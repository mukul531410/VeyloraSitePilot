<?php

namespace Tests\Feature;

use App\Models\ConnectorCapability;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\MaintenanceLock;
use App\Services\OperationService;
use App\Services\PolicyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithSite(string $roleKey = 'owner'): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => $roleKey]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id, 'url' => 'https://example.com']);

        return [$user, $organization, $site];
    }

    private function createConnectorWithToken(Site $site, string $token): SiteConnection
    {
        return SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'connector_token_hash' => hash('sha256', $token),
        ]);
    }

    private function createActiveConnectionWithCapability(Site $site, string $capabilityKey = 'action.cache_clear', bool $enabled = true): SiteConnection
    {
        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => $capabilityKey,
            'enabled' => $enabled,
            'discovered_at' => now(),
        ]);

        return $connection;
    }

    private function createQueuedOperation(): Operation
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
            'require_approval' => false,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => uniqid('dispatch-', true),
        ]);
        $response->assertStatus(201)->assertJsonPath('data.status', Operation::STATUS_QUEUED);

        return Operation::findOrFail($response->json('data.id'));
    }

    public function test_unauthenticated_user_cannot_create_operation(): void
    {
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-123',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_with_access_can_create_cache_clear_operation(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
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
            ->assertJsonPath('data.max_attempts', 3)
            ->assertJsonPath('data.idempotency_key', 'test-key-123');

        $this->assertDatabaseHas('operations', [
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-123',
        ]);
    }

    public function test_cache_clear_target_defaults_to_wordpress_and_rejects_other_cache_types(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $this->postJson('/api/v1/sites/'.$site->id.'/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'wordpress-cache-type-default',
        ])->assertCreated()->assertJsonPath('data.target_json.cache_type', 'wordpress');

        $this->postJson('/api/v1/sites/'.$site->id.'/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'invalid-cache-type',
            'target_json' => ['cache_type' => 'object_cache'],
        ])->assertUnprocessable()->assertJsonValidationErrors('target_json.cache_type');
    }

    public function test_explicit_wordpress_cache_type_is_accepted_and_missing_type_defaults(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $explicit = $this->postJson('/api/v1/sites/'.$site->id.'/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'explicit-wordpress-cache-type',
            'target_json' => ['cache_type' => 'wordpress'],
        ]);
        $explicit->assertCreated()->assertJsonPath('data.target_json.cache_type', 'wordpress');

        $this->postJson('/api/v1/sites/'.$site->id.'/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'default-wordpress-cache-type',
        ])->assertCreated()->assertJsonPath('data.target_json.cache_type', 'wordpress');
    }

    public function test_cross_organization_access_is_rejected(): void
    {
        $otherUser = User::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherRole = Role::factory()->create(['organization_id' => $otherOrganization->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $otherOrganization->id,
            'user_id' => $otherUser->id,
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $otherOrganization->id]);

        $user = User::factory()->create();
        $userOrganization = Organization::factory()->create();
        $userRole = Role::factory()->create(['organization_id' => $userOrganization->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $userOrganization->id,
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
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-789',
        ]);

        $response->assertStatus(403);
    }

    public function test_inactive_connection_is_rejected(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'pending']);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-inactive',
        ]);

        $response->assertStatus(403);
    }

    public function test_revoked_connection_is_rejected(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $connection->revoke();

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-revoked',
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_capability_is_rejected(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-no-cap',
        ]);

        $response->assertStatus(403);
    }

    public function test_ungranted_capability_is_rejected(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site, 'action.cache_clear', false);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'test-key-disabled-cap',
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
        ]);

        $response->assertStatus(422);
    }

    public function test_same_idempotency_key_same_request_returns_existing_operation(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);
        $payload = [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-key-123',
            'target_json' => ['cache_type' => 'wordpress'],
        ];

        $response1 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', $payload);
        $response1->assertStatus(201);
        $operationId1 = $response1->json('data.id');
        $response2 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', $payload);
        $response2->assertStatus(201);
        $operationId2 = $response2->json('data.id');

        $this->assertEquals($operationId1, $operationId2);
        $this->assertDatabaseCount('operations', 1);
    }

    public function test_cache_clear_rejects_non_wordpress_type_for_existing_idempotency_key(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response1 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-conflict-key',
            'target_json' => ['cache_type' => 'wordpress'],
        ]);
        $response1->assertStatus(201);
        $response2 = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-conflict-key',
            'target_json' => ['cache_type' => 'partial'],
        ]);

        $response2->assertUnprocessable()->assertJsonValidationErrors('target_json.cache_type');
        $this->assertDatabaseCount('operations', 1);
    }

    public function test_same_key_and_target_with_different_operation_type_conflicts_at_service_boundary(): void
    {
        [$user, , $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);
        $target = ['cache_type' => 'wordpress'];

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-type-conflict',
            'target_json' => $target,
        ]);
        $response->assertCreated();

        try {
            app(OperationService::class)->createOperation($user, $site, 'action.other', $target, 'idem-type-conflict');
            $this->fail('An idempotency key cannot identify different operation types.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Idempotency conflict', $exception->getMessage());
        }

        try {
            app(OperationService::class)->createOperation(
                $user,
                $site,
                'action.cache_clear',
                ['cache_type' => 'wordpress', 'scope' => 'other'],
                'idem-type-conflict',
            );
            $this->fail('An idempotency key cannot identify different targets.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Idempotency conflict', $exception->getMessage());
        }

        $this->assertDatabaseCount('operations', 1);
    }

    public function test_expected_unique_key_collision_rereads_existing_request_and_replays_or_conflicts(): void
    {
        [$user, , $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);
        $target = ['cache_type' => 'wordpress'];
        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-collision',
            'target_json' => $target,
        ]);
        $response->assertCreated();
        $existing = Operation::findOrFail($response->json('data.id'));

        $service = new class(app(PolicyEngine::class), app(MaintenanceLock::class)) extends OperationService {
            public function resolveCollision(QueryException $exception, string $siteId, string $type, array $target, string $key): Operation
            {
                return $this->resolveIdempotencyCollision($exception, $siteId, $type, $target, $key);
            }
        };

        $message = 'UNIQUE constraint failed: operations.site_id, operations.idempotency_key';
        $previous = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 ' . $message, 23000);
        $previous->errorInfo = ['23000', 19, $message];
        $collision = new QueryException('sqlite', 'insert into operations (...) values (...)', [], $previous);

        $replayed = $service->resolveCollision($collision, $site->id, 'action.cache_clear', $target, 'idem-collision');
        $this->assertSame($existing->id, $replayed->id);

        foreach ([
            ['action.cache_clear', ['cache_type' => 'wordpress', 'scope' => 'other']],
            ['action.other', $target],
        ] as [$type, $differentTarget]) {
            try {
                $service->resolveCollision($collision, $site->id, $type, $differentTarget, 'idem-collision');
                $this->fail('A unique-key collision for a different logical request must conflict.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Idempotency conflict', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('operations', 1);
    }

    public function test_unrelated_unique_constraint_exception_is_not_swallowed(): void
    {
        [$user, , $site] = $this->createUserWithSite();
        $service = new class(app(PolicyEngine::class), app(MaintenanceLock::class)) extends OperationService {
            public function resolveCollision(QueryException $exception, string $siteId): Operation
            {
                return $this->resolveIdempotencyCollision($exception, $siteId, 'action.cache_clear', [], 'unrelated');
            }
        };

        $message = 'UNIQUE constraint failed: users.email';
        $previous = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 ' . $message, 23000);
        $previous->errorInfo = ['23000', 19, $message];
        $unrelated = new QueryException('sqlite', 'insert into users (...) values (...)', [], $previous);

        try {
            $service->resolveCollision($unrelated, $site->id);
            $this->fail('Unrelated database exceptions must be rethrown.');
        } catch (QueryException $exception) {
            $this->assertSame($unrelated, $exception);
        }
    }

    public function test_same_idempotency_key_different_sites_no_collision(): void
    {
        [$user, $organization, $site1] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site1);
        $site2 = Site::factory()->create(['organization_id' => $organization->id, 'url' => 'https://example2.com']);
        $this->createActiveConnectionWithCapability($site2);

        $response1 = $this->postJson('/api/v1/sites/' . $site1->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-cross-site',
            'target_json' => ['cache_type' => 'wordpress'],
        ]);
        $response1->assertStatus(201);
        $operationId1 = $response1->json('data.id');
        $response2 = $this->postJson('/api/v1/sites/' . $site2->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'idem-cross-site',
            'target_json' => ['cache_type' => 'wordpress'],
        ]);
        $response2->assertStatus(201);
        $operationId2 = $response2->json('data.id');

        $this->assertNotEquals($operationId1, $operationId2);
        $this->assertDatabaseCount('operations', 2);
    }

    public function test_require_approval_false_creates_normal_operation(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
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
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
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
        $this->assertDatabaseHas('approval_requests', [
            'operation_id' => $response->json('data.id'),
            'status' => 'pending',
        ]);
    }

    public function test_pending_approval_does_not_execute_remotely(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
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

        $this->assertDatabaseHas('operations', [
            'id' => $operationId,
            'status' => 'pending_approval',
        ]);
        $this->assertDatabaseCount('operation_attempts', 0);
    }

    public function test_invalid_approval_policy_fails_closed(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => ['require_approval' => 'not-a-boolean']]);
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'invalid-policy-key',
        ]);

        $response->assertStatus(403);
    }

    public function test_authorized_user_can_get_operation(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
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
        $otherOrganization = Organization::factory()->create();
        $otherRole = Role::factory()->create(['organization_id' => $otherOrganization->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $otherOrganization->id,
            'user_id' => $otherUser->id,
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $otherOrganization->id]);
        $user = User::factory()->create();
        $userOrganization = Organization::factory()->create();
        $userRole = Role::factory()->create(['organization_id' => $userOrganization->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $userOrganization->id,
            'user_id' => $user->id,
            'role_id' => $userRole->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($otherUser);
        $this->createActiveConnectionWithCapability($site);
        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'cross-org-get-key',
        ]);
        $createResponse->assertStatus(201);
        $operationId = $createResponse->json('data.id');

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/sites/' . $site->id . '/operations/' . $operationId)->assertStatus(403);
    }

    public function test_user_without_access_cannot_get_operation(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);
        $createResponse = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'no-access-get-key',
        ]);
        $createResponse->assertStatus(201);
        $operationId = $createResponse->json('data.id');

        $otherUser = User::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherRole = Role::factory()->create(['organization_id' => $otherOrganization->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $otherOrganization->id,
            'user_id' => $otherUser->id,
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/sites/' . $site->id . '/operations/' . $operationId)->assertStatus(403);
    }

    public function test_operation_creation_records_audit_events(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);
        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'audit-test-key',
        ]);
        $response->assertStatus(201);
        $operationId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'operation',
            'target_id' => $operationId,
            'action' => 'operation_requested',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'operation',
            'target_id' => $operationId,
            'action' => 'policy_evaluated',
        ]);
    }

    public function test_approval_required_operation_records_approval_audit(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
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

        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'operation',
            'target_id' => $operationId,
            'action' => 'operation_requested',
        ]);
        $this->assertDatabaseHas('approval_requests', ['operation_id' => $operationId]);
    }

    public function test_dispatching_queued_operation_creates_one_dispatched_attempt_with_connector_fields(): void
    {
        $operation = $this->createQueuedOperation();
        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());

        (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
            $this->app->make(MaintenanceLock::class),
            $this->app->make(\App\Services\PolicyEngine::class),
        );

        $this->assertDatabaseCount('operation_attempts', 1);
        $attempt = OperationAttempt::where('operation_id', $operation->id)->firstOrFail();
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->status);
        $this->assertNotNull($attempt->connector_job_id);
        $this->assertNotNull($attempt->lock_token);
        $this->assertNotNull($attempt->timeout_at);
    }

    public function test_dispatching_queued_operation_changes_operation_to_running(): void
    {
        $operation = $this->createQueuedOperation();
        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());

        (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
            $this->app->make(MaintenanceLock::class),
            $this->app->make(\App\Services\PolicyEngine::class),
        );

        $this->assertDatabaseHas('operations', [
            'id' => $operation->id,
            'status' => Operation::STATUS_RUNNING,
        ]);
    }

    public function test_dispatching_operation_releases_maintenance_lock_after_persisting_attempt(): void
    {
        $operation = $this->createQueuedOperation();
        $lock = \Mockery::mock(MaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->with($operation->site_id, $operation->id, 1)->andReturn(true);
        $lock->shouldReceive('release')->once()->with($operation->site_id, $operation->id, 1)->andReturn(true);
        $this->app->instance(MaintenanceLock::class, $lock);

        (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
            $lock,
            $this->app->make(\App\Services\PolicyEngine::class),
        );

        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_failed_maintenance_lock_rolls_back_attempt_and_operation_transition(): void
    {
        $operation = $this->createQueuedOperation();
        $lock = \Mockery::mock(MaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(false);
        $lock->shouldNotReceive('release');

        try {
            (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
                $lock,
                $this->app->make(\App\Services\PolicyEngine::class),
            );
            $this->fail('Expected a failed maintenance lock acquisition to abort dispatch.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Could not acquire maintenance lock', $exception->getMessage());
        }

        $this->assertSame(0, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame(Operation::STATUS_QUEUED, $operation->fresh()->status);
    }

    public function test_claiming_job_releases_maintenance_lock_after_claim(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-lock-release-token');
        $lock = \Mockery::mock(MaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->with($site->id, $attempt->operation_id, 1)->andReturn(true);
        $lock->shouldReceive('release')->once()->with($site->id, $attempt->operation_id, 1)->andReturn(true);
        $this->app->instance(MaintenanceLock::class, $lock);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(200);
        $this->assertSame(OperationAttempt::STATUS_ACCEPTED, $attempt->fresh()->status);
    }

    public function test_dispatching_same_operation_twice_does_not_create_second_attempt(): void
    {
        $operation = $this->createQueuedOperation();
        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());
        $job = new \App\Jobs\DispatchOperationJob($operation->id);
        $maintenanceLock = $this->app->make(MaintenanceLock::class);
        $policyEngine = $this->app->make(\App\Services\PolicyEngine::class);

        $job->handle($maintenanceLock, $policyEngine);
        $job->handle($maintenanceLock, $policyEngine);

        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_queued_dispatch_jobs_remain_unique_per_operation(): void
    {
        $operation = $this->createQueuedOperation();
        Queue::fake();

        \App\Jobs\DispatchOperationJob::dispatch($operation->id);
        \App\Jobs\DispatchOperationJob::dispatch($operation->id);

        Queue::assertPushed(\App\Jobs\DispatchOperationJob::class, 1);
        $this->assertSame(0, OperationAttempt::where('operation_id', $operation->id)->count());
    }

    public function test_dispatching_after_attempt_one_allocates_attempt_two_with_a_new_connector_job_id(): void
    {
        $operation = $this->createQueuedOperation();
        $firstJobId = (string) \Illuminate\Support\Str::ulid();
        OperationAttempt::create([
            'operation_id' => $operation->id,
            'attempt_number' => 1,
            'status' => OperationAttempt::STATUS_TIMEOUT,
            'connector_job_id' => $firstJobId,
        ]);
        $operation->update(['status' => Operation::STATUS_QUEUED]);
        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());

        (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
            $this->app->make(MaintenanceLock::class),
            $this->app->make(\App\Services\PolicyEngine::class),
        );

        $attempts = OperationAttempt::where('operation_id', $operation->id)->orderBy('attempt_number')->get();
        $this->assertSame([1, 2], $attempts->pluck('attempt_number')->all());
        $this->assertNotSame($firstJobId, $attempts[1]->connector_job_id);
        $this->assertSame(Operation::STATUS_RUNNING, $operation->fresh()->status);
    }

    public function test_dispatch_uses_non_default_stored_max_attempts_value(): void
    {
        $operation = $this->createQueuedOperation();
        $operation->update(['max_attempts' => 2, 'status' => Operation::STATUS_QUEUED]);
        $maintenanceLock = $this->getFakeMaintenanceLock();
        $policyEngine = $this->app->make(\App\Services\PolicyEngine::class);

        (new \App\Jobs\DispatchOperationJob($operation->id))->handle($maintenanceLock, $policyEngine);
        $operation->refresh()->update(['status' => Operation::STATUS_QUEUED]);
        (new \App\Jobs\DispatchOperationJob($operation->id))->handle($maintenanceLock, $policyEngine);

        $attempts = OperationAttempt::where('operation_id', $operation->id)->orderBy('attempt_number')->get();
        $this->assertSame(2, $operation->fresh()->max_attempts);
        $this->assertSame(2, $attempts->count());
        $this->assertSame([1, 2], $attempts->pluck('attempt_number')->all());

        $operation->refresh()->update(['status' => Operation::STATUS_QUEUED]);
        try {
            (new \App\Jobs\DispatchOperationJob($operation->id))->handle($maintenanceLock, $policyEngine);
            $this->fail('Expected dispatch to honor the stored max_attempts value of 2.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Maximum attempts reached', $exception->getMessage());
        }

        $this->assertSame(2, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame(2, $operation->fresh()->max_attempts);
    }

    public function test_dispatch_blocks_attempt_four_when_stored_max_attempts_is_exhausted(): void
    {
        $operation = $this->createQueuedOperation();
        $operation->update(['max_attempts' => 3, 'status' => Operation::STATUS_QUEUED]);
        foreach ([1, 2, 3] as $number) {
            OperationAttempt::create([
                'operation_id' => $operation->id,
                'attempt_number' => $number,
                'status' => OperationAttempt::STATUS_TIMEOUT,
                'connector_job_id' => (string) \Illuminate\Support\Str::ulid(),
            ]);
        }

        try {
            (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
                $this->getFakeMaintenanceLock(),
                $this->app->make(\App\Services\PolicyEngine::class),
            );
            $this->fail('Expected dispatch to block after the stored max_attempts limit.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Maximum attempts reached', $exception->getMessage());
        }

        $this->assertSame(3, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame(Operation::STATUS_QUEUED, $operation->fresh()->status);
    }

    public function test_terminal_operation_never_receives_an_attempt(): void
    {
        foreach ([
            Operation::STATUS_SUCCEEDED,
            Operation::STATUS_FAILED,
            Operation::STATUS_UNKNOWN,
            Operation::STATUS_CANCELLED,
            Operation::STATUS_DEAD_LETTER,
        ] as $status) {
            $operation = $this->createQueuedOperation();
            $operation->update(['status' => $status]);

            (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
                $this->getFakeMaintenanceLock(),
                $this->app->make(\App\Services\PolicyEngine::class),
            );

            $this->assertSame(0, OperationAttempt::where('operation_id', $operation->id)->count(), $status);
        }
    }

    public function test_attempt_number_and_connector_job_id_uniqueness_constraints_exist(): void
    {
        $indexes = \Illuminate\Support\Facades\DB::select("PRAGMA index_list('operation_attempts')");
        $attemptIndex = collect($indexes)->first(fn ($index) => $index->name === 'operation_attempts_operation_id_attempt_number_unique');
        $this->assertNotNull($attemptIndex);
        $this->assertSame(1, (int) $attemptIndex->unique);

        $operation = $this->createQueuedOperation();
        $jobId = (string) \Illuminate\Support\Str::ulid();
        OperationAttempt::create(['operation_id' => $operation->id, 'attempt_number' => 1, 'status' => 'dispatched', 'connector_job_id' => $jobId]);

        try {
            OperationAttempt::create(['operation_id' => $operation->id, 'attempt_number' => 1, 'status' => 'dispatched', 'connector_job_id' => (string) \Illuminate\Support\Str::ulid()]);
            $this->fail('Expected duplicate attempt number to violate the unique constraint.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->count());
        }

        try {
            OperationAttempt::create(['operation_id' => $operation->id, 'attempt_number' => 2, 'status' => 'dispatched', 'connector_job_id' => $jobId]);
            $this->fail('Expected duplicate connector job ID to violate its existing unique constraint.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->count());
        }
    }

    public function test_unrelated_operations_can_dispatch_independently(): void
    {
        $first = $this->createQueuedOperation();
        $second = $this->createQueuedOperation();
        $lock = $this->getFakeMaintenanceLock();
        $policyEngine = $this->app->make(\App\Services\PolicyEngine::class);

        (new \App\Jobs\DispatchOperationJob($first->id))->handle($lock, $policyEngine);
        (new \App\Jobs\DispatchOperationJob($second->id))->handle($lock, $policyEngine);

        $this->assertSame([1, 1], OperationAttempt::whereIn('operation_id', [$first->id, $second->id])->orderBy('operation_id')->pluck('attempt_number')->all());
        $this->assertSame(Operation::STATUS_RUNNING, $first->fresh()->status);
        $this->assertSame(Operation::STATUS_RUNNING, $second->fresh()->status);
    }

    public function test_expired_dispatched_attempt_times_out_and_automatically_retries_same_operation(): void
    {
        [, $attempt, $jobId] = $this->createDispatchedJob('timeout-dispatched-token');
        $operation = Operation::findOrFail($attempt->operation_id);
        $originalIdempotencyKey = $operation->idempotency_key;
        $attempt->update(['timeout_at' => now()->subSecond()]);

        $this->runExpiredAttemptDetector();

        $attempt->refresh();
        $this->assertSame('timeout', $attempt->status);
        $this->assertTrue($attempt->retryable);
        $this->assertSame('attempt_timeout', $attempt->error_code);
        $this->assertNotNull($attempt->finished_at);
        $this->assertSame($jobId, $attempt->connector_job_id);
        $this->assertNotNull($attempt->timeout_at);
        $this->assertSame('safe_automatic_retry', app(\App\Services\OperationRetryClassifier::class)->classifyAttemptFailure($attempt));
        $this->assertSame('running', $operation->fresh()->status);
        $this->assertSame($originalIdempotencyKey, $operation->fresh()->idempotency_key);
        $this->assertSame(2, OperationAttempt::where('operation_id', $attempt->operation_id)->count());
        $this->assertSame(1, Operation::where('site_id', $operation->site_id)->where('idempotency_key', $originalIdempotencyKey)->count());
        $secondAttempt = OperationAttempt::where('operation_id', $attempt->operation_id)->where('attempt_number', 2)->firstOrFail();
        $this->assertSame('dispatched', $secondAttempt->status);
        $this->assertNotSame($jobId, $secondAttempt->connector_job_id);

        $event = \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_timeout')->firstOrFail();
        $this->assertSame([
            'operation_id' => $attempt->operation_id,
            'attempt_id' => $attempt->id,
            'attempt_number' => 1,
            'connector_job_id' => $jobId,
            'previous_attempt_status' => 'dispatched',
            'new_attempt_status' => 'timeout',
            'previous_operation_status' => 'running',
            'new_operation_status' => 'running',
            'timeout_at' => $attempt->timeout_at->toIso8601String(),
            'detected_at' => $event->metadata_json['detected_at'],
            'retry_classification' => 'safe_automatic_retry',
        ], $event->metadata_json);

        $retryEvent = \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_retry_scheduled')->firstOrFail();
        $this->assertSame([
            'operation_id' => $operation->id,
            'previous_attempt_id' => $attempt->id,
            'previous_attempt_number' => 1,
            'new_attempt_number' => 2,
            'previous_connector_job_id' => $jobId,
            'retry_classification' => 'safe_automatic_retry',
            'retry_reason' => 'expired_dispatched_attempt',
            'scheduled_at' => $retryEvent->metadata_json['scheduled_at'],
            'max_attempts' => 3,
        ], $retryEvent->metadata_json);

        $this->runExpiredAttemptDetector();
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_timeout')->count());
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_retry_scheduled')->count());
        $this->assertSame(2, OperationAttempt::where('operation_id', $attempt->operation_id)->count());
    }

    public function test_expired_accepted_attempt_times_out_as_unknown_and_marks_operation_unknown(): void
    {
        [, $attempt, $jobId] = $this->createClaimedJob('timeout-accepted-token');
        $attempt->update(['timeout_at' => now()->subSecond()]);

        $this->runExpiredAttemptDetector();

        $attempt->refresh();
        $operation = Operation::findOrFail($attempt->operation_id);
        $this->assertSame('timeout', $attempt->status);
        $this->assertFalse($attempt->retryable);
        $this->assertSame('attempt_timeout', $attempt->error_code);
        $this->assertSame($jobId, $attempt->connector_job_id);
        $this->assertSame('unknown', app(\App\Services\OperationRetryClassifier::class)->classifyAttemptFailure($attempt));
        $this->assertSame('unknown', $operation->status);
        $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->count());

        $timeoutEvent = \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_timeout')->firstOrFail();
        $unknownEvent = \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_unknown')->firstOrFail();
        $this->assertSame('accepted', $timeoutEvent->metadata_json['previous_attempt_status']);
        $this->assertSame('running', $timeoutEvent->metadata_json['previous_operation_status']);
        $this->assertSame('unknown', $timeoutEvent->metadata_json['new_operation_status']);
        $this->assertSame('unknown', $timeoutEvent->metadata_json['retry_classification']);
        $this->assertSame($timeoutEvent->metadata_json, $unknownEvent->metadata_json);
        $this->assertNotNull($operation->finished_at);

        $this->runExpiredAttemptDetector();
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_timeout')->count());
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_unknown')->count());
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_dead_lettered')->count());
    }

    public function test_max_attempts_three_retries_twice_then_dead_letters_without_a_fourth_attempt(): void
    {
        [, $firstAttempt] = $this->createDispatchedJob('timeout-max-three-token');
        $operation = Operation::findOrFail($firstAttempt->operation_id);
        $operation->update(['max_attempts' => 3]);
        $firstAttempt->update(['timeout_at' => now()->subSecond()]);

        $this->runExpiredAttemptDetector();

        $this->assertSame([1, 2], OperationAttempt::where('operation_id', $operation->id)->orderBy('attempt_number')->pluck('attempt_number')->all());
        $secondAttempt = OperationAttempt::where('operation_id', $operation->id)->where('attempt_number', 2)->firstOrFail();
        $this->assertSame('dispatched', $secondAttempt->status);

        $secondAttempt->update(['timeout_at' => now()->subSecond()]);
        $this->runExpiredAttemptDetector();

        $thirdAttempt = OperationAttempt::where('operation_id', $operation->id)->where('attempt_number', 3)->firstOrFail();
        $this->assertSame('dispatched', $thirdAttempt->status);
        $this->assertSame('running', $operation->fresh()->status);

        $thirdAttempt->update(['timeout_at' => now()->subSecond()]);
        $this->runExpiredAttemptDetector();

        $this->assertSame(3, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame(3, $operation->fresh()->max_attempts);
        $this->assertSame('dead_letter', $operation->fresh()->status);
        $this->assertSame(2, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_scheduled')->count());
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_dead_lettered')->count());
        $this->assertSame(3, $thirdAttempt->fresh()->attempt_number);
        $this->assertSame(3, OperationAttempt::where('operation_id', $operation->id)->distinct('connector_job_id')->count('connector_job_id'));

        $this->runExpiredAttemptDetector();
        $this->assertSame(3, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_dead_lettered')->count());
    }

    public function test_max_attempts_one_dead_letters_and_preserves_operation_and_approval_fields(): void
    {
        [$site, $operation, $attempt] = $this->createApprovedDispatchedOperation('timeout-max-one-token');
        $operation->update(['max_attempts' => 1]);
        $attempt->update(['timeout_at' => now()->subSecond()]);
        $approval = $operation->approvalRequest()->firstOrFail();
        $before = [
            'id' => $operation->id,
            'site_id' => $operation->site_id,
            'organization_id' => $site->organization_id,
            'operation_type' => $operation->operation_type,
            'target_json' => $operation->target_json,
            'idempotency_key' => $operation->idempotency_key,
            'approval_required' => $operation->approval_required,
            'max_attempts' => $operation->max_attempts,
            'approval_id' => $approval->id,
            'approval_status' => $approval->status,
            'connector_job_id' => $attempt->connector_job_id,
        ];

        $this->runExpiredAttemptDetector();

        $operation = $operation->fresh();
        $attempt = $attempt->fresh();
        $this->assertSame('dead_letter', $operation->status);
        $this->assertSame(1, $operation->max_attempts);
        $this->assertSame('timeout', $attempt->status);
        $this->assertTrue($attempt->retryable);
        $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame($before['connector_job_id'], $attempt->connector_job_id);
        $this->assertSame($before['id'], $operation->id);
        $this->assertSame($before['site_id'], $operation->site_id);
        $this->assertSame($before['organization_id'], $operation->site->organization_id);
        $this->assertSame($before['operation_type'], $operation->operation_type);
        $this->assertSame($before['target_json'], $operation->target_json);
        $this->assertSame($before['idempotency_key'], $operation->idempotency_key);
        $this->assertSame($before['approval_required'], $operation->approval_required);
        $this->assertSame($before['max_attempts'], $operation->max_attempts);
        $this->assertSame($before['approval_id'], $operation->approvalRequest->id);
        $this->assertSame($before['approval_status'], $operation->approvalRequest->status);
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_scheduled')->count());

        $event = \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_dead_lettered')->firstOrFail();
        $this->assertSame('operation', $event->target_type);
        $this->assertSame($operation->id, $event->correlation_id);
        $this->assertSame($attempt->id, $event->metadata_json['attempt_id']);
        $this->assertSame(1, $event->metadata_json['attempt_number']);
        $this->assertSame(1, $event->metadata_json['max_attempts']);
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_dead_lettered')->count());

        $this->runExpiredAttemptDetector();
        $this->assertSame('dead_letter', $operation->fresh()->status);
        $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_dead_lettered')->count());
    }

    public function test_expired_dispatched_attempt_is_ignored_if_operation_is_no_longer_running(): void
    {
        [, $attempt] = $this->createDispatchedJob('timeout-operation-state-changed');
        $operation = Operation::findOrFail($attempt->operation_id);
        $attempt->update(['timeout_at' => now()->subSecond()]);
        $operation->update(['status' => Operation::STATUS_CANCELLED]);

        $this->runExpiredAttemptDetector();

        $this->assertSame(Operation::STATUS_CANCELLED, $operation->fresh()->status);
        $this->assertSame('dispatched', $attempt->fresh()->status);
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'operation_dead_lettered')->count());
    }

    public function test_inactive_revoked_or_capability_missing_connector_blocks_automatic_retry(): void
    {
        foreach ([
            'inactive',
            'revoked',
            'capability_missing',
            'policy_changed',
            'requesting_user_inactive',
            'membership_inactive',
            'organization_suspended',
            'site_inactive',
        ] as $condition) {
            [$site, $attempt] = $this->createDispatchedJob('timeout-precondition-'.$condition);
            $operation = Operation::findOrFail($attempt->operation_id);
            $connection = $site->connections()->where('status', 'active')->firstOrFail();

            if ($condition === 'inactive') {
                $connection->update(['status' => 'inactive']);
            } elseif ($condition === 'revoked') {
                $connection->update(['revoked_at' => now()]);
            } elseif ($condition === 'capability_missing') {
                $connection->capabilities()->where('capability_key', 'action.cache_clear')->delete();
            } elseif ($condition === 'policy_changed') {
                $site->organization->update(['approval_policy' => ['require_approval' => false]]);
            } elseif ($condition === 'requesting_user_inactive') {
                $operation->requestedBy->update(['status' => 'inactive']);
            } elseif ($condition === 'membership_inactive') {
                OrganizationMember::where('organization_id', $site->organization_id)
                    ->where('user_id', $operation->requested_by)
                    ->update(['status' => 'inactive']);
            } elseif ($condition === 'organization_suspended') {
                $site->organization->update(['status' => 'suspended']);
            } else {
                $site->update(['status' => 'inactive']);
            }

            $attempt->update(['timeout_at' => now()->subSecond()]);
            $this->runExpiredAttemptDetector();

            $this->assertSame('running', $operation->fresh()->status, $condition);
            $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->count(), $condition);
            $this->assertSame(0, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_scheduled')->count(), $condition);
            $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_blocked')->count(), $condition);
            $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->distinct('connector_job_id')->count('connector_job_id'), $condition);
            $this->assertSame(0, \App\Models\ApprovalRequest::where('operation_id', $operation->id)->count(), $condition);
        }
    }

    public function test_retry_dispatch_releases_maintenance_lock_and_does_not_create_a_second_approval(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($user);
        $connection = $this->createConnectorWithToken($site, 'timeout-approved-retry-token');
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'discovered_at' => now(),
        ]);
        $response = $this->postJson('/api/v1/sites/'.$site->id.'/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'approved-timeout-retry',
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'pending_approval');
        $operation = Operation::findOrFail($response->json('data.id'));
        $approval = \App\Models\ApprovalRequest::where('operation_id', $operation->id)->firstOrFail();
        $approval->update(['status' => 'approved', 'reviewed_by' => User::factory()->create()->id, 'reviewed_at' => now()]);
        \Illuminate\Support\Facades\DB::table('operations')->where('id', $operation->id)->update([
            'status' => Operation::STATUS_QUEUED,
            'policy_result' => 'approved',
        ]);

        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());
        (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
            $this->app->make(MaintenanceLock::class),
            $this->app->make(\App\Services\PolicyEngine::class),
        );
        $attempt = OperationAttempt::where('operation_id', $operation->id)->firstOrFail();
        $attempt->update(['timeout_at' => now()->subSecond()]);
        $approvalCount = \App\Models\ApprovalRequest::where('operation_id', $operation->id)->count();

        $lock = \Mockery::mock(MaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->with($site->id, $operation->id, 2)->andReturn(true);
        $lock->shouldReceive('release')->once()->with($site->id, $operation->id, 2)->andReturn(true);
        $this->app->instance(MaintenanceLock::class, $lock);

        $this->runExpiredAttemptDetector();

        $this->assertSame(2, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame($approvalCount, \App\Models\ApprovalRequest::where('operation_id', $operation->id)->count());
    }

    public function test_retry_preserves_original_approval_when_current_policy_no_longer_requires_approval(): void
    {
        [$site, $operation, $attempt] = $this->createApprovedDispatchedOperation('approval-policy-relaxed');
        $site->organization->update(['approval_policy' => [
            'require_approval' => false,
            'high_criticality_requires_approval' => false,
        ]]);
        $attempt->update(['timeout_at' => now()->subSecond()]);

        $this->runExpiredAttemptDetector();

        $this->assertTrue($operation->fresh()->approval_required);
        $this->assertSame(2, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame(1, \App\Models\ApprovalRequest::where('operation_id', $operation->id)->count());
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_scheduled')->count());
    }

    public function test_retry_requires_original_approval_to_remain_approved(): void
    {
        foreach ([
            'pending' => 'pending',
            'rejected' => 'rejected',
            'expired' => 'expired',
            'missing' => null,
        ] as $case => $approvalStatus) {
            [, $operation, $attempt] = $this->createApprovedDispatchedOperation('approval-state-'.$case);
            $approval = $operation->approvalRequest;
            if ($approvalStatus === null) {
                $approval->delete();
            } else {
                $approval->update(['status' => $approvalStatus]);
            }
            $existingApprovalCount = \App\Models\ApprovalRequest::where('operation_id', $operation->id)->count();
            $originalConnectorJobId = $attempt->connector_job_id;
            $attempt->update(['timeout_at' => now()->subSecond()]);

            $this->runExpiredAttemptDetector();

            $attempts = OperationAttempt::where('operation_id', $operation->id)->get();
            $this->assertSame(1, $attempts->count(), $case);
            $this->assertSame([$originalConnectorJobId], $attempts->pluck('connector_job_id')->all(), $case);
            $this->assertSame($existingApprovalCount, \App\Models\ApprovalRequest::where('operation_id', $operation->id)->count(), $case);
            $this->assertSame(0, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_scheduled')->count(), $case);
            $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_blocked')->count(), $case);
            $this->assertSame(Operation::STATUS_RUNNING, $operation->fresh()->status, $case);
        }
    }

    public function test_failed_retry_dispatch_can_be_recovered_by_stale_dispatcher_without_duplicate_attempt(): void
    {
        [, $attempt] = $this->createDispatchedJob('timeout-lock-failure-token');
        $operation = Operation::findOrFail($attempt->operation_id);
        $firstConnectorJobId = $attempt->connector_job_id;
        $attempt->update(['timeout_at' => now()->subSecond()]);

        $lock = \Mockery::mock(MaintenanceLock::class);
        $lock->shouldReceive('acquire')->once()->with($operation->site_id, $operation->id, 2)->andThrow(new \RuntimeException('Redis unavailable'));
        $lock->shouldNotReceive('release');
        $this->app->instance(MaintenanceLock::class, $lock);

        $this->runExpiredAttemptDetector();

        $this->assertSame(1, OperationAttempt::where('operation_id', $operation->id)->count());
        $this->assertSame('queued', $operation->fresh()->status);
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_scheduled')->count());
        $this->assertSame(1, \App\Models\AuditLog::where('target_id', $operation->id)->where('action', 'attempt_retry_dispatch_failed')->count());

        // The sync queue runs Laravel's real unique-until-processing handler path;
        // it releases the queued uniqueness lock before the simulated handler failure.
        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());
        (new \App\Jobs\DispatchStaleQueuedOperations())->handle();
        (new \App\Jobs\DispatchStaleQueuedOperations())->handle();

        $attempts = OperationAttempt::where('operation_id', $operation->id)->orderBy('attempt_number')->get();
        $this->assertSame([1, 2], $attempts->pluck('attempt_number')->all());
        $this->assertSame(2, $attempts->pluck('connector_job_id')->unique()->count());
        $this->assertNotSame($firstConnectorJobId, $attempts[1]->connector_job_id);
        $this->assertSame(Operation::STATUS_RUNNING, $operation->fresh()->status);
        $this->assertSame(0, \App\Models\ApprovalRequest::where('operation_id', $operation->id)->count());
    }

    public function test_expired_executing_attempt_times_out_as_unknown(): void
    {
        [, $attempt] = $this->createClaimedJob('timeout-executing-token');
        $attempt->update(['status' => 'executing', 'timeout_at' => now()->subSecond()]);

        $this->runExpiredAttemptDetector();

        $attempt->refresh();
        $this->assertSame('timeout', $attempt->status);
        $this->assertFalse($attempt->retryable);
        $this->assertSame('unknown', app(\App\Services\OperationRetryClassifier::class)->classifyAttemptFailure($attempt));
        $this->assertSame('unknown', Operation::findOrFail($attempt->operation_id)->status);
        $this->assertSame('executing', \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_timeout')->firstOrFail()->metadata_json['previous_attempt_status']);
        $this->assertSame(1, OperationAttempt::where('operation_id', $attempt->operation_id)->count());
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_retry_scheduled')->count());
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'operation_dead_lettered')->count());
    }

    public function test_expired_result_received_attempt_is_ignored_for_verification_lifecycle(): void
    {
        [, $attempt, $jobId] = $this->createClaimedJob('timeout-result-received-token');
        OperationResult::create([
            'operation_id' => $attempt->operation_id,
            'operation_attempt_id' => $attempt->id,
            'connector_job_id' => $jobId,
            'result_status' => 'success',
            'verification_status' => 'pending',
        ]);
        $attempt->update(['status' => 'result_received', 'timeout_at' => now()->subSecond()]);
        Operation::findOrFail($attempt->operation_id)->update(['status' => 'verification_pending']);

        $this->runExpiredAttemptDetector();

        $this->assertSame('result_received', $attempt->fresh()->status);
        $this->assertSame('verification_pending', Operation::findOrFail($attempt->operation_id)->status);
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->whereIn('action', ['attempt_timeout', 'operation_unknown'])->count());
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'operation_dead_lettered')->count());
    }

    public function test_terminal_attempts_are_ignored_by_timeout_detector(): void
    {
        foreach (['succeeded', 'failed', 'timeout', 'rejected'] as $status) {
            [, $attempt] = $this->createDispatchedJob('timeout-terminal-'.$status);
            $attempt->update([
                'status' => $status,
                'timeout_at' => now()->subSecond(),
                'error_code' => 'preserve-me',
                'finished_at' => now()->subMinute(),
            ]);

            $this->runExpiredAttemptDetector();

            $attempt->refresh();
            $this->assertSame($status, $attempt->status);
            $this->assertSame('preserve-me', $attempt->error_code);
            $this->assertSame(0, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_timeout')->count());
        }
    }

    public function test_terminal_operations_are_not_overwritten_by_expired_accepted_attempts(): void
    {
        foreach (['cancelled', 'unknown', 'succeeded', 'failed', 'dead_letter'] as $status) {
            [, $attempt] = $this->createClaimedJob('timeout-terminal-operation-'.$status);
            $attempt->update(['timeout_at' => now()->subSecond()]);
            Operation::findOrFail($attempt->operation_id)->update(['status' => $status]);

            $this->runExpiredAttemptDetector();

            $this->assertSame('accepted', $attempt->fresh()->status);
            $this->assertSame($status, Operation::findOrFail($attempt->operation_id)->status);
            $this->assertSame(0, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->whereIn('action', ['attempt_timeout', 'operation_unknown'])->count());
        }
    }

    public function test_attempt_before_timeout_at_is_not_processed(): void
    {
        [, $attempt] = $this->createDispatchedJob('timeout-not-yet-expired-token');
        $attempt->update(['timeout_at' => now()->addMinute()]);

        $this->runExpiredAttemptDetector();

        $this->assertSame('dispatched', $attempt->fresh()->status);
        $this->assertTrue($attempt->fresh()->retryable === false);
        $this->assertSame('running', Operation::findOrFail($attempt->operation_id)->status);
        $this->assertSame(0, \App\Models\AuditLog::where('target_id', $attempt->operation_id)->where('action', 'attempt_timeout')->count());
    }

    private function runExpiredAttemptDetector(): void
    {
        (new \App\Jobs\DetectExpiredOperationAttempts())->handle(
            app(\App\Services\OperationRetryClassifier::class),
            app(\App\Services\OperationRetryPreconditions::class),
            app(\App\Services\PolicyEngine::class),
        );
    }

    private function createApprovedDispatchedOperation(string $key): array
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($user);
        $connection = $this->createConnectorWithToken($site, $key.'-token');
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/sites/'.$site->id.'/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => $key,
        ]);
        $response->assertCreated()->assertJsonPath('data.status', Operation::STATUS_PENDING_APPROVAL);
        $operation = Operation::findOrFail($response->json('data.id'));
        $operation->approvalRequest->update([
            'status' => \App\Models\ApprovalRequest::STATUS_APPROVED,
            'reviewed_by' => User::factory()->create()->id,
            'reviewed_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('operations')->where('id', $operation->id)->update([
            'status' => Operation::STATUS_QUEUED,
            'policy_result' => 'approved',
        ]);

        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());
        \App\Jobs\DispatchOperationJob::dispatch($operation->id);
        $attempt = OperationAttempt::where('operation_id', $operation->id)->firstOrFail();

        return [$site, $operation->fresh(), $attempt->fresh()];
    }

    public function test_dispatching_pending_approval_operation_does_not_create_attempt(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($user);
        $this->createActiveConnectionWithCapability($site);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'dispatch-pending-approval',
        ]);
        $response->assertStatus(201)->assertJsonPath('data.status', Operation::STATUS_PENDING_APPROVAL);
        $operation = Operation::findOrFail($response->json('data.id'));

        (new \App\Jobs\DispatchOperationJob($operation->id))->handle(
            $this->app->make(MaintenanceLock::class),
            $this->app->make(\App\Services\PolicyEngine::class),
        );

        $this->assertDatabaseCount('operation_attempts', 0);
        $this->assertDatabaseHas('operations', [
            'id' => $operation->id,
            'status' => Operation::STATUS_PENDING_APPROVAL,
        ]);
    }

    private function createDispatchedJob(string $token = 'site-a-token'): array
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
            'require_approval' => false,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($user);
        $connection = $this->createConnectorWithToken($site, $token);
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => uniqid('poll-', true),
        ]);
        $response->assertStatus(201);

        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());
        \App\Jobs\DispatchOperationJob::dispatch($response->json('data.id'));
        $attempt = OperationAttempt::where('operation_id', $response->json('data.id'))->firstOrFail();

        return [$site, $attempt->fresh(), $attempt->connector_job_id, $token];
    }

    public function test_authenticated_connector_can_poll_its_own_available_job(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/connector/jobs');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.job_id', $jobId)
            ->assertJsonPath('data.0.operation_id', $attempt->operation_id)
            ->assertJsonPath('data.0.operation_type', 'action.cache_clear');
    }

    public function test_connector_cannot_poll_job_belonging_to_another_site(): void
    {
        [$site, $attempt, $jobId] = $this->createDispatchedJob();
        [, , $otherSite] = $this->createUserWithSite();
        $otherToken = 'site-b-token';
        $this->createConnectorWithToken($otherSite, $otherToken);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])
            ->getJson('/api/v1/connector/jobs');

        $response->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_unauthenticated_connector_cannot_poll_jobs(): void
    {
        $response = $this->getJson('/api/v1/connector/jobs');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_invalid_connector_token_cannot_poll_jobs(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer invalid-connector-token'])
            ->getJson('/api/v1/connector/jobs');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_claimed_job_is_not_returned_as_an_available_job(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('poll-claimed-token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/connector/jobs');

        $response->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_pending_approval_operation_is_not_exposed_through_polling(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($user);
        $token = 'pending-poll-token';
        $connection = $this->createConnectorWithToken($site, $token);
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => 'pending-poll-operation',
        ]);
        $response->assertStatus(201)->assertJsonPath('data.status', Operation::STATUS_PENDING_APPROVAL);

        $pollResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/connector/jobs');

        $pollResponse->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_terminal_operation_is_not_exposed_through_polling(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('terminal-poll-token');
        Operation::findOrFail($attempt->operation_id)->update(['status' => Operation::STATUS_SUCCEEDED]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/connector/jobs');

        $response->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_authenticated_connector_can_claim_its_own_available_job(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-own-job-token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(200)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.operation_id', $attempt->operation_id)
            ->assertJsonPath('data.attempt_number', $attempt->attempt_number)
            ->assertJsonPath('data.operation_type', 'action.cache_clear')
            ->assertJsonStructure([
                'data' => ['job_id', 'operation_id', 'attempt_number', 'operation_type', 'target_json', 'idempotency_key', 'lock_token'],
            ]);

        $attempt->refresh();
        $this->assertSame(OperationAttempt::STATUS_ACCEPTED, $attempt->status);
        $this->assertSame($jobId, $attempt->connector_job_id);
    }

    public function test_same_connector_claiming_same_job_twice_is_idempotent(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-idempotent-token');
        $headers = ['Authorization' => 'Bearer ' . $token];

        $firstResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');
        $secondResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $firstResponse->assertStatus(200);
        $secondResponse->assertStatus(200);
        $this->assertSame($firstResponse->json('data'), $secondResponse->json('data'));
        $this->assertDatabaseCount('operation_attempts', 1);
        $this->assertSame(OperationAttempt::STATUS_ACCEPTED, $attempt->fresh()->status);
    }

    public function test_connector_from_another_site_cannot_claim_job(): void
    {
        [$site, $attempt, $jobId] = $this->createDispatchedJob('claim-own-site-token');
        [, , $otherSite] = $this->createUserWithSite();
        $otherToken = 'claim-other-site-token';
        $this->createConnectorWithToken($otherSite, $otherToken);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_invalid_connector_token_cannot_claim_job(): void
    {
        [$site, $attempt, $jobId] = $this->createDispatchedJob('claim-valid-token');

        $response = $this->withHeaders(['Authorization' => 'Bearer invalid-connector-token'])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_connector_without_required_capability_cannot_claim_job(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-no-capability-token');
        $connection = SiteConnection::where('site_id', $site->id)->firstOrFail();
        $connection->capabilities()->delete();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(403)->assertJsonPath('error.code', 'capability_denied');
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_connector_with_disabled_capability_cannot_claim_job(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-disabled-capability-token');
        $connection = SiteConnection::where('site_id', $site->id)->firstOrFail();
        $connection->capabilities()->update(['enabled' => false]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(403)->assertJsonPath('error.code', 'capability_denied');
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_revoked_connector_cannot_claim_job(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-revoked-token');
        $connection = SiteConnection::where('site_id', $site->id)->firstOrFail();
        $connection->revoke();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_inactive_connector_cannot_claim_job(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-inactive-token');
        $connection = SiteConnection::where('site_id', $site->id)->firstOrFail();
        $connection->update(['status' => 'inactive']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_already_claimed_job_cannot_be_claimed_by_another_connector(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-original-token');
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim')
            ->assertStatus(200);

        $otherToken = 'claim-another-connector-token';
        $otherConnection = $this->createConnectorWithToken($site, $otherToken);
        ConnectorCapability::create([
            'site_connection_id' => $otherConnection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(409);
        $this->assertSame(OperationAttempt::STATUS_ACCEPTED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_unknown_job_cannot_be_claimed(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-unknown-token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/unknown-job/claim');

        $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->assertSame(OperationAttempt::STATUS_DISPATCHED, $attempt->fresh()->status);
        $this->assertDatabaseCount('operation_attempts', 1);
    }

    public function test_claiming_job_does_not_create_second_operation_attempt(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-attempt-count-token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim');

        $response->assertStatus(200);
        $this->assertDatabaseCount('operation_attempts', 1);
        $this->assertSame(1, $attempt->fresh()->attempt_number);
    }

    public function test_successful_claim_records_job_claimed_audit_event(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createDispatchedJob('claim-audit-token');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim')
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'job_claimed',
            'target_type' => 'operation',
            'target_id' => $attempt->operation_id,
        ]);
    }

    private function createClaimedJob(string $token = 'site-a-token'): array
    {
        [$user, $organization, $site] = $this->createUserWithSite();
        $organization->update(['approval_policy' => [
            'require_approval' => false,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($user);
        $connection = $this->createConnectorWithToken($site, $token);
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/sites/' . $site->id . '/operations', [
            'operation_type' => 'action.cache_clear',
            'idempotency_key' => uniqid('result-', true),
        ]);
        $response->assertStatus(201);

        $this->app->instance(MaintenanceLock::class, $this->getFakeMaintenanceLock());
        \App\Jobs\DispatchOperationJob::dispatch($response->json('data.id'));
        $attempt = OperationAttempt::where('operation_id', $response->json('data.id'))->firstOrFail();
        $jobId = $attempt->connector_job_id;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/claim')
            ->assertStatus(200);

        return [$site, $attempt->fresh(), $jobId, $token];
    }

    private function successPayload(): array
    {
        return [
            'status' => 'success',
            'cache_cleared_at' => '2026-09-24T12:00:00+00:00',
            'cleared_types' => ['file'],
            'cache_generation' => 'v2',
        ];
    }

    private function failedPayload(): array
    {
        return [
            'status' => 'failed',
            'error_code' => 'CACHE_CLEAR_FAILED',
            'error_message' => 'Unable to clear cache',
        ];
    }

    private function getFakeMaintenanceLock(): MaintenanceLock
    {
        $lock = \Mockery::mock(MaintenanceLock::class);
        $lock->shouldReceive('acquire')->andReturn(true);
        $lock->shouldReceive('release')->andReturn(true);

        return $lock;
    }

    public function test_successful_result_submission_returns_200_and_persists_result(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $this->successPayload());

        $response->assertStatus(200)->assertJsonPath('data.result_status', 'success');
        $this->assertDatabaseHas('operation_results', [
            'operation_id' => $attempt->operation_id,
            'operation_attempt_id' => $attempt->id,
            'connector_job_id' => $jobId,
            'result_status' => 'success',
            'cache_generation' => 'v2',
        ]);
    }

    public function test_failed_result_is_accepted_without_finally_failing_operation(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $this->failedPayload())
            ->assertStatus(200)
            ->assertJsonPath('data.result_status', 'failed');

        $this->assertDatabaseHas('operations', [
            'id' => $attempt->operation_id,
            'status' => Operation::STATUS_VERIFICATION_PENDING,
        ]);
        $this->assertDatabaseHas('operation_results', [
            'operation_attempt_id' => $attempt->id,
            'result_status' => 'failed',
            'error_code' => 'CACHE_CLEAR_FAILED',
        ]);
    }

    public function test_result_submission_updates_attempt_and_operation_and_creates_audit_event(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $this->successPayload())
            ->assertStatus(200);

        $this->assertDatabaseHas('operation_attempts', [
            'id' => $attempt->id,
            'status' => OperationAttempt::STATUS_RESULT_RECEIVED,
        ]);
        $this->assertDatabaseHas('operations', [
            'id' => $attempt->operation_id,
            'status' => Operation::STATUS_VERIFICATION_PENDING,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'result_received',
            'target_id' => $attempt->operation_id,
        ]);
    }

    public function test_conflicting_duplicate_result_is_rejected(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob();
        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->withHeaders($headers)->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $this->successPayload())->assertStatus(200);
        $this->withHeaders($headers)->postJson('/api/v1/connector/jobs/' . $jobId . '/result', [
            'status' => 'failed',
            'error_code' => 'DIFFERENT_RESULT',
        ])->assertStatus(409);
        $this->assertDatabaseCount('operation_results', 1);
    }

    public function test_wrong_site_connector_cannot_submit_result(): void
    {
        [$site, $attempt, $jobId] = $this->createClaimedJob();
        [, , $otherSite] = $this->createUserWithSite();
        $otherToken = 'site-b-token';
        $this->createConnectorWithToken($otherSite, $otherToken);

        $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $this->successPayload())
            ->assertStatus(404);
    }

    public function test_mismatched_connector_job_id_is_rejected(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/not-the-job/result', $this->successPayload())
            ->assertStatus(404);
    }

    public function test_result_submission_requires_valid_connector_authentication(): void
    {
        [$site, $attempt, $jobId] = $this->createClaimedJob();

        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $this->successPayload())
            ->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer invalid-connector-token-' . uniqid()])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $this->successPayload())
            ->assertStatus(401);
    }

    public function test_already_result_received_job_is_idempotent(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob();
        $payload = $this->successPayload();

        $firstResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $payload);
        $secondResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/connector/jobs/' . $jobId . '/result', $payload);

        $firstResponse->assertStatus(200);
        $secondResponse->assertStatus(200);
        $this->assertEquals($firstResponse->json('data.job_id'), $secondResponse->json('data.job_id'));
        $this->assertDatabaseCount('operation_results', 1);
    }

    public function test_authoritative_state_is_stored_pending_and_queued_for_verification(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-submit-token');
        Queue::fake();
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $state = $this->authoritativeState();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state);
        $response->assertStatus(202)->assertJsonPath('data.verification_status', OperationResult::VERIFICATION_PENDING);
        $this->assertEquals($state, OperationResult::firstOrFail()->actual_state_json);
        $this->assertSame(OperationResult::VERIFICATION_PENDING, OperationResult::firstOrFail()->verification_status);
        $this->assertSame(Operation::STATUS_VERIFICATION_PENDING, Operation::findOrFail($attempt->operation_id)->status);
        Queue::assertPushed(\App\Jobs\VerifyOperationAttempt::class);
        $claimResult = OperationResult::firstOrFail();
        $this->assertSame('success', $claimResult->result_status);
        $this->assertSame('v2', $claimResult->cache_generation);
        $this->assertSame(['file'], $claimResult->cleared_types);
        $this->assertSame('2026-09-24T12:00:00+00:00', $claimResult->cache_cleared_at->toIso8601String());
        $this->assertNull($claimResult->error_code);
        $this->assertNull($claimResult->error_message);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->operation_id, 'action' => 'result_received']);
    }

    public function test_duplicate_authoritative_state_is_idempotent_and_conflict_is_rejected(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-idempotent-token');
        Queue::fake();
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $state = $this->authoritativeState();
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->withHeaders($headers)->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(202);
        $this->withHeaders($headers)->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertOk();
        Queue::assertPushedTimes(\App\Jobs\VerifyOperationAttempt::class, 1);
        $state['cache_generation'] = 'different';
        $this->withHeaders($headers)->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(409);
        $this->assertDatabaseCount('operation_results', 1);
    }

    public function test_identical_pending_state_recovers_missing_queue_dispatch_without_duplicate_jobs(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-dispatch-recovery-token');
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $state = $this->authoritativeState();
        $attempt->result->update([
            'actual_state_json' => $state,
            'verification_status' => OperationResult::VERIFICATION_PENDING,
        ]);
        Queue::fake();

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->withHeaders($headers)->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertOk();
        Queue::assertPushedTimes(\App\Jobs\VerifyOperationAttempt::class, 1);
    }

    public function test_state_submission_requires_own_claim_and_read_capability(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-capability-token');
        Queue::fake();
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $connection = SiteConnection::where('site_id', $site->id)->firstOrFail();
        $connection->capabilities()->where('capability_key', 'action.cache_clear')->update(['capability_key' => 'other']);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $this->authoritativeState())->assertStatus(403);
        $this->assertNull(OperationResult::firstOrFail()->actual_state_json);
    }

    public function test_successful_verification_transitions_operation_and_attempt(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-job-token');
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $state = $this->authoritativeState();
        $state['read_at'] = now('UTC')->addSeconds(10)->toIso8601String();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(202);
        $attempt->refresh();
        $this->assertSame(OperationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(Operation::STATUS_SUCCEEDED, Operation::findOrFail($attempt->operation_id)->status);
        $this->assertSame(OperationResult::VERIFICATION_PASSED, $attempt->result->verification_status);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->operation_id, 'action' => 'verification_started']);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->operation_id, 'action' => 'verification_verified']);
    }

    public function test_uncertain_verification_failure_does_not_mark_remote_attempt_failed(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-failure-job-token');
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $state = $this->authoritativeState();
        $state['cache_generation'] = 'not-v2';
        $state['read_at'] = now('UTC')->addSeconds(10)->toIso8601String();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(202);

        $attempt->refresh();
        $this->assertSame(OperationAttempt::STATUS_RESULT_RECEIVED, $attempt->status);
        $this->assertSame(OperationResult::VERIFICATION_FAILED, $attempt->result->verification_status);
        $this->assertSame(OperationResult::VERIFICATION_ERROR_CACHE_GENERATION_MISMATCH, $attempt->result->verification_error);
        $this->assertSame(Operation::STATUS_VERIFICATION_PENDING, Operation::findOrFail($attempt->operation_id)->status);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->operation_id, 'action' => 'verification_started']);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->operation_id, 'action' => 'verification_failed']);
    }

    public function test_deterministic_cache_error_marks_attempt_failed_without_failing_operation_aggregate(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-cache-error-job-token');
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $state = $this->authoritativeState();
        $state['read_at'] = now('UTC')->addSeconds(10)->toIso8601String();
        $state['cache_state']['page_cache'] = 'error';
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(202);

        $attempt->refresh();
        $this->assertSame(OperationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('cache_error', $attempt->error_code);
        $this->assertSame(OperationResult::VERIFICATION_FAILED, $attempt->result->verification_status);
        $this->assertSame(Operation::STATUS_VERIFICATION_PENDING, Operation::findOrFail($attempt->operation_id)->status);
    }

    public function test_verification_job_does_not_overwrite_operation_that_became_terminal(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-terminal-job-token');
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $attempt->result->update([
            'actual_state_json' => $this->authoritativeState(),
            'verification_status' => OperationResult::VERIFICATION_PENDING,
        ]);
        $attempt->refresh()->update(['status' => OperationAttempt::STATUS_ACCEPTED]);
        (new \App\Jobs\VerifyOperationAttempt($attempt->id))->handle(app(\App\Services\VerificationEngine::class));
        $attempt->refresh();
        $this->assertSame(OperationAttempt::STATUS_ACCEPTED, $attempt->status);
        $this->assertSame(OperationResult::VERIFICATION_PENDING, $attempt->result->verification_status);

        $attempt->update(['status' => OperationAttempt::STATUS_RESULT_RECEIVED]);
        Operation::findOrFail($attempt->operation_id)->update(['status' => Operation::STATUS_CANCELLED]);

        (new \App\Jobs\VerifyOperationAttempt($attempt->id))->handle(app(\App\Services\VerificationEngine::class));

        $attempt->refresh();
        $this->assertSame(Operation::STATUS_CANCELLED, Operation::findOrFail($attempt->operation_id)->status);
        $this->assertSame(OperationAttempt::STATUS_RESULT_RECEIVED, $attempt->status);
        $this->assertSame(OperationResult::VERIFICATION_FAILED, $attempt->result->verification_status);
        $this->assertSame(OperationResult::VERIFICATION_ERROR_OPERATION_STATE_CHANGED, $attempt->result->verification_error);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->operation_id, 'action' => 'verification_failed']);
    }

    public function test_state_submission_rejects_wrong_site_job_inactive_and_terminal_states(): void
    {
        [$site, $attempt, $jobId, $token] = $this->createClaimedJob('state-guard-token');
        $this->grantCacheStateRead($site);
        Operation::findOrFail($attempt->operation_id)->update(['target_json' => ['cache_type' => 'wordpress']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/result', $this->successPayload())->assertOk();
        $state = $this->authoritativeState();

        $attempt->refresh()->update(['status' => OperationAttempt::STATUS_ACCEPTED]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(409);
        $attempt->update(['status' => OperationAttempt::STATUS_RESULT_RECEIVED]);

        $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/not-the-job/state', $state)->assertNotFound();
        [, , $otherSite] = $this->createUserWithSite();
        $otherToken = 'state-other-site-token';
        $this->createConnectorWithToken($otherSite, $otherToken);
        $this->withHeaders(['Authorization' => 'Bearer '.$otherToken])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertNotFound();
        $sameSiteToken = 'state-different-connector-token';
        $otherConnection = $this->createConnectorWithToken($site, $sameSiteToken);
        ConnectorCapability::create([
            'site_connection_id' => $otherConnection->id,
            'capability_key' => 'read.cache_state',
            'enabled' => true,
            'discovered_at' => now(),
        ]);
        $this->withHeaders(['Authorization' => 'Bearer '.$sameSiteToken])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(409);

        Operation::findOrFail($attempt->operation_id)->update(['status' => Operation::STATUS_SUCCEEDED]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(409);
        Operation::findOrFail($attempt->operation_id)->update(['status' => Operation::STATUS_VERIFICATION_PENDING]);
        $attempt->result->update(['verification_status' => OperationResult::VERIFICATION_PASSED]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertStatus(409);
        $attempt->result->update(['verification_status' => null]);

        $connection = SiteConnection::where('site_id', $site->id)->firstOrFail();
        $connection->update(['status' => 'inactive']);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertUnauthorized();
        $connection->update(['status' => 'active']);
        $connection->revoke();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/connector/jobs/'.$jobId.'/state', $state)->assertUnauthorized();
    }

    private function authoritativeState(): array
    {
        return [
            'read_at' => now('UTC')->addSeconds(10)->toIso8601String(),
            'cache_generation' => 'v2',
            'cleared_types' => [
                'object_cache', 'page_cache', 'transient_cache', 'rewrite_cache', 'file_cache', 'opcache',
            ],
            'cache_state' => [
                'object_cache' => 'cleared',
                'page_cache' => 'cleared',
                'transient_cache' => 'cleared',
                'rewrite_cache' => 'cleared',
                'file_cache' => 'cleared',
                'opcache' => 'cleared',
            ],
            'wp_version' => '6.8',
            'connector_version' => '1.0.0',
        ];
    }

    private function grantCacheStateRead(Site $site): void
    {
        $connection = SiteConnection::where('site_id', $site->id)->firstOrFail();
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'read.cache_state',
            'enabled' => true,
            'discovered_at' => now(),
        ]);
    }
}
