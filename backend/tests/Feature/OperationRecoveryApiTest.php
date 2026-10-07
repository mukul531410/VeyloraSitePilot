<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\ConnectorCapability;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class OperationRecoveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_returns_new_successor_and_preserves_source_without_dispatch(): void
    {
        [$actor, , $site, $source] = $this->makeRecoverable();
        $before = $source->fresh()->only(['status', 'idempotency_key', 'operation_type', 'target_json', 'requested_by']);
        $attemptIds = $source->attempts()->pluck('id')->all();
        Sanctum::actingAs($actor);
        Queue::fake();

        $response = $this->withHeader('X-Request-ID', 'recovery-request-1')
            ->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'api-recovery-key']);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'recovery_of_operation_id', 'site_id', 'operation_type', 'status', 'approval_required', 'idempotency_key'], 'meta', 'request_id'])
            ->assertJsonPath('request_id', 'recovery-request-1')
            ->assertJsonPath('data.recovery_of_operation_id', $source->id)
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.operation_type', $source->operation_type)
            ->assertJsonPath('data.target_json', $source->target_json)
            ->assertJsonPath('data.status', Operation::STATUS_QUEUED)
            ->assertJsonPath('data.idempotency_key', 'api-recovery-key')
            ->assertJsonPath('data.requested_by', $actor->id);

        $successorId = $response->json('data.id');
        $this->assertNotSame($source->id, $successorId);
        $this->assertSame($before, $source->fresh()->only(array_keys($before)));
        $this->assertSame($attemptIds, $source->fresh()->attempts()->pluck('id')->all());
        $this->assertDatabaseHas('operations', ['id' => $successorId, 'recovery_of_operation_id' => $source->id]);
        $this->assertSame(0, Operation::where('recovery_of_operation_id', $successorId)->count());
        Queue::assertNothingPushed();
        $this->assertSame(1, AuditLog::where('action', 'operation_retry_requested')->count());
    }

    public function test_current_approval_policy_creates_new_successor_approval_and_preserves_source_approval(): void
    {
        [$actor, $organization, , $source] = $this->makeRecoverable();
        $sourceApproval = $this->makeApproval($source, ApprovalRequest::STATUS_APPROVED);
        $source->update(['approval_required' => true]);
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'approval-api-key']);

        $response->assertCreated()
            ->assertJsonPath('data.status', Operation::STATUS_PENDING_APPROVAL)
            ->assertJsonPath('data.approval_required', true)
            ->assertJsonPath('data.approval.status', ApprovalRequest::STATUS_PENDING);
        $successor = Operation::findOrFail($response->json('data.id'));
        $this->assertNotSame($sourceApproval->id, $successor->approvalRequest->id);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $sourceApproval->fresh()->status);
        $this->assertSame($source->id, $source->fresh()->id);
        $this->assertSame(2, ApprovalRequest::count());
    }

    public function test_policy_without_approval_queues_successor_without_approval_request(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'queued-api-key']);

        $response->assertCreated()->assertJsonPath('data.status', Operation::STATUS_QUEUED);
        $this->assertDatabaseMissing('approval_requests', ['operation_id' => $response->json('data.id')]);
    }

    public function test_same_recovery_request_replay_returns_the_same_successor(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        Sanctum::actingAs($actor);
        $payload = ['idempotency_key' => 'replay-api-key'];

        $first = $this->postJson('/api/v1/operations/'.$source->id.'/retry', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/operations/'.$source->id.'/retry', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Operation::where('recovery_of_operation_id', $source->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'operation_retry_requested')->count());
    }

    public function test_same_key_for_a_different_source_conflicts(): void
    {
        [$actor, , $site, $sourceA] = $this->makeRecoverable();
        $sourceB = $this->createDeadLetterSource($actor, $site);
        Sanctum::actingAs($actor);
        $payload = ['idempotency_key' => 'cross-source-api-key'];

        $this->postJson('/api/v1/operations/'.$sourceA->id.'/retry', $payload)->assertCreated();
        $this->withHeader('X-Request-ID', 'conflict-request-1')
            ->postJson('/api/v1/operations/'.$sourceB->id.'/retry', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict')
            ->assertJsonPath('request_id', 'conflict-request-1');
    }

    public function test_same_key_with_different_logical_successor_request_conflicts(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        Sanctum::actingAs($actor);
        Operation::create([
            'site_id' => $source->site_id,
            'operation_type' => 'action.other',
            'target_json' => ['different' => true],
            'status' => Operation::STATUS_QUEUED,
            'approval_required' => false,
            'idempotency_key' => 'logical-conflict-key',
            'requested_by' => $actor->id,
        ]);

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'logical-conflict-key'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_source_original_idempotency_key_is_rejected_for_recovery(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => $source->idempotency_key])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_different_key_after_source_has_successor_conflicts(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'first-api-key'])->assertCreated();

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'second-api-key'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'recovery_conflict');
    }

    public function test_viewer_operator_and_unknown_roles_are_denied(): void
    {
        foreach (['viewer', 'operator', 'unknown'] as $roleKey) {
            [$actor, , , $source] = $this->makeRecoverable($roleKey);
            Sanctum::actingAs($actor);

            $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'denied-'.$roleKey])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'unauthorized');
        }
    }

    public function test_active_admin_can_request_recovery(): void
    {
        [$actor, , , $source] = $this->makeRecoverable('admin');
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'admin-recovery-key'])
            ->assertCreated()
            ->assertJsonPath('data.recovery_of_operation_id', $source->id);
    }

    public function test_endpoint_requires_authentication(): void
    {
        [, , , $source] = $this->makeRecoverable();

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'unauthenticated-key'])
            ->assertUnauthorized();
    }

    public function test_non_dead_letter_source_is_denied_with_conflict_status(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        $source->update(['status' => Operation::STATUS_QUEUED]);
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'invalid-state-key'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'source_not_dead_letter');
    }

    public function test_recovery_chain_limit_is_enforced(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        config(['sitepilot.operations.max_recovery_chain_length' => 1]);
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'chain-limit-api-key'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'recovery_chain_limit_reached');
        $this->assertSame(1, Operation::count());
    }

    public function test_cross_organization_operation_is_hidden_as_not_found(): void
    {
        [$actor] = $this->makeRecoverable();
        [$foreignOwner, , , $foreignSource] = $this->makeRecoverable();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/operations/'.$foreignSource->id.'/retry', ['idempotency_key' => 'foreign-key'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_invalid_payload_is_rejected_and_operation_fields_are_not_accepted(): void
    {
        [$actor, , , $source] = $this->makeRecoverable();
        Sanctum::actingAs($actor);

        $this->withHeader('X-Request-ID', 'validation-request-1')
            ->postJson('/api/v1/operations/'.$source->id.'/retry', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.idempotency_key.0', 'The idempotency key field is required.')
            ->assertJsonPath('request_id', 'validation-request-1')
            ->assertHeader('X-Request-ID', 'validation-request-1');

        $this->postJson('/api/v1/operations/'.$source->id.'/retry', [
            'idempotency_key' => 'no-client-operation-fields',
            'operation_type' => 'action.other',
            'target_json' => ['cache_type' => 'object'],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.operation_type.0', 'The operation type field must be missing.')
            ->assertJsonPath('error.details.target_json.0', 'The target json field must be missing.');
    }

    public function test_audit_failure_returns_server_error_without_partial_recovery_records(): void
    {
        [$actor, $organization, , $source] = $this->makeRecoverable();
        $this->makeApproval($source, ApprovalRequest::STATUS_APPROVED);
        $source->update(['approval_required' => true]);
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($actor);
        Event::listen('eloquent.creating: App\\Models\\AuditLog', function (): void {
            throw new RuntimeException('synthetic audit failure');
        });

        try {
            $this->postJson('/api/v1/operations/'.$source->id.'/retry', ['idempotency_key' => 'atomic-api-key'])
                ->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: App\\Models\\AuditLog');
        }

        $this->assertSame(1, Operation::count());
        $this->assertSame(1, ApprovalRequest::count());
        $this->assertSame(0, AuditLog::where('action', 'operation_retry_requested')->count());
        $this->assertDatabaseMissing('operations', ['idempotency_key' => 'atomic-api-key']);
    }

    private function makeRecoverable(string $roleKey = 'owner'): array
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create([
            'approval_policy' => [
                'require_approval' => false,
                'high_criticality_requires_approval' => false,
            ],
        ]);
        $role = Role::create(['organization_id' => $organization->id, 'name' => ucfirst($roleKey), 'key' => $roleKey]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active', 'revoked_at' => null]);
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'reported_supported' => true,
            'reported_at' => now(),
            'discovered_at' => now(),
        ]);
        $source = $this->createDeadLetterSource($actor, $site);

        return [$actor, $organization, $site, $source, $connection];
    }

    private function createDeadLetterSource(User $actor, Site $site): Operation
    {
        $source = Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_DEAD_LETTER,
            'approval_required' => false,
            'idempotency_key' => 'source-'.$site->id.'-'.str()->ulid(),
            'max_attempts' => 1,
            'requested_by' => $actor->id,
        ]);
        OperationAttempt::create([
            'operation_id' => $source->id,
            'attempt_number' => 1,
            'status' => OperationAttempt::STATUS_TIMEOUT,
            'connector_job_id' => str()->ulid(),
            'retryable' => true,
            'timeout_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_code' => OperationAttempt::ERROR_TIMEOUT,
            'claimed_by_connection_id' => null,
        ]);

        return $source;
    }

    private function makeApproval(Operation $operation, string $status): ApprovalRequest
    {
        return ApprovalRequest::create([
            'organization_id' => $operation->site->organization_id,
            'site_id' => $operation->site_id,
            'operation_id' => $operation->id,
            'status' => $status,
            'requested_by' => $operation->requested_by,
            'reason' => 'Original operation approval',
            'expires_at' => now()->addDay(),
            'reviewed_at' => $status === ApprovalRequest::STATUS_APPROVED ? now() : null,
        ]);
    }
}
