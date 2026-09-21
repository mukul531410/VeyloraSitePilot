<?php

namespace Tests\Feature;

use App\Models\ConnectorCapability;
use App\Models\ConnectorHeartbeat;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $org->id]);

        return [$user, $org, $site];
    }

    public function test_authenticated_user_can_create_connection_for_own_site(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/connections');

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'site_id', 'status', 'connection_intent', 'intent_expires_at'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'status' => 'pending',
                ],
            ]);

        $this->assertNotNull($response->json('data.connection_intent'));
        $this->assertDatabaseHas('site_connections', [
            'site_id' => $site->id,
            'status' => 'pending',
        ]);
    }

    public function test_unauthenticated_user_receives_401(): void
    {
        $org = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $org->id]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/connections');

        $response->assertStatus(401);
    }

    public function test_unauthorized_organization_member_receives_403(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();

        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/connections');

        $response->assertStatus(403);
    }

    public function test_user_cannot_access_another_organization_site_connections(): void
    {
        $otherUser = User::factory()->create();
        $otherOrg = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role_id' => Role::factory()->create(['organization_id' => $otherOrg->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $otherOrg->id]);

        $user = User::factory()->create();
        $userOrg = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $userOrg->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $userOrg->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/connections');

        $response->assertStatus(403);
    }

    public function test_authenticated_user_can_view_connection_metadata(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'pending']);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/connections/' . $connection->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'site_id', 'status', 'connected_at', 'last_seen_at', 'revoked_at'],
                'meta' => ['capabilities', 'latest_heartbeat'],
                'request_id',
            ]);
    }

    public function test_connection_metadata_does_not_expose_secrets(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'credential_ciphertext' => 'encrypted-credential-data',
            'connector_token_hash' => 'hashed-token-data',
            'connection_intent' => 'some-intent-code',
        ]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/connections/' . $connection->id);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('credential_ciphertext', $response->json('data'));
        $this->assertArrayNotHasKey('connector_token_hash', $response->json('data'));
        $this->assertArrayNotHasKey('connection_intent', $response->json('data'));
        $response->assertJsonMissing([
            'credential_ciphertext' => 'encrypted-credential-data',
            'connector_token_hash' => 'hashed-token-data',
            'connection_intent' => 'some-intent-code',
        ]);
    }

    public function test_user_can_revoke_connection(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        $response = $this->deleteJson('/api/v1/sites/' . $site->id . '/connections/' . $connection->id);

        $response->assertStatus(200);

        $this->assertDatabaseHas('site_connections', [
            'id' => $connection->id,
            'status' => 'revoked',
        ]);
        $this->assertNull($connection->fresh()->credential_ciphertext);
    }

    public function test_cannot_create_duplicate_active_connection(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/connections');

        $response->assertStatus(409);
    }

    public function test_nonexistent_site_returns_404(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites/01h-nonexistent-site/connections');

        $response->assertStatus(404);
    }

    public function test_connector_register_with_valid_intent(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/connections');
        $intent = $createResponse->json('data.connection_intent');

        $response = $this->postJson('/api/v1/connector/register', [
            'intent' => $intent,
            'connector_version' => '1.0.0',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['connection_id', 'status', 'token'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'status' => 'active',
                ],
            ]);

        $this->assertNotNull($response->json('data.token'));
        $this->assertDatabaseHas('site_connections', [
            'connection_intent' => null,
        ]);
    }

    public function test_connector_register_with_invalid_intent_returns_401(): void
    {
        $response = $this->postJson('/api/v1/connector/register', [
            'intent' => 'invalid-intent-code',
            'connector_version' => '1.0.0',
        ]);

        $response->assertStatus(401);
    }

    public function test_intent_cannot_be_reused_after_successful_registration(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/connections');
        $intent = $createResponse->json('data.connection_intent');

        $this->postJson('/api/v1/connector/register', [
            'intent' => $intent,
            'connector_version' => '1.0.0',
        ])->assertStatus(201);

        $response = $this->postJson('/api/v1/connector/register', [
            'intent' => $intent,
            'connector_version' => '1.0.0',
        ]);

        $response->assertStatus(401);
    }

    public function test_connector_heartbeat_requires_valid_token(): void
    {
        $response = $this->postJson('/api/v1/connector/heartbeat', [
            'connector_version' => '1.0.0',
            'wordpress_version' => '6.5',
            'php_version' => '8.2',
            'status' => 'online',
        ]);

        $response->assertStatus(401);
    }

    public function test_connector_can_send_heartbeat_with_valid_token(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/connections');
        $intent = $createResponse->json('data.connection_intent');

        $registerResponse = $this->postJson('/api/v1/connector/register', [
            'intent' => $intent,
            'connector_version' => '1.0.0',
        ]);
        $token = $registerResponse->json('data.token');

        $response = $this->postJson('/api/v1/connector/heartbeat', [
            'connector_version' => '1.0.1',
            'wordpress_version' => '6.5',
            'php_version' => '8.2',
            'status' => 'online',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['connection_id', 'status'],
                'meta',
                'request_id',
            ]);

        $this->assertDatabaseHas('connector_heartbeats', [
            'connector_version' => '1.0.1',
            'wordpress_version' => '6.5',
            'status' => 'online',
        ]);
    }

    public function test_connector_capabilities_endpoint_requires_valid_token(): void
    {
        $response = $this->getJson('/api/v1/connector/capabilities');

        $response->assertStatus(401);
    }

    public function test_connector_capabilities_returns_discovered_capabilities(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'read.wordpress',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        $token = \Illuminate\Support\Str::random(64);
        $connection->update(['connector_token_hash' => hash('sha256', $token)]);

        $response = $this->getJson('/api/v1/connector/capabilities', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['capability_key', 'enabled', 'discovered_at']],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    ['capability_key' => 'read.wordpress'],
                ],
            ]);
    }

    public function test_revoked_connection_cannot_receive_heartbeats(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        $token = \Illuminate\Support\Str::random(64);
        $connection->update(['connector_token_hash' => hash('sha256', $token)]);

        $connection->revoke();

        $response = $this->postJson('/api/v1/connector/heartbeat', [
            'connector_version' => '1.0.0',
            'wordpress_version' => '6.5',
            'php_version' => '8.2',
            'status' => 'online',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(401);
    }

    public function test_connection_show_returns_latest_heartbeat(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        ConnectorHeartbeat::factory()->create([
            'site_connection_id' => $connection->id,
            'connector_version' => '1.0.0',
            'status' => 'online',
            'reported_at' => now()->subHour(),
        ]);

        ConnectorHeartbeat::factory()->create([
            'site_connection_id' => $connection->id,
            'connector_version' => '1.1.0',
            'status' => 'online',
            'reported_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/connections/' . $connection->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('meta.latest_heartbeat.connector_version', '1.1.0');
    }

    public function test_connection_show_returns_capabilities(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'pending',
        ]);

        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'read.site',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.plugin_update',
            'enabled' => false,
            'discovered_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/connections/' . $connection->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'meta' => ['capabilities'],
            ])
            ->assertJsonCount(2, 'meta.capabilities')
            ->assertJsonPath('meta.capabilities.0.capability_key', 'action.plugin_update')
            ->assertJsonPath('meta.capabilities.0.enabled', false)
            ->assertJsonPath('meta.capabilities.1.capability_key', 'read.site')
            ->assertJsonPath('meta.capabilities.1.enabled', true);
    }
}
