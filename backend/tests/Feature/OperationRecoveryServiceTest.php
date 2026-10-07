<?php

namespace Tests\Feature;

use App\Exceptions\OperationRecoveryChainException;
use App\Exceptions\OperationRecoveryException;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
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
use App\Services\OperationRecoveryAuthorization;
use App\Services\OperationRecoveryChain;
use App\Services\OperationRecoveryPreconditions;
use App\Services\OperationRecoveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class OperationRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_one_fresh_successor_and_leaves_the_source_unchanged(): void
    {
        [$actor, $source] = $this->makeRecoverable(2);
        $attemptIds = $source->attempts()->orderBy('attempt_number')->pluck('id')->all();
        $sourceBefore = $source->fresh()->only([
            'status', 'idempotency_key', 'operation_type', 'target_json', 'approval_required',
            'requested_by', 'started_at', 'finished_at',
        ]);
        $sourceUpdatedAt = $source->fresh()->updated_at->toDateTimeString();

        $successor = $this->service()->createSuccessor($actor, $source, 'recovery-key-1', 'request-correlation-1');

        $this->assertNotSame($source->id, $successor->id);
        $this->assertSame($source->id, $successor->recovery_of_operation_id);
        $this->assertSame('recovery-key-1', $successor->idempotency_key);
        $this->assertSame($source->site_id, $successor->site_id);
        $this->assertSame($source->operation_type, $successor->operation_type);
        $this->assertSame($source->target_json, $successor->target_json);
        $this->assertSame(Operation::STATUS_QUEUED, $successor->status);
        $this->assertSame(3, $successor->max_attempts);
        $this->assertSame($actor->id, $successor->requested_by);
        $this->assertSame(1, Operation::where('recovery_of_operation_id', $source->id)->count());
        $this->assertSame([], $successor->attempts()->pluck('id')->all());
        $this->assertSame($attemptIds, $source->fresh()->attempts()->orderBy('attempt_number')->pluck('id')->all());
        $this->assertSame($sourceBefore, $source->fresh()->only(array_keys($sourceBefore)));
        $this->assertSame($sourceUpdatedAt, $source->fresh()->updated_at->toDateTimeString());
        $this->assertSame(1, AuditLog::where('action', 'operation_retry_requested')->count());

        $audit = AuditLog::where('action', 'operation_retry_requested')->firstOrFail();
        $this->assertSame('request-correlation-1', $audit->correlation_id);
        $this->assertSame($successor->id, $audit->target_id);
        $this->assertSame($source->id, $audit->metadata_json['source_operation_id']);
        $this->assertSame($successor->id, $audit->metadata_json['successor_operation_id']);
        $this->assertSame($actor->id, $audit->metadata_json['recovery_actor_id']);
        $this->assertSame(2, $audit->metadata_json['recovery_chain_position']);
    }

    public function test_current_policy_without_approval_queues_successor_without_approval_request(): void
    {
        [$actor, $source] = $this->makeRecoverable();

        $successor = $this->service()->createSuccessor($actor, $source, 'no-approval-key');

        $this->assertSame(Operation::STATUS_QUEUED, $successor->status);
        $this->assertFalse($successor->approval_required);
        $this->assertSame('allowed', $successor->policy_result);
        $this->assertDatabaseMissing('approval_requests', ['operation_id' => $successor->id]);
    }

    public function test_current_policy_with_approval_creates_a_new_approval_for_successor_only(): void
    {
        [$actor, $source, $organization] = $this->makeRecoverable();
        $originalApproval = $this->makeApproval($source, ApprovalRequest::STATUS_APPROVED);
        $source->update(['approval_required' => true]);
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        $sourceBefore = $source->fresh()->only(['status', 'approval_required']);
        $sourceUpdatedAt = $source->fresh()->updated_at->toDateTimeString();

        $successor = $this->service()->createSuccessor($actor, $source, 'approval-key');
        $newApproval = $successor->approvalRequest()->firstOrFail();

        $this->assertSame(Operation::STATUS_PENDING_APPROVAL, $successor->status);
        $this->assertTrue($successor->approval_required);
        $this->assertSame('pending_approval', $successor->policy_result);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $newApproval->status);
        $this->assertSame($actor->id, $newApproval->requested_by);
        $this->assertSame($successor->id, $newApproval->operation_id);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $originalApproval->fresh()->status);
        $this->assertSame($sourceBefore, $source->fresh()->only(array_keys($sourceBefore)));
        $this->assertSame($sourceUpdatedAt, $source->fresh()->updated_at->toDateTimeString());
        $this->assertSame(2, ApprovalRequest::count());
    }

    public function test_recovery_actor_is_requested_by_even_when_different_from_original_requester(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        $otherActor = User::factory()->create();
        $role = Role::where('organization_id', $source->site->organization_id)->where('key', 'owner')->firstOrFail();
        OrganizationMember::create([
            'organization_id' => $source->site->organization_id,
            'user_id' => $otherActor->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $source->update(['requested_by' => $actor->id]);

        $successor = $this->service()->createSuccessor($otherActor, $source, 'different-requester-key');

        $this->assertSame($otherActor->id, $successor->requested_by);
        $this->assertSame($actor->id, $source->fresh()->requested_by);
    }

    public function test_same_recovery_key_and_request_replays_the_same_successor(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        $first = $this->service()->createSuccessor($actor, $source, 'replay-key');

        $replayed = $this->service()->createSuccessor($actor, $source->fresh(), 'replay-key');

        $this->assertSame($first->id, $replayed->id);
        $this->assertSame(1, Operation::where('recovery_of_operation_id', $source->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'operation_retry_requested')->count());
    }

    public function test_recovery_key_conflicts_with_different_target_or_operation_type(): void
    {
        foreach ([
            ['target_json' => ['cache_type' => 'other'], 'operation_type' => 'action.cache_clear'],
            ['target_json' => ['cache_type' => 'wordpress'], 'operation_type' => 'action.other'],
        ] as $case) {
            [$actor, $source] = $this->makeRecoverable();
            Operation::create([
                'site_id' => $source->site_id,
                'operation_type' => $case['operation_type'],
                'target_json' => $case['target_json'],
                'status' => Operation::STATUS_QUEUED,
                'approval_required' => false,
                'idempotency_key' => 'conflicting-recovery-key',
                'requested_by' => $actor->id,
            ]);

            try {
                $this->service()->createSuccessor($actor, $source, 'conflicting-recovery-key');
                $this->fail('Mismatched recovery request must conflict.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Idempotency conflict', $exception->getMessage());
            }
        }
    }

    public function test_unrelated_operation_using_recovery_key_conflicts(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        Operation::create([
            'site_id' => $source->site_id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_QUEUED,
            'approval_required' => false,
            'idempotency_key' => 'unrelated-key',
            'recovery_of_operation_id' => null,
            'requested_by' => $actor->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Idempotency conflict');
        $this->service()->createSuccessor($actor, $source, 'unrelated-key');
    }

    public function test_source_operation_idempotency_key_cannot_be_reused_for_recovery(): void
    {
        [$actor, $source] = $this->makeRecoverable();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Idempotency conflict');
        $this->service()->createSuccessor($actor, $source, $source->idempotency_key);
    }

    public function test_different_sites_can_use_the_same_recovery_key(): void
    {
        [$actorA, $sourceA] = $this->makeRecoverable();
        [$actorB, $sourceB] = $this->makeRecoverable();

        $successorA = $this->service()->createSuccessor($actorA, $sourceA, 'site-scoped-key');
        $successorB = $this->service()->createSuccessor($actorB, $sourceB, 'site-scoped-key');

        $this->assertNotSame($successorA->id, $successorB->id);
        $this->assertSame($sourceA->site_id, $successorA->site_id);
        $this->assertSame($sourceB->site_id, $successorB->site_id);
    }

    public function test_a_source_can_have_only_one_successor_even_with_a_different_key(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        $this->service()->createSuccessor($actor, $source, 'first-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source already has a successor');
        $this->service()->createSuccessor($actor, $source->fresh(), 'second-key');
    }

    public function test_non_dead_letter_source_is_rejected_without_creating_a_successor(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        $source->update(['status' => Operation::STATUS_UNKNOWN]);

        try {
            $this->service()->createSuccessor($actor, $source, 'not-dead-letter-key');
            $this->fail('A non-dead-letter source must fail closed.');
        } catch (OperationRecoveryChainException $exception) {
            $this->assertSame(OperationRecoveryChainException::SOURCE_INELIGIBLE, $exception->reason);
        }

        $this->assertSame(1, Operation::count());
    }

    public function test_uncertain_result_evidence_and_invalid_attempt_history_are_rejected(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        OperationResult::create([
            'operation_id' => $source->id,
            'result_status' => 'success',
            'verification_status' => OperationResult::VERIFICATION_PENDING,
        ]);
        $this->assertRecoveryFailure($actor, $source, OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);

        [$actor, $source] = $this->makeRecoverable();
        $source->attempts()->delete();
        $this->assertRecoveryFailure($actor, $source, OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
    }

    public function test_missing_or_revoked_connection_and_missing_capability_are_rejected(): void
    {
        [$actor, $source, , $connection] = $this->makeRecoverable();
        $connection->update(['status' => 'inactive']);
        $this->assertRecoveryFailure($actor, $source, OperationRecoveryException::CONNECTION_UNAVAILABLE);
        $connection->update(['status' => 'active', 'revoked_at' => now()]);
        $this->assertRecoveryFailure($actor, $source, OperationRecoveryException::CONNECTION_UNAVAILABLE);

        [$actor, $source, , , $capability] = $this->makeRecoverable();
        $capability->delete();
        $this->assertRecoveryFailure($actor, $source, OperationRecoveryException::CAPABILITY_NOT_GRANTED);
    }

    public function test_recovery_chain_limit_rejects_without_creating_a_successor(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        config(['sitepilot.operations.max_recovery_chain_length' => 1]);

        try {
            $this->service()->createSuccessor($actor, $source, 'chain-limit-key');
            $this->fail('A source at the configured chain limit must be rejected.');
        } catch (OperationRecoveryChainException $exception) {
            $this->assertSame(OperationRecoveryChainException::LIMIT_EXCEEDED, $exception->reason);
        }

        $this->assertSame(1, Operation::count());
    }

    public function test_audit_failure_rolls_back_successor_and_new_approval_atomically(): void
    {
        [$actor, $source, $organization] = $this->makeRecoverable();
        $this->makeApproval($source, ApprovalRequest::STATUS_APPROVED);
        $source->update(['approval_required' => true]);
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Event::listen('eloquent.creating: App\\Models\\AuditLog', function (): void {
            throw new RuntimeException('synthetic audit failure');
        });

        try {
            $this->service()->createSuccessor($actor, $source, 'atomicity-key');
            $this->fail('Audit failure should abort successor creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic audit failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: App\\Models\\AuditLog');
        }

        $this->assertSame(1, Operation::count());
        $this->assertSame(1, ApprovalRequest::count());
        $this->assertSame(0, AuditLog::where('action', 'operation_retry_requested')->count());
        $this->assertDatabaseMissing('operations', ['idempotency_key' => 'atomicity-key']);
    }

    public function test_expected_unique_key_collision_reloads_and_replays_exact_successor(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        $successor = $this->service()->createSuccessor($actor, $source, 'collision-key');
        $service = $this->collisionResolver();

        $replayed = $service->resolveCollision(
            $this->sqliteUniqueException('UNIQUE constraint failed: operations.site_id, operations.idempotency_key'),
            (string) $source->site_id,
            (string) $source->id,
            $source->operation_type,
            $source->target_json,
            'collision-key',
        );

        $this->assertSame($successor->id, $replayed->id);
    }

    public function test_expected_source_successor_collision_with_another_key_conflicts(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        $this->service()->createSuccessor($actor, $source, 'existing-source-key');
        $service = $this->collisionResolver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source already has a successor');
        $service->resolveCollision(
            $this->sqliteUniqueException('UNIQUE constraint failed: operations.recovery_of_operation_id'),
            (string) $source->site_id,
            (string) $source->id,
            $source->operation_type,
            $source->target_json,
            'different-source-key',
        );
    }

    public function test_unrelated_database_unique_exception_is_not_swallowed(): void
    {
        [$actor, $source] = $this->makeRecoverable();
        $exception = $this->sqliteUniqueException('UNIQUE constraint failed: users.email');
        $service = $this->collisionResolver();

        try {
            $service->resolveCollision(
                $exception,
                (string) $source->site_id,
                (string) $source->id,
                $source->operation_type,
                $source->target_json,
                'unrelated-key',
            );
            $this->fail('Unrelated unique violations must be rethrown.');
        } catch (QueryException $actual) {
            $this->assertSame($exception, $actual);
        }
    }

    private function service(): OperationRecoveryService
    {
        return new OperationRecoveryService(
            app(OperationRecoveryAuthorization::class),
            app(OperationRecoveryPreconditions::class),
            app(OperationRecoveryChain::class),
        );
    }

    private function collisionResolver(): OperationRecoveryService
    {
        return new class(
            app(OperationRecoveryAuthorization::class),
            app(OperationRecoveryPreconditions::class),
            app(OperationRecoveryChain::class),
        ) extends OperationRecoveryService {
            public function resolveCollision(QueryException $exception, string $siteId, string $sourceId, string $operationType, array $targetJson, string $idempotencyKey): Operation
            {
                return $this->resolveUniqueCollision($exception, $siteId, $sourceId, $operationType, $targetJson, $idempotencyKey);
            }
        };
    }

    private function sqliteUniqueException(string $message): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = ['23000', 19, $message];

        return new QueryException('sqlite', 'insert into operations ...', [], $previous);
    }

    private function makeRecoverable(int $maxAttempts = 2): array
    {
        $actor = User::factory()->create();
        $requester = User::factory()->create();
        $organization = Organization::factory()->create([
            'approval_policy' => [
                'require_approval' => false,
                'high_criticality_requires_approval' => false,
            ],
        ]);
        $role = Role::create(['organization_id' => $organization->id, 'name' => 'Owner', 'key' => 'owner']);
        foreach ([$actor, $requester] as $member) {
            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $member->id,
                'role_id' => $role->id,
                'status' => 'active',
            ]);
        }
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active', 'revoked_at' => null]);
        $capability = ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'reported_supported' => true,
            'reported_at' => now(),
            'discovered_at' => now(),
        ]);
        $source = Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_DEAD_LETTER,
            'approval_required' => false,
            'idempotency_key' => 'source-' . str()->ulid(),
            'max_attempts' => $maxAttempts,
            'requested_by' => $requester->id,
        ]);

        for ($number = 1; $number <= $maxAttempts; $number++) {
            OperationAttempt::create([
                'operation_id' => $source->id,
                'attempt_number' => $number,
                'status' => OperationAttempt::STATUS_TIMEOUT,
                'connector_job_id' => str()->ulid(),
                'retryable' => true,
                'timeout_at' => now()->subMinute(),
                'finished_at' => now(),
                'error_code' => OperationAttempt::ERROR_TIMEOUT,
                'claimed_by_connection_id' => null,
            ]);
        }

        return [$actor, $source, $organization, $connection, $capability, $requester];
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

    private function assertRecoveryFailure(User $actor, Operation $source, string $reason): void
    {
        try {
            $this->service()->createSuccessor($actor, $source, 'blocked-' . str()->ulid());
            $this->fail('Expected recovery to fail: ' . $reason);
        } catch (OperationRecoveryException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
