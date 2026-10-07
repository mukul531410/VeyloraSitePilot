<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Veylora\SitePilotConnector\Api\CapabilityReporter;
use Veylora\SitePilotConnector\Api\HeartbeatClient;
use Veylora\SitePilotConnector\Api\InventoryClient;
use Veylora\SitePilotConnector\Http\SitePilotHttpClient;
use Veylora\SitePilotConnector\Security\CredentialStore;
use Veylora\SitePilotConnector\Security\NonceGenerator;
use Veylora\SitePilotConnector\Security\SignatureBuilder;
use Veylora\SitePilotConnector\Site\ConfigStore;
use Veylora\SitePilotConnector\Site\SiteIdentity;
use Veylora\SitePilotConnector\Support\ConnectorException;

final class ConnectorFoundationTest extends TestCase
{
    private ConfigStore $config;

    private CredentialStore $credentials;

    protected function setUp(): void
    {
        $GLOBALS['sitepilot_test_options'] = [];
        $GLOBALS['sitepilot_test_plugins'] = [];
        $GLOBALS['sitepilot_test_themes'] = [];
        $GLOBALS['sitepilot_test_transients'] = [];
        $GLOBALS['sitepilot_test_transport'] = null;
        $this->config = new ConfigStore();
        $this->config->setBaseUrl('https://sitepilot.example');
        $this->credentials = new CredentialStore();
    }

    public function test_canonical_request_includes_backend_version_line_and_body_sha256(): void
    {
        $body = '{"hello":"world"}';
        $canonical = (new SignatureBuilder())->canonicalInput(
            'post', '/api/v1/connector/capabilities/report', '1780000000', '0123456789012345678901', $body,
        );

        self::assertSame(implode("\n", [
            'SP-HMAC-SHA256', 'POST', '/api/v1/connector/capabilities/report', '1780000000',
            '0123456789012345678901', hash('sha256', $body),
        ]), $canonical);
    }

    public function test_signature_is_hmac_sha256_encoded_as_unpadded_base64url(): void
    {
        $secret = str_repeat("\x11", 32);
        $encodedSecret = base64_encode($secret);
        $canonical = "SP-HMAC-SHA256\nPOST\n/api/v1/connector/capabilities/report\n1780000000\nnonce\n" . hash('sha256', '{}');
        $headers = (new SignatureBuilder())->headers(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV', $encodedSecret, 'POST',
            '/api/v1/connector/capabilities/report', '1780000000', 'nonce', '{}',
        );
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');

        self::assertSame($expected, $headers['X-SP-Signature']);
        self::assertSame(43, strlen($headers['X-SP-Signature']));
    }

