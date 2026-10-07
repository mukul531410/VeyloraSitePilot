<?php

namespace Tests\Feature;

use App\Exceptions\ConnectorCredentialException;
use App\Models\ConnectorCredential;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Services\ConnectorCredentialLifecycle;
use App\Services\DatabaseConnectorCredentialResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectorCredentialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_secret_is_encrypted_and_resolves_only_for_active_connection(): void
    {
        $connection = $this->connection();
        $issued = app(ConnectorCredentialLifecycle::class)->issueInitial($connection);

        self::assertSame(32, strlen(base64_decode($issued['secret'], true)));
        self::assertSame(base64_decode($issued['secret']), Crypt::decryptString($issued['credential']->secret_ciphertext));
        self::assertStringNotContainsString($issued['secret'], $issued['credential']->secret_ciphertext);
        $resolved = app(DatabaseConnectorCredentialResolver::class)->resolve($issued['credential']->id);
        self::assertSame($issued['credential']->id, $resolved?->nonceScopeId);
        self::assertSame($issued['credential']->id, $resolved?->credentialId);

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
        self::assertNull(app(DatabaseConnectorCredentialResolver::class)->resolve($issued['credential']->id));
    }

    public function test_initial_credential_cannot_be_issued_for_a_pending_connection(): void
    {
        $connection = $this->connection();
        $connection->update(['status' => 'pending']);

        try {
            app(ConnectorCredentialLifecycle::class)->issueInitial($connection);
            self::fail('A pending connection cannot receive an initial credential.');
        } catch (ConnectorCredentialException $exception) {
            self::assertSame(ConnectorCredentialException::UNAUTHORIZED, $exception->reason);
        }

        $this->assertDatabaseCount('connector_credentials', 0);
    }

    public function test_rotation_creates_one_primary_and_exact_24_hour_overlap_then_expires_old_credential(): void
    {
        $connection = $this->connection();
        $lifecycle = app(ConnectorCredentialLifecycle::class);
        $first = $lifecycle->issueInitial($connection);
        $rotated = $lifecycle->rotate($first['credential']->id, 1);

        self::assertNotSame($first['secret'], $rotated['secret']);
        self::assertSame(2, $rotated['credential']->version);
        self::assertSame(1, ConnectorCredential::query()->where('site_connection_id', $connection->id)->where('status', 'primary')->count());
        self::assertSame(ConnectorCredential::STATUS_OVERLAP, $first['credential']->fresh()->status);
        self::assertSame(86400, $first['credential']->fresh()->overlap_expires_at->timestamp - $rotated['credential']->issued_at->timestamp);
        self::assertNotNull(app(DatabaseConnectorCredentialResolver::class)->resolve($first['credential']->id));
        try {
            $lifecycle->rotate($first['credential']->id, 2);
            self::fail('An overlap credential cannot rotate the current primary.');
        } catch (ConnectorCredentialException $exception) {
            self::assertSame(ConnectorCredentialException::UNAUTHORIZED, $exception->reason);
        }

        $this->travel(24)->hours();
        self::assertNull(app(DatabaseConnectorCredentialResolver::class)->resolve($first['credential']->id));
        self::assertNotNull(app(DatabaseConnectorCredentialResolver::class)->resolve($rotated['credential']->id));
    }

    public function test_stale_rotation_conflicts_and_revocation_keeps_history(): void
    {
        $connection = $this->connection();
        $lifecycle = app(ConnectorCredentialLifecycle::class);
        $issued = $lifecycle->issueInitial($connection);
        $lifecycle->rotate($issued['credential']->id, 1);

        try {
            $lifecycle->rotate($issued['credential']->id, 1);
            self::fail('A stale credential version must conflict.');
        } catch (ConnectorCredentialException $exception) {
            self::assertSame(ConnectorCredentialException::VERSION_CONFLICT, $exception->reason);
        }

        $lifecycle->revoke($issued['credential']->id);
        self::assertNull(app(DatabaseConnectorCredentialResolver::class)->resolve($issued['credential']->id));
        self::assertDatabaseHas('connector_credentials', ['id' => $issued['credential']->id, 'status' => 'revoked']);
        self::assertDatabaseHas('audit_logs', ['action' => 'connector_credential_rotated']);
        self::assertDatabaseHas('audit_logs', ['action' => 'connector_credential_revoked']);
    }

    public function test_resolver_rejects_unknown_and_expired_overlap_credentials(): void
    {
        self::assertNull(app(DatabaseConnectorCredentialResolver::class)->resolve((string) Str::ulid()));
        $issued = app(ConnectorCredentialLifecycle::class)->issueInitial($this->connection());
        $issued['credential']->update(['status' => 'overlap', 'overlap_expires_at' => now()]);
        self::assertNull(app(DatabaseConnectorCredentialResolver::class)->resolve($issued['credential']->id));
    }

    public function test_database_enforces_connection_version_uniqueness_and_cascading_foreign_key(): void
    {
        $connection = $this->connection();
        $issued = app(ConnectorCredentialLifecycle::class)->issueInitial($connection);

        try {
            $connection->connectorCredentials()->create([
                'secret_ciphertext' => Crypt::encryptString(random_bytes(32)),
                'version' => 1,
                'status' => ConnectorCredential::STATUS_PRIMARY,
                'issued_at' => now(),
            ]);
            self::fail('A connection cannot have two credentials with the same version.');
        } catch (QueryException) {
            self::assertDatabaseCount('connector_credentials', 1);
        }

        $connection->delete();
        $this->assertDatabaseMissing('connector_credentials', ['id' => $issued['credential']->id]);
    }

    private function connection(): SiteConnection
    {
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        return SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    }
}
