<?php

namespace Tests\Feature;

use App\Models\ConnectorCapability;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\MaintenanceLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'target_json' => ['cache_type' => 'full'],
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

    public function test_same_idempotency_key_different_request_returns_409(): void
    {
        [$user, $organization, $site] = $this->createUserWithSite();
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
            'target_json' => ['cache_type' => 'partial'],
        ]);

        $response2->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
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
        $attempt = OperationAttempt::firstOrFail();
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
}