    public function test_signed_request_has_all_backend_headers_and_signs_exact_body(): void
    {
        $this->saveCredentials();
        $captured = null;
        $client = $this->client(function (string $url, array $args) use (&$captured): array {
            $captured = [$url, $args];
            return $this->response(['accepted' => true]);
        });
        $client->request('POST', '/api/v1/connector/capabilities/report', ['capabilities' => []], 'signed');

        [$url, $args] = $captured;
        self::assertSame('https://sitepilot.example/api/v1/connector/capabilities/report', $url);
        self::assertSame('SP-HMAC 01ARZ3NDEKTSV4RRFFQ69G5FAV', $args['headers']['Authorization']);
        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $args['headers']['X-SP-Credential-Id']);
        self::assertMatchesRegularExpression('/^[0-9]+$/', $args['headers']['X-SP-Timestamp']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $args['headers']['X-SP-Nonce']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $args['headers']['X-SP-Signature']);
        self::assertSame(hash('sha256', $args['body']), hash('sha256', wp_json_encode(['capabilities' => []], JSON_UNESCAPED_SLASHES)));
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['sslverify']);
    }

    public function test_nonce_is_cryptographically_random_base64url_and_unique(): void
    {
        $generator = new NonceGenerator();
        $nonces = [];
        for ($i = 0; $i < 100; $i++) {
            $nonce = $generator->generate();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $nonce);
            self::assertSame(16, strlen(base64_decode(strtr($nonce, '-_', '+/') . '==', true)));
            $nonces[] = $nonce;
        }
        self::assertCount(100, array_unique($nonces));
    }

    public function test_site_identity_uses_utc_rfc3339_timestamp_and_connector_version(): void
    {
        $identity = new SiteIdentity();
        self::assertSame('0.1.0', $identity->connectorVersion());
        self::assertSame('6.8.1', $identity->wordpressVersion());
        self::assertSame(PHP_VERSION, $identity->phpVersion());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $identity->utcTimestamp());
    }

    public function test_capability_report_claims_only_supported_read_features_and_never_grants_them(): void
    {
        $this->saveCredentials();
        $captured = null;
        $reporter = new CapabilityReporter($this->client(function (string $url, array $args) use (&$captured): array {
            $captured = [$url, $args];
            return $this->response(['connection_id' => 'connection']);
        }), new SiteIdentity());
        $reporter->report();
        $payload = json_decode($captured[1]['body'], true);

        self::assertSame('/api/v1/connector/capabilities/report', parse_url($captured[0], PHP_URL_PATH));
        self::assertSame(['read.wordpress', 'read.plugins', 'read.themes'], array_column($payload['capabilities'], 'key'));
        self::assertSame([true, true, true], array_column($payload['capabilities'], 'supported'));
        self::assertArrayNotHasKey('enabled', $payload);
        self::assertStringNotContainsString('action.cache_clear', $captured[1]['body']);
        self::assertStringNotContainsString('read.cache_state', $captured[1]['body']);
        self::assertSame('signed', $captured[1]['headers']['Authorization'] === 'SP-HMAC 01ARZ3NDEKTSV4RRFFQ69G5FAV' ? 'signed' : 'other');
    }

    public function test_heartbeat_payload_matches_current_backend_contract(): void
    {
        $this->saveCredentials();
        $captured = null;
        (new HeartbeatClient($this->client(function (string $url, array $args) use (&$captured): array {
            $captured = [$url, $args];
            return $this->response(['status' => 'active']);
        }), new SiteIdentity()))->send();
        $payload = json_decode($captured[1]['body'], true);

        self::assertSame('/api/v1/connector/heartbeat', parse_url($captured[0], PHP_URL_PATH));
        self::assertSame(['connector_version', 'wordpress_version', 'php_version', 'status'], array_keys($payload));
        self::assertSame('online', $payload['status']);
        self::assertSame('Bearer bearer-token-test-only', $captured[1]['headers']['Authorization']);
    }

    public function test_inventory_matches_contract_and_only_includes_effectively_granted_categories(): void
    {
        $this->saveCredentials();
        $requests = [];
        $GLOBALS['sitepilot_test_plugins'] = [
            'hello/hello.php' => ['Name' => 'Hello', 'Version' => '1.7'],
            'paused/paused.php' => ['Name' => 'Paused', 'Version' => '2.0'],
        ];
        $GLOBALS['sitepilot_test_themes'] = [
            'sample' => new class {
                public function get(string $key): string { return $key === 'Name' ? 'Sample' : '1.0'; }
            },
        ];
        $GLOBALS['sitepilot_test_transients'] = [
            'update_plugins' => (object) [
                'response' => ['paused/paused.php' => (object) []],
                'no_update' => ['hello/hello.php' => (object) []],
            ],
            'update_themes' => (object) ['response' => [], 'no_update' => ['sample' => (object) []]],
        ];
        $client = $this->client(function (string $url, array $args) use (&$requests): array {
            $requests[] = [$url, $args];
            if (str_ends_with($url, '/connector/capabilities')) {
                return ['response' => ['code' => 200], 'body' => json_encode(['data' => [
                    ['capability_key' => 'read.wordpress', 'effective' => true],
                    ['capability_key' => 'read.plugins', 'effective' => true],
                    ['capability_key' => 'read.themes', 'effective' => false],
                ]], JSON_THROW_ON_ERROR)];
            }
            return $this->response(['inventory_snapshot_id' => 'snapshot']);
        });

        $payload = (new InventoryClient($client, new SiteIdentity()))->collect();
        self::assertSame(['started_at', 'category_completeness', 'wordpress', 'plugins', 'completed_at'], array_keys($payload));
        self::assertSame(['wordpress' => true, 'plugins' => true], $payload['category_completeness']);
        self::assertSame('6.8.1', $payload['wordpress']['version']);
        self::assertSame('active', $payload['wordpress']['status']);
        self::assertCount(2, $payload['plugins']);
        self::assertSame('inactive', $payload['plugins'][1]['status']);
        self::assertTrue($payload['plugins'][1]['update_available']);
        self::assertFalse($payload['plugins'][0]['update_available']);
        self::assertArrayNotHasKey('themes', $payload);
        self::assertArrayNotHasKey('site', $payload);
        self::assertArrayNotHasKey('enabled', $payload);
        self::assertSame('Bearer bearer-token-test-only', $requests[0][1]['headers']['Authorization']);
    }

    public function test_inventory_fails_closed_when_core_capability_is_not_effective(): void
    {
        $this->saveCredentials();
        $client = $this->client(fn (): array => [
            'response' => ['code' => 200],
            'body' => json_encode(['data' => [
                ['capability_key' => 'read.wordpress', 'effective' => false],
            ]], JSON_THROW_ON_ERROR),
        ]);

        try {
            (new InventoryClient($client, new SiteIdentity()))->collect();
            self::fail('Expected missing core capability to reject inventory.');
        } catch (ConnectorException $exception) {
            self::assertSame('capability_unavailable', $exception->errorCode);
        }
    }

    public function test_inventory_omits_update_available_when_wordpress_has_no_update_observation(): void
    {
        $this->saveCredentials();
        $GLOBALS['sitepilot_test_plugins'] = ['unknown/unknown.php' => ['Name' => 'Unknown', 'Version' => '1.0']];
        $GLOBALS['sitepilot_test_transients'] = ['update_plugins' => false];
        $client = $this->client(fn (): array => [
            'response' => ['code' => 200],
            'body' => json_encode(['data' => [
                ['capability_key' => 'read.wordpress', 'effective' => true],
                ['capability_key' => 'read.plugins', 'effective' => true],
            ]], JSON_THROW_ON_ERROR),
        ]);

        $inventory = (new InventoryClient($client, new SiteIdentity()))->collect();
        self::assertArrayNotHasKey('update_available', $inventory['plugins'][0]);
    }

    public function test_credentials_are_encrypted_in_options_and_can_be_read_back(): void
    {
        $secrets = [
            'token' => 'bearer-secret-fixture',
            'credential_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'credential_secret' => base64_encode(str_repeat("\x22", 32)),
        ];
        $this->credentials->save($secrets);
        $stored = $GLOBALS['sitepilot_test_options']['sitepilot_connector_credentials'];

        self::assertIsString($stored);
        self::assertStringNotContainsString($secrets['token'], $stored);
        self::assertStringNotContainsString($secrets['credential_secret'], $stored);
        self::assertSame($secrets, $this->credentials->get());
    }

    public function test_http_errors_are_generic_and_do_not_include_response_or_transport_secrets(): void
    {
        $this->saveCredentials();
        $secret = 'do-not-leak-secret-fixture';
        $client = $this->client(fn (): WP_Error => new WP_Error('transport', $secret));

        try {
            $client->request('POST', '/api/v1/connector/heartbeat', [], 'bearer');
            self::fail('Expected HTTP transport failure.');
        } catch (ConnectorException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertSame('request_failed', $exception->errorCode);
        }

        $client = $this->client(fn (): array => ['response' => ['code' => 500], 'body' => $secret]);
        try {
            $client->request('POST', '/api/v1/connector/heartbeat', [], 'bearer');
            self::fail('Expected non-success response.');
        } catch (ConnectorException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function test_missing_or_invalid_credentials_fail_closed_before_transport(): void
    {
        $called = false;
        $client = $this->client(function () use (&$called): array {
            $called = true;
            return $this->response([]);
        });
        try {
            $client->request('POST', '/api/v1/connector/heartbeat', [], 'bearer');
            self::fail('Expected missing credentials to reject request.');
        } catch (ConnectorException $exception) {
            self::assertSame('missing_credentials', $exception->errorCode);
        }
        self::assertFalse($called);

        $this->credentials->save([
            'token' => 'bearer-token-test-only',
            'credential_id' => 'invalid',
            'credential_secret' => 'bad-secret',
        ]);
        try {
            $client->request('POST', '/api/v1/connector/capabilities/report', [], 'signed');
            self::fail('Expected malformed HMAC credentials to reject request.');
        } catch (ConnectorException $exception) {
            self::assertSame('invalid_credentials', $exception->errorCode);
        }
        self::assertFalse($called);
    }

    public function test_production_http_urls_and_urls_with_credentials_or_paths_are_rejected(): void
    {
        foreach (['http://sitepilot.example', 'https://user:pass@sitepilot.example', 'https://sitepilot.example/api/v1'] as $url) {
            try {
                ConfigStore::validatedBaseUrl($url);
                self::fail('Expected unsafe base URL to be rejected.');
            } catch (ConnectorException $exception) {
                self::assertSame('invalid_configuration', $exception->errorCode);
            }
        }
        self::assertSame('http://localhost:8000', ConfigStore::validatedBaseUrl('http://localhost:8000/'));
    }

    private function saveCredentials(): void
    {
        $this->credentials->save([
            'token' => 'bearer-token-test-only',
            'credential_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'credential_secret' => base64_encode(str_repeat("\x11", 32)),
        ]);
    }

    private function client(callable $transport): SitePilotHttpClient
    {
        return new SitePilotHttpClient(
            $this->config,
            $this->credentials,
            new SignatureBuilder(),
            new NonceGenerator(),
            $transport,
        );
    }

    private function response(array $data): array
    {
        return ['response' => ['code' => 200], 'body' => json_encode(['data' => $data], JSON_THROW_ON_ERROR)];
    }
}
