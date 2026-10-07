<?php

namespace Tests\Feature;

use App\Exceptions\OperationRecoveryException;
use App\Models\ApprovalRequest;
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
use App\Services\OperationRecoveryPreconditions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationRecoveryPreconditionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_retry_allows_only_active_owner_and_admin_memberships(): void
    {
        [$actor, , $operation] = $this->makeRecoverable();
        $authorization = app(OperationRecoveryAuthorization::class);
        $this->assertTrue($authorization->canRetryOperation($actor, $operation));

        $membership = OrganizationMember::where('organization_id', $operation->site->organization_id)
            ->where('user_id', $actor->id)->firstOrFail();
        $admin = Role::create(['organization_id' => $operation->site->organization_id, 'name' => 'Admin', 'key' => 'admin']);
        $membership->update(['role_id' => $admin->id]);
        $this->assertTrue($authorization->canRetryOperation($actor, $operation));

        foreach (['viewer', 'operator', 'unrecognized'] as $roleKey) {
            $role = Role::create(['organization_id' => $operation->site->organization_id, 'name' => $roleKey, 'key' => $roleKey]);
            $membership->update(['role_id' => $role->id]);
            $this->assertFalse($authorization->canRetryOperation($actor, $operation), $roleKey);
        }
    }

    public function test_site_retry_rejects_missing_inactive_and_wrong_tenant_actors(): void
    {
        [$actor, $organization, $operation] = $this->makeRecoverable();
        $authorization = app(OperationRecoveryAuthorization::class);
        $this->assertFalse($authorization->canRetryOperation(null, $operation));

        $actor->update(['status' => 'inactive']);
        $this->assertSame(OperationRecoveryException::INACTIVE_USER, $authorization->failureReason($actor, $operation));
        $actor->update(['status' => 'active']);

        $membership = OrganizationMember::where('organization_id', $organization->id)->where('user_id', $actor->id)->firstOrFail();
        $membership->update(['status' => 'inactive']);
        $this->assertSame(OperationRecoveryException::INACTIVE_MEMBERSHIP, $authorization->failureReason($actor, $operation));
        $membership->update(['status' => 'active']);

        $otherOrg = Organization::factory()->create();
        $otherActor = User::factory()->create();
        $otherRole = Role::create(['organization_id' => $otherOrg->id, 'name' => 'Owner', 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherActor->id,
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);
        $this->assertFalse($authorization->canRetryOperation($otherActor, $operation));
        $this->assertFalse($authorization->canRetryOperation($actor, $operation, Site::factory()->create([
            'organization_id' => $organization->id,
        ])));
    }

    public function test_site_retry_rejects_inactive_organization_and_site(): void
    {
        [$actor, $organization, $operation] = $this->makeRecoverable();
        $authorization = app(OperationRecoveryAuthorization::class);

        $organization->update(['status' => 'suspended']);
        $this->assertSame(OperationRecoveryException::INACTIVE_ORGANIZATION, $authorization->failureReason($actor, $operation));
        $organization->update(['status' => 'active']);

        $operation->site->update(['status' => 'inactive']);
        $this->assertSame(OperationRecoveryException::INACTIVE_SITE, $authorization->failureReason($actor, $operation));
    }

    public function test_safe_dead_letter_source_passes_and_returns_current_approval_requirement(): void
    {
        [$actor, $organization, $operation] = $this->makeRecoverable();
        $this->assertSame(['approval_required' => false], app(OperationRecoveryPreconditions::class)->assess($actor, $operation));

        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        $this->assertSame(['approval_required' => true], app(OperationRecoveryPreconditions::class)->assess($actor, $operation));
    }

    public function test_non_dead_letter_operation_states_are_rejected(): void
    {
        [$actor, , $operation] = $this->makeRecoverable();
        foreach ([
            Operation::STATUS_RUNNING,
            Operation::STATUS_QUEUED,
            Operation::STATUS_SUCCEEDED,
            Operation::STATUS_UNKNOWN,
            Operation::STATUS_CANCELLED,
        ] as $status) {
            DB::table('operations')->where('id', $operation->id)->update(['status' => $status]);
            $operation->refresh();
            $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::SOURCE_NOT_DEAD_LETTER, $status);
        }
    }

    public function test_safe_attempt_history_requires_final_retryable_unclaimed_timeout_and_complete_sequence(): void
    {
        [$actor, , $operation] = $this->makeRecoverable(2);
        $this->assertSame(['approval_required' => false], app(OperationRecoveryPreconditions::class)->assess($actor, $operation));

        foreach ([
            ['status' => OperationAttempt::STATUS_ACCEPTED],
            ['status' => OperationAttempt::STATUS_EXECUTING],
            ['status' => OperationAttempt::STATUS_RESULT_RECEIVED],
            ['status' => 'unknown'],
            ['retryable' => false],
            ['error_code' => 'other_error'],
            ['connector_job_id' => null],
            ['timeout_at' => null],
            ['finished_at' => null],
            ['claimed_by_connection_id' => 'set'],
            ['attempt_number' => 4],
        ] as $overrides) {
            [$actor, , $operation] = $this->makeRecoverable();
            $attempt = $operation->attempts()->firstOrFail();
            if (($overrides['claimed_by_connection_id'] ?? null) === 'set') {
                $overrides['claimed_by_connection_id'] = $operation->site->connections()->firstOrFail()->id;
            }
            $attempt->update($overrides);
            $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
        }

        [$actor, , $operation] = $this->makeRecoverable(2);
        $operation->attempts()->firstOrFail()->update(['status' => OperationAttempt::STATUS_ACCEPTED, 'retryable' => false]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN, 'mixed safe and uncertain attempts');

        [$actor, , $operation] = $this->makeRecoverable();
        $operation->attempts()->firstOrFail()->update(['error_code' => null]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN, 'missing timeout evidence');

        [$actor, , $operation] = $this->makeRecoverable();
        $operation->attempts()->firstOrFail()->delete();
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN, 'missing attempt history');
    }

    public function test_verification_evidence_blocks_recovery_even_with_safe_timeout_history(): void
    {
        [$actor, , $operation] = $this->makeRecoverable();
        OperationResult::create([
            'operation_id' => $operation->id,
            'result_status' => 'success',
            'verification_status' => OperationResult::VERIFICATION_PENDING,
            'actual_state_json' => ['state' => 'uncertain'],
        ]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
    }

    public function test_original_approval_is_required_only_when_source_says_it_was_required(): void
    {
        [$actor, , $operation] = $this->makeRecoverable();
        $this->assertSame(['approval_required' => false], app(OperationRecoveryPreconditions::class)->assess($actor, $operation));

        $operation->update(['approval_required' => true]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::ORIGINAL_APPROVAL_UNAVAILABLE);
        $approval = $this->makeApproval($operation, ApprovalRequest::STATUS_PENDING);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::ORIGINAL_APPROVAL_UNAVAILABLE);
        foreach ([ApprovalRequest::STATUS_REJECTED, ApprovalRequest::STATUS_EXPIRED] as $status) {
            $approval->update(['status' => $status]);
            $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::ORIGINAL_APPROVAL_UNAVAILABLE, $status);
        }
        $approval->update(['status' => ApprovalRequest::STATUS_APPROVED]);
        $this->assertSame(['approval_required' => false], app(OperationRecoveryPreconditions::class)->assess($actor, $operation));
    }

    public function test_connection_and_capability_are_checked(): void
    {
        [$actor, , $operation, $connection, $capability] = $this->makeRecoverable();
        $connection->update(['status' => 'inactive']);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::CONNECTION_UNAVAILABLE);
        $connection->update(['status' => 'active', 'revoked_at' => now()]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::CONNECTION_UNAVAILABLE);
        $connection->update(['revoked_at' => null]);

        $capability->delete();
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::CAPABILITY_NOT_GRANTED);
        $capability = ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => false,
            'discovered_at' => now(),
        ]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::CAPABILITY_NOT_GRANTED);
    }

    public function test_incomplete_current_policy_and_invalid_lineage_fail_closed(): void
    {
        [$actor, $organization, $operation] = $this->makeRecoverable();
        $organization->update(['approval_policy' => ['require_approval' => true]]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::CURRENT_POLICY_UNAVAILABLE);

        $organization->update(['approval_policy' => [
            'require_approval' => false,
            'high_criticality_requires_approval' => false,
        ]]);
        config(['sitepilot.operations.max_recovery_chain_length' => 1]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::RECOVERY_CHAIN_LIMIT_REACHED);

        config(['sitepilot.operations.max_recovery_chain_length' => 3]);
        $foreign = Operation::create([
            'site_id' => Site::factory()->create(['organization_id' => Organization::factory()->create()->id])->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_DEAD_LETTER,
            'approval_required' => false,
            'idempotency_key' => 'foreign-'.str()->ulid(),
            'max_attempts' => 1,
            'requested_by' => $actor->id,
        ]);
        $operation->update(['recovery_of_operation_id' => $foreign->id]);
        $this->assertRecoveryReason($actor, $operation, OperationRecoveryException::RECOVERY_CHAIN_UNAVAILABLE);
    }

    private function makeRecoverable(int $maxAttempts = 1): array
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::create(['organization_id' => $organization->id, 'name' => 'Owner', 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
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
        $operation = Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_DEAD_LETTER,
            'approval_required' => false,
            'idempotency_key' => 'recovery-'.str()->ulid(),
            'max_attempts' => $maxAttempts,
            'requested_by' => $actor->id,
        ]);

        for ($number = 1; $number <= $maxAttempts; $number++) {
            OperationAttempt::create([
                'operation_id' => $operation->id,
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

        return [$actor, $organization, $operation, $connection, $capability];
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

    private function assertRecoveryReason(?User $actor, Operation $operation, string $reason, string $message = ''): void
    {
        try {
            app(OperationRecoveryPreconditions::class)->assess($actor, $operation);
            $this->fail('Expected recovery to fail: '.$reason.($message === '' ? '' : " ({$message})"));
        } catch (OperationRecoveryException $exception) {
            $this->assertSame($reason, $exception->reason, $message);
        }
    }
}
