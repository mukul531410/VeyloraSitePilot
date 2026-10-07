<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ConnectorCapability;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Services\ConnectorCredentialLifecycle;
use App\Services\ConnectorHmacVerifier;
use App\Services\ConnectorRequestCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectorCapabilityReportTest extends TestCase
{
    use RefreshDatabase;

    private SiteConnection $connection;

    private string $credentialId;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $this->connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $issued = app(ConnectorCredentialLifecycle::class)->issueInitial($this->connection);
        $this->credentialId = $issued['credential']->id;
        $this->secret = base64_decode($issued['secret'], true);
    }

    public function test_signed_report_persists_support_version_and_time_without_granting_it(): void
    {
        $reportedAt = '2026-10-06T12:00:00Z';
        $this->report($this->payload([['key' => 'read.future_feature', 'supported' => true]], $reportedAt))
            ->assertOk()
            ->assertJsonPath('data.connector_version', '1.4.0');

        $capability = ConnectorCapability::query()->firstOrFail();
        $this->assertSame('read.future_feature', $capability->capability_key);
        $this->assertTrue($capability->reported_supported);
        $this->assertFalse($capability->enabled);
        $this->assertFalse($capability->isEffective());
        $this->assertSame($reportedAt, $capability->reported_at->toIso8601ZuluString());
        $this->assertSame('1.4.0', $this->connection->fresh()->connector_version);
    }

    public function test_existing_sitepilot_grant_is_preserved_but_omission_blocks_effective_capability(): void
    {
        $capability = ConnectorCapability::query()->create([
            'site_connection_id' => $this->connection->id,
            'capability_key' => 'read.plugins',
            'enabled' => true,
            'discovered_at' => now(),
            'reported_supported' => true,
            'reported_at' => now()->subMinute(),
        ]);
        $this->assertTrue($capability->isEffective());

        $this->report($this->payload([['key' => 'read.wordpress', 'supported' => true]]))->assertOk();

        $capability->refresh();
        $this->assertTrue($capability->enabled);
        $this->assertFalse($capability->reported_supported);
        $this->assertFalse($capability->isEffective());
    }

    public function test_effective_capability_requires_both_grant_and_reported_support(): void
    {
        $capability = ConnectorCapability::query()->create([
            'site_connection_id' => $this->connection->id,
            'capability_key' => 'read.plugins',
            'enabled' => false,
            'reported_supported' => true,
            'reported_at' => now(),
            'discovered_at' => now(),
        ]);
        $this->assertFalse($capability->isEffective());

        $capability->update(['enabled' => true, 'reported_supported' => false]);
        $this->assertFalse($capability->isEffective());

        $capability->update(['reported_supported' => null]);
        $this->assertFalse($capability->isEffective());

        $capability->update(['reported_supported' => true]);
        $this->assertTrue($capability->isEffective());
    }

    public function test_identical_report_is_idempotent_and_does_not_duplicate_material_audits(): void
    {
        $payload = $this->payload([
            ['key' => 'read.wordpress', 'supported' => true],
            ['key' => 'read.plugins', 'supported' => false],
        ]);
        $this->report($payload)->assertOk();
        $auditCount = AuditLog::query()->where('action', 'connector_capability_report_changed')->count();
        $this->report($payload)->assertOk();

        $this->assertSame(2, ConnectorCapability::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->where('action', 'connector_capability_report_changed')->count());
    }

    public function test_same_nonce_is_rejected_before_a_second_report_is_applied(): void
    {
        $payload = $this->payload([['key' => 'read.wordpress', 'supported' => true]]);
        $nonce = $this->nonce();
        $this->report($payload, $nonce)->assertOk();
        $this->report($payload, $nonce)->assertStatus(409)->assertJsonPath('error.code', 'connector_request_replayed');
        $this->assertSame(1, ConnectorCapability::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'connector_capability_report_changed')->count());
    }

    public function test_rejects_bad_report_shapes_timestamps_keys_and_duplicates(): void
    {
        $valid = $this->payload([['key' => 'read.plugins', 'supported' => true]]);
        $invalidReports = [
            'connector_version' => [array_diff_key($valid, ['connector_version' => true]), 'connector_version'],
            'reported_at' => [array_diff_key($valid, ['reported_at' => true]), 'reported_at'],
            'capabilities' => [array_diff_key($valid, ['capabilities' => true]), 'capabilities'],
            'empty capabilities' => [$this->payload([]), 'capabilities'],
            'malformed timestamp' => [$this->payload($valid['capabilities'], '2026-10-06 12:00:00'), 'reported_at'],
            'non-UTC timestamp' => [$this->payload($valid['capabilities'], '2026-10-06T12:00:00+06:00'), 'reported_at'],
            'malformed key' => [$this->payload([['key' => 'Read.plugins', 'supported' => true]]), 'capabilities.0.key'],
            'duplicate keys' => [$this->payload([
                ['key' => 'read.plugins', 'supported' => true],
                ['key' => 'read.plugins', 'supported' => false],
            ]), 'capabilities.1.key'],
            'non-boolean support' => [$this->payload([['key' => 'read.plugins', 'supported' => 'yes']]), 'capabilities.0.supported'],
        ];

        foreach ($invalidReports as $label => [$payload, $errorKey]) {
            $this->report($payload)->assertUnprocessable()->assertJsonValidationErrors($errorKey);
        }
        $this->assertSame(0, ConnectorCapability::query()->count());
    }

    public function test_invalid_signature_and_revoked_credential_are_rejected(): void
    {
        $payload = $this->payload([['key' => 'read.wordpress', 'supported' => true]]);
        $this->report($payload, null, false, (string) Str::ulid())
            ->assertUnauthorized()->assertJsonPath('error.code', 'connector_unauthorized');
        $this->report($payload, null, true)->assertUnauthorized();

        app(ConnectorCredentialLifecycle::class)->revoke($this->credentialId);
        $this->report($payload)->assertUnauthorized()->assertJsonPath('error.code', 'connector_unauthorized');
        $this->assertSame(0, ConnectorCapability::query()->count());
    }

    public function test_existing_bearer_capability_read_remains_available_and_distinguishes_grant_support_and_effective(): void
    {
        $token = 'legacy-bearer-token';
        $this->connection->update(['connector_token_hash' => hash('sha256', $token)]);
        ConnectorCapability::query()->create([
            'site_connection_id' => $this->connection->id,
            'capability_key' => 'read.plugins',
            'enabled' => true,
            'reported_supported' => false,
            'reported_at' => now(),
            'discovered_at' => now(),
        ]);

        $this->getJson('/api/v1/connector/capabilities', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.0.reported_supported', false)
            ->assertJsonPath('data.0.effective', false);
    }

    public function test_report_is_bound_to_the_credential_connection(): void
    {
        $otherSite = Site::factory()->create(['organization_id' => $this->connection->site->organization_id]);
        $otherConnection = SiteConnection::factory()->create(['site_id' => $otherSite->id, 'status' => 'active']);
        $this->report($this->payload([['key' => 'read.plugins', 'supported' => true]]))->assertOk();

        $this->assertDatabaseHas('connector_capabilities', [
            'site_connection_id' => $this->connection->id,
            'capability_key' => 'read.plugins',
        ]);
        $this->assertDatabaseMissing('connector_capabilities', [
            'site_connection_id' => $otherConnection->id,
            'capability_key' => 'read.plugins',
        ]);
    }

    private function payload(array $capabilities, string $reportedAt = '2026-10-06T12:00:00Z'): array
    {
        return [
            'connector_version' => '1.4.0',
            'reported_at' => $reportedAt,
            'capabilities' => $capabilities,
        ];
    }

    private function report(array $payload, ?string $nonce = null, bool $invalidSignature = false, ?string $credentialIdOverride = null)
    {
        $nonce ??= $this->nonce();
        $credentialId = $credentialIdOverride ?? $this->credentialId;
        $url = '/api/v1/connector/capabilities/report';
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $request = Request::create($url, 'POST', [], [], [], [], $body);
        $canonical = app(ConnectorRequestCanonicalizer::class)->canonicalize($request, $timestamp, $nonce);
        $signature = app(ConnectorHmacVerifier::class)->sign($this->secret, $canonical);
        if ($invalidSignature) {
            $signature = str_repeat('A', 43);
        }

        $headers = [
            'Authorization' => 'SP-HMAC '.$credentialId,
            'X-SP-Credential-Id' => $credentialId,
            'X-SP-Timestamp' => $timestamp,
            'X-SP-Nonce' => $nonce,
            'X-SP-Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        return $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    private function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
