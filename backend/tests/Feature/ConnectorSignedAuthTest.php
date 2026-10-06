<?php

namespace Tests\Feature;

use App\Contracts\ConnectorCredentialResolver;
use App\Data\ConnectorCredential;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Services\ConnectorHmacVerifier;
use App\Services\ConnectorRequestCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectorSignedAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $credentialId;

    private string $scopeId;

    private string $secret = 'test-only-connector-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentialId = (string) Str::ulid();
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $this->scopeId = SiteConnection::factory()->create(['site_id' => $site->id])->id;

        Route::middleware('connector.signed')->get('/api/v1/_test/connector/signed', fn () => response()->json(['reached' => true]));
        Route::middleware('connector.signed')->get('/api/v1/_test/connector/other', fn () => response()->json(['reached' => true]));
        Route::middleware('connector.signed')->post('/api/v1/_test/connector/signed', fn () => response()->json(['reached' => true]));

        $credential = new ConnectorCredential($this->credentialId, $this->scopeId, $this->secret);
        app()->instance(ConnectorCredentialResolver::class, new class($credential) implements ConnectorCredentialResolver
        {
            public function __construct(private ConnectorCredential $credential) {}

            public function resolve(string $credentialId): ?ConnectorCredential
            {
                return hash_equals(strtolower($this->credential->credentialId), strtolower($credentialId))
                    ? $this->credential
                    : null;
            }
        });
    }

    public function test_valid_signed_get_reaches_endpoint_and_uses_nonce_scope(): void
    {
        $nonce = $this->nonce();
        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed?ignored=1', '', $nonce);

        $this->get('/api/v1/_test/connector/signed?ignored=1', $headers)->assertOk()->assertJson(['reached' => true]);
        $this->assertDatabaseHas('connector_request_nonces', [
            'site_connection_id' => $this->scopeId,
            'nonce_hash' => hash('sha256', $nonce),
        ]);
    }

    public function test_valid_signed_post_reaches_endpoint_with_exact_body(): void
    {
        $nonce = $this->nonce();
        $body = "{\"a\":1, \"b\":2}\n";
        $headers = $this->signedHeaders('POST', '/api/v1/_test/connector/signed', $body, $nonce);

        $this->call('POST', '/api/v1/_test/connector/signed', [], [], [], $this->transformHeadersToServerVars($headers), $body)
            ->assertOk()->assertJson(['reached' => true]);
    }

    public function test_authentication_failures_have_stable_codes_and_do_not_reach_endpoint(): void
    {
        $this->get('/api/v1/_test/connector/signed')->assertUnauthorized()->assertJsonPath('error.code', 'connector_unauthorized');

        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce());
        unset($headers['X-SP-Signature']);
        $this->get('/api/v1/_test/connector/signed', $headers)->assertUnauthorized()->assertJsonPath('error.code', 'connector_signature_invalid');

        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce());
        $headers['X-SP-Signature'] = 'invalid';
        $this->get('/api/v1/_test/connector/signed', $headers)->assertUnauthorized()->assertJsonPath('error.code', 'connector_signature_invalid');

        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce());
        $headers['X-SP-Signature'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->get('/api/v1/_test/connector/signed', $headers)->assertUnauthorized()->assertJsonPath('error.code', 'connector_signature_invalid');
    }

    public function test_method_path_and_body_changes_invalidate_signature(): void
    {
        $nonce = $this->nonce();
        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $nonce);
        $this->call('POST', '/api/v1/_test/connector/signed', [], [], [], $this->transformHeadersToServerVars($headers), '')->assertUnauthorized()->assertJsonPath('error.code', 'connector_signature_invalid');

        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce());
        $this->get('/api/v1/_test/connector/other', $headers)->assertUnauthorized()->assertJsonPath('error.code', 'connector_signature_invalid');

        $nonce = $this->nonce();
        $headers = $this->signedHeaders('POST', '/api/v1/_test/connector/signed', '{"a":1}', $nonce);
        $this->call('POST', '/api/v1/_test/connector/signed', [], [], [], $this->transformHeadersToServerVars($headers), '{"a":2}')
            ->assertUnauthorized()->assertJsonPath('error.code', 'connector_signature_invalid');
    }

    public function test_timestamp_expiry_future_and_inclusive_boundary(): void
    {
        $now = now()->timestamp;
        $this->get('/api/v1/_test/connector/signed', $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce(), (string) ($now - 301)))
            ->assertUnauthorized()->assertJsonPath('error.code', 'connector_timestamp_expired');
        $this->get('/api/v1/_test/connector/signed', $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce(), (string) ($now + 301)))
            ->assertUnauthorized()->assertJsonPath('error.code', 'connector_timestamp_expired');
        $this->get('/api/v1/_test/connector/signed', $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce(), (string) ($now - 300)))
            ->assertOk();
        $this->get('/api/v1/_test/connector/signed', $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce(), (string) ($now + 300)))
            ->assertOk();
    }

    public function test_duplicate_nonce_is_rejected_but_same_nonce_in_another_connection_scope_is_allowed(): void
    {
        $nonce = $this->nonce();
        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $nonce);
        $this->get('/api/v1/_test/connector/signed', $headers)->assertOk();
        $this->get('/api/v1/_test/connector/signed', $headers)->assertStatus(409)->assertJsonPath('error.code', 'connector_request_replayed');

        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $otherCredential = new ConnectorCredential((string) Str::ulid(), SiteConnection::factory()->create(['site_id' => $site->id])->id, $this->secret);
        app()->instance(ConnectorCredentialResolver::class, new class($otherCredential) implements ConnectorCredentialResolver
        {
            public function __construct(private ConnectorCredential $credential) {}

            public function resolve(string $credentialId): ?ConnectorCredential
            {
                return hash_equals(strtolower($this->credential->credentialId), strtolower($credentialId)) ? $this->credential : null;
            }
        });
        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $nonce, null, $otherCredential->credentialId);
        $this->get('/api/v1/_test/connector/signed', $headers)->assertOk();
    }

    public function test_expired_nonce_row_is_replaced_and_nonce_is_stored_as_hash(): void
    {
        $nonce = $this->nonce();
        DB::table('connector_request_nonces')->insert([
            'site_connection_id' => $this->scopeId,
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => now()->subSecond(),
            'created_at' => now()->subMinutes(11),
        ]);
        $this->get('/api/v1/_test/connector/signed', $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $nonce))->assertOk();
        $this->assertDatabaseHas('connector_request_nonces', ['nonce_hash' => hash('sha256', $nonce)]);
        $this->assertDatabaseMissing('connector_request_nonces', ['nonce_hash' => $nonce]);
    }

    public function test_wrong_credential_is_indistinguishable_from_unknown_credential(): void
    {
        $headers = $this->signedHeaders('GET', '/api/v1/_test/connector/signed', '', $this->nonce(), null, (string) Str::ulid());
        $this->get('/api/v1/_test/connector/signed', $headers)->assertUnauthorized()->assertJsonPath('error.code', 'connector_unauthorized');
    }

    private function signedHeaders(string $method, string $url, string $body, string $nonce, ?string $timestamp = null, ?string $credentialId = null): array
    {
        $credentialId ??= $this->credentialId;
        $timestamp ??= (string) now()->timestamp;
        $request = Request::create($url, $method, [], [], [], [], $body);
        $canonical = app(ConnectorRequestCanonicalizer::class)->canonicalize($request, $timestamp, $nonce);
        $signature = app(ConnectorHmacVerifier::class)->sign($this->secret, $canonical);

        return [
            'Authorization' => 'SP-HMAC '.$credentialId,
            'X-SP-Credential-Id' => $credentialId,
            'X-SP-Timestamp' => $timestamp,
            'X-SP-Nonce' => $nonce,
            'X-SP-Signature' => $signature,
        ];
    }

    private function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
