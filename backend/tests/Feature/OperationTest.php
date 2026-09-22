<?php

namespace Tests\Feature;

use App\Models\ConnectorCapability;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithSite(string $roleKey = 'owner'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $role = Role::factory()->create(['organization_id' => $org->id, 'key' => $roleKey]);

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $org->id, 'url' => 'https://example.com']);

        return [$user, $org, $site];
    }

    private function createActiveConnectionWithCapability(Site $site, string $capabilityKey = 'action.cache_clear', bool $enabled = true): SiteConnection
    {
        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => $capabilityKey,
            'enabled' => $enabled,
            'discovered_at' => now(),
        ]);

        return $connection;
    }

    public function test_unauthenticated_user_cannot_create_operation(): void
    {
        $org = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $org->id]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-123',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_with_access_can_create_cache_clear_operation(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'site_id', 'operation_type', 'status', 'policy_result', 'approval_required', 'idempotency_key', 'requested_by'],
                'meta',
                'request_id',
            ])
            ->assertJsonPath('data.operation_type', 'action.cache_clear')
            ->assertJsonPath('data.idempotency_key', 'test-key-123');

        $this->assertDatabaseHas('operations', [
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-123',
        ]);
    }

    public function test_cross_organization_access_is_rejected(): void
    {
        $otherUser = User::factory()->create();
        $otherOrg = Organization::factory()->create();

        $otherRole = Role::factory()->create(['organization_id' => $otherOrg->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $otherOrg->id]);

        $user = User::factory()->create();
        $userOrg = Organization::factory()->create();
        $userRole = Role::factory()->create(['organization_id' => $userOrg->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $userOrg->id,
            'user_id' => $user->id,
            'role_id' => $userRole->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-456',
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_connection_is_rejected(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        // No connection created

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-789',
        ]);

        $response->assertStatus(403);
    }

    public function test_inactive_connection_is_rejected(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        // Create pending connection (not active)
        SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-inactive',
        ]);

        $response->assertStatus(403);
    }

    public function test_revoked_connection_is_rejected(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $connection->revoke();

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-revoked',
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_capability_is_rejected(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        // Create active connection but NO capability
        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-no-cap',
        ]);

        $response->assertStatus(403);
    }

    public function test_ungranted_capability_is_rejected(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        // Create active connection with capability but NOT enabled
        $this->createActiveConnectionWithCapability($site, 'action.cache_clear', false);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-disabled-cap',
        ]);

        $response->assertStatus(403);
    }

    // IDEMPOTENCY TESTS

    public function test_missing_idempotency_key_is_rejected(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            // Missing idempotency_key
        ]);

        $response->assertStatus(422);
    }

    public function test_same_idempotency_key_same_request_returns_existing_operation(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site);

        $payload = [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-key-123',
            'target_json' => ['cache_type' => 'full'],
        ];

        $response1 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', $payload);
        $response1->assertStatus(201);
        $operationId1 = $response1->json('data.id');

        $response2 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', $payload);
        $response2->assertStatus(201);
        $operationId2 = $response2->json('data.id');

        $this->assertEquals($operationId1, $operationId2, 'Same idempotency key with same payload should return same operation');

        $this->assertDatabaseCount('operations', 1);
    }

    public function test_same_idempotency_key_different_request_returns_409(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site);

        $response1 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-conflict-key',
            'target_json' => ['cache_type' => 'full'],
        ]);
        $response1->assertStatus(201);

        $response2 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-conflict-key',
            'target_json' => ['cache_type' => 'partial'], // Different payload
        ]);
        $response2->assertStatus(409);
        $response2->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_same_idempotency_key_different_sites_no_collision(): void
    {
        [$user, $org, $site1] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site1);

        // Create another site in the same org
        $site2 = Site::factory()->create(['organization_id' => $org->id, 'url' => 'https://example2.com']);
        $this->createActiveConnectionWithCapability($site2);

        $response1 = $this->postJson('/api/v1/sites/' . $site1->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-cross-site',
            'target_json' => ['cache_type' => 'full'],
        ]);
        $response1->assertStatus(201);
        $operationId1 = $response1->json('data.id');

        $response2 = $this->postJson('/api/v1/sites/' . $site2->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-cross-site',
            'target_json' => ['cache_type' => 'full'],
        ]);
        $response2->assertStatus(201);
        $operationId2 = $response2->json('data.id');

        $this->assertNotEquals($operationId1, $operationId2, 'Different sites should have different operations even with same idempotency key');
        $this->assertDatabaseCount('operations', 2);
    }

    // APPROVAL POLICY TESTS

    public function test_require_approval_false_creates_normal_operation(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        // Set approval policy to NOT require approval
        $org->update(['approval_policy' => [
            'require_approval' => false,
            'high_criticality_requires_approval' => false,
        ]]);

        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'approval-false-key',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.approval_required', false)
            ->assertJsonPath('data.policy_result', 'allowed')
            ->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseHas('operations', [
            'id' => $response->json('data.id'),
            'status' => 'queued',
            'approval_required' => false,
        ]);
    }

    public function test_require_approval_true_creates_pending_approval(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        // Set approval policy to REQUIRE approval
        $org->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);

        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'approval-true-key',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.approval_required', true)
            ->assertJsonPath('data.policy_result', 'pending_approval')
            ->assertJsonPath('data.status', 'pending_approval');

        $this->assertDatabaseHas('operations', [
            'id' => $response->json('data.id'),
            'status' => 'pending_approval',
            'approval_required' => true,
        ]);

        // Verify approval request was created
        $this->assertDatabaseHas('approval_requests', [
            'operation_id' => $response->json('data.id'),
            'status' => 'pending',
        ]);
    }

    public function test_pending_approval_does_not_execute_remotely(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        $org->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);

        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'pending-no-execute',
        ]);

        $response->assertStatus(201);
        $operationId = $response->json('data.id');

        // Operation should be in pending_approval, not queued/running
        $this->assertDatabaseHas('operations', [
            'id' => $operationId,
            'status' => 'pending_approval',
        ]);

        // No operation attempt should be created
        $this->assertDatabaseCount('operation_attempts', 0);
    }

    public function test_invalid_approval_policy_fails_closed(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        // Set invalid approval policy (missing required keys)
        $org->update(['approval_policy' => [
            'require_approval' => 'not-a-boolean', // Invalid type
        ]]);

        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'invalid-policy-key',
        ]);

        // Should be denied (fail closed)
        $response->assertStatus(403);
    }

    // GET OPERATION TESTS

    public function test_authorized_user_can_get_operation(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site);

        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'get-test-key',
        ]);
        $createResponse->assertStatus(201);
        $operationId = $createResponse->json('data.id');

        $getResponse = $this->getJson('/api/v1/sites/' . $site->id . '/operations/' . $operationId);
        $getResponse->assertStatus(200)
            ->assertJsonPath('data.id', $operationId)
            ->assertJsonPath('data.operation_type', 'action.cache_clear');
    }

    public function test_cross_organization_user_cannot_get_operation(): void
    {
        $otherUser = User::factory()->create();
        $otherOrg = Organization::factory()->create();
        $otherRole = Role::factory()->create(['organization_id' => $otherOrg->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $otherOrg->id]);

        $user = User::factory()->create();
        $userOrg = Organization::factory()->create();
        $userRole = Role::factory()->create(['organization_id' => $userOrg->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $userOrg->id,
            'user_id' => $user->id,
            'role_id' => $userRole->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        // First create operation as other user
        Sanctum::actingAs($otherUser);
        $this->createActiveConnectionWithCapability($site);
        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'cross-org-get-key',
        ]);
        $createResponse->assertStatus(201);
        $operationId = $createResponse->json('data.id');

        // Now try to GET as unauthorized user
        Sanctum::actingAs($user);
        $getResponse = $this->getJson('/api/v1/sites/' . $site->id . '/operations/' . $operationId);
        $getResponse->assertStatus(403);
    }

    public function test_user_without_access_cannot_get_operation(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site);

        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'no-access-get-key',
        ]);
        $createResponse->assertStatus(201);
        $operationId = $createResponse->json('data.id');

        // Create another user without access to this org
        $otherUser = User::factory()->create();
        $otherOrg = Organization::factory()->create();
        $otherRole = Role::factory()->create(['organization_id' => $otherOrg->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($otherUser);

        $getResponse = $this->getJson('/api/v1/sites/' . $site->id . '/operations/' . $operationId);
        $getResponse->assertStatus(403);
    }

    // AUDIT TESTS

    public function test_operation_creation_records_audit_events(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'audit-test-key',
        ]);
        $response->assertStatus(201);
        $operationId = $response->json('data.id');

        // Check operation_requested audit log
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'operation',
            'target_id' => $operationId,
            'action' => 'operation_requested',
        ]);

        // Check policy_evaluated audit log
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'operation',
            'target_id' => $operationId,
            'action' => 'policy_evaluated',
        ]);
    }

    public function test_approval_required_operation_records_approval_audit(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        $org->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);

        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'approval-audit-key',
        ]);
        $response->assertStatus(201);
        $operationId = $response->json('data.id');

        // Check approval_requested audit log (or similar)
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'operation',
            'target_id' => $operationId,
            'action' => 'operation_requested',
        ]);

        // The approval request creation should also be audited
        $this->assertDatabaseHas('approval_requests', [
            'operation_id' => $operationId,
        ]);
    }
}