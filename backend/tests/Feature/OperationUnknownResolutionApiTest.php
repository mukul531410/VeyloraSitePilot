<?php

namespace Tests\Feature;

use App\Exceptions\OperationUnknownResolutionException;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\OperationUnknownResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationUnknownResolutionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_resolve_unknown_operation_without_changing_evidence_or_dispatching(): void
    {
        [$actor, $operation, $attempt] = $this->makeUnknown();
        $result = OperationResult::create([
            'operation_id' => $operation->id,
            'operation_attempt_id' => $attempt->id,
            'result_status' => 'success',
            'verification_status' => OperationResult::VERIFICATION_PENDING,
            'actual_state_json' => ['connector_reported' => 'pending'],
            'result_summary' => 'Original connector evidence',
        ]);
        $resultBefore = $result->fresh()->only(['result_status', 'verification_status', 'actual_state_json', 'result_summary']);
        Sanctum::actingAs($actor);
        Queue::fake();

        $response = $this->withHeader('X-Request-ID', 'unknown-resolution-1')
            ->postJson('/api/v1/operations/'.$operation->id.'/resolve-unknown', [
                'resolution' => 'success',
                'reason' => 'Confirmed in the site control panel.',
            ]);

        $response->assertOk()
            ->assertJsonPath('request_id', 'unknown-resolution-1')
            ->assertJsonPath('data.status', Operation::STATUS_UNKNOWN)
            ->assertJsonPath('data.resolution', Operation::RESOLUTION_SUCCESS)
            ->assertJsonPath('data.resolved_by', $actor->id)
            ->assertJsonPath('data.resolution_reason', 'Confirmed in the site control panel.');

        $this->assertSame($attempt->id, $operation->fresh()->attempts()->sole()->id);
        $this->assertSame(1, $operation->fresh()->result()->count());
        $this->assertSame($resultBefore, $result->fresh()->only(array_keys($resultBefore)));
        $this->assertSame(0, Operation::where('recovery_of_operation_id', $operation->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'operation_unknown_resolved')->count());
        Queue::assertNothingPushed();
    }

    public function test_failed_and_cancelled_resolutions_use_terminal_statuses(): void
    {
        foreach ([
            ['failed', Operation::STATUS_FAILED],
            ['cancelled', Operation::STATUS_CANCELLED],
        ] as [$resolution, $status]) {
            [$actor, $operation] = $this->makeUnknown();
            Sanctum::actingAs($actor);

            $this->postJson('/api/v1/operations/'.$operation->id.'/resolve-unknown', [
                'resolution' => $resolution,
                'reason' => 'Reviewed the remote site state.',
            ])->assertOk()->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.resolution', $resolution);
        }
    }

    public function test_admin_is_allowed_and_viewer_is_denied(): void
    {
        [$admin, $adminOperation] = $this->makeUnknown('admin');
        Sanctum::actingAs($admin);
        $this->resolve($adminOperation)->assertOk();

        [$viewer, $viewerOperation] = $this->makeUnknown('viewer');
        Sanctum::actingAs($viewer);
        $this->resolve($viewerOperation)->assertForbidden()->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_inactive_user_and_membership_are_denied(): void
    {
        [$actor, $operation] = $this->makeUnknown();
        $actor->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->resolve($operation)->assertForbidden();

        [$actor, $operation, , $membership] = $this->makeUnknown();
        $membership->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->resolve($operation)->assertNotFound();
    }

    public function test_inactive_organization_and_site_are_denied(): void
    {
        [$actor, $operation, , , $organization] = $this->makeUnknown();
        $organization->update(['status' => 'suspended']);
        Sanctum::actingAs($actor);
        $this->resolve($operation)->assertNotFound();

        [$actor, $operation, , , , $site] = $this->makeUnknown();
        $site->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->resolve($operation)->assertNotFound();
    }

    public function test_service_rejects_wrong_expected_site(): void
    {
        [$actor, $operation] = $this->makeUnknown();
        $wrongOrganization = Organization::factory()->create();
        $wrongSite = Site::factory()->create(['organization_id' => $wrongOrganization->id]);

        try {
            app(OperationUnknownResolutionService::class)->resolve(
                $actor,
                $operation,
                'success',
                'Reviewed site state.',
                'wrong-site-correlation',
                $wrongSite,
            );
            $this->fail('Expected the wrong site to be rejected.');
        } catch (OperationUnknownResolutionException $exception) {
            $this->assertSame(OperationUnknownResolutionException::WRONG_TENANT, $exception->reason);
        }
    }

    public function test_cross_organization_and_unrelated_site_are_hidden(): void
    {
        [$actor] = $this->makeUnknown();
        [, $foreignOperation] = $this->makeUnknown();
        Sanctum::actingAs($actor);
        $this->resolve($foreignOperation)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_non_unknown_operation_and_repeated_resolution_conflict(): void
    {
        [$actor, $operation] = $this->makeUnknown();
        Sanctum::actingAs($actor);
        DB::table('operations')->where('id', $operation->id)->update(['status' => Operation::STATUS_QUEUED]);
        $this->resolve($operation)->assertStatus(409)->assertJsonPath('error.code', 'operation_not_unknown');

        [$actor, $operation] = $this->makeUnknown();
        Sanctum::actingAs($actor);
        $this->resolve($operation)->assertOk();
        $this->resolve($operation)->assertStatus(409)->assertJsonPath('error.code', 'already_resolved');
    }

    public function test_validation_requires_allowed_resolution_and_nonblank_reason(): void
    {
        [$actor, $operation] = $this->makeUnknown();
        Sanctum::actingAs($actor);
        $this->withHeader('X-Request-ID', 'unknown-validation-1')
            ->postJson('/api/v1/operations/'.$operation->id.'/resolve-unknown', [
                'resolution' => 'queued',
                'reason' => '  ',
                'site_id' => 'attacker-controlled',
                'status' => 'succeeded',
            ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('request_id', 'unknown-validation-1');
    }

    public function test_missing_unknown_execution_evidence_fails_closed(): void
    {
        [$actor, $operation] = $this->makeUnknown(withAttempt: false);
        Sanctum::actingAs($actor);
        $this->resolve($operation)->assertStatus(409)->assertJsonPath('error.code', 'evidence_missing');
    }

    public function test_unknown_cannot_enter_any_other_lifecycle_without_resolution(): void
    {
        foreach ([
            Operation::STATUS_REQUESTED,
            Operation::STATUS_PENDING_APPROVAL,
            Operation::STATUS_APPROVED,
            Operation::STATUS_QUEUED,
            Operation::STATUS_RUNNING,
            Operation::STATUS_VERIFICATION_PENDING,
            Operation::STATUS_SUCCEEDED,
            Operation::STATUS_FAILED,
            Operation::STATUS_CANCELLED,
            Operation::STATUS_DEAD_LETTER,
        ] as $status) {
            [, $operation] = $this->makeUnknown();
            try {
                $operation->update(['status' => $status]);
                $this->fail("Expected unknown -> {$status} to be rejected");
            } catch (\LogicException) {
                $this->assertSame(Operation::STATUS_UNKNOWN, $operation->fresh()->status, $status);
            }
        }
    }

    public function test_unknown_cannot_be_marked_succeeded_without_operator_resolution_fields(): void
    {
        [, $operation] = $this->makeUnknown();
        $this->expectException(\LogicException::class);
        $operation->update(['status' => Operation::STATUS_SUCCEEDED]);
    }

    public function test_success_resolution_without_existing_result_does_not_create_one(): void
    {
        [$actor, $operation] = $this->makeUnknown();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/operations/'.$operation->id.'/resolve-unknown', [
            'resolution' => 'success',
            'reason' => 'External administrator confirmed the action.',
        ])->assertOk()
            ->assertJsonPath('data.status', Operation::STATUS_UNKNOWN)
            ->assertJsonPath('data.resolution', Operation::RESOLUTION_SUCCESS);

        $this->assertSame(0, OperationResult::where('operation_id', $operation->id)->count());
    }

    private function resolve(Operation $operation)
    {
        return $this->postJson('/api/v1/operations/'.$operation->id.'/resolve-unknown', [
            'resolution' => 'failed',
            'reason' => 'Reviewed the remote site state.',
        ]);
    }

    private function makeUnknown(string $roleKey = 'owner', bool $withAttempt = true): array
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::create(['organization_id' => $organization->id, 'name' => ucfirst($roleKey), 'key' => $roleKey]);
        $membership = OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $operation = Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_UNKNOWN,
            'policy_result' => 'allowed',
            'approval_required' => false,
            'idempotency_key' => 'unknown-'.$site->id.'-'.str()->ulid(),
            'max_attempts' => 1,
            'requested_by' => $actor->id,
        ]);

        $attempt = $withAttempt ? OperationAttempt::create([
            'operation_id' => $operation->id,
            'attempt_number' => 1,
            'status' => OperationAttempt::STATUS_TIMEOUT,
            'connector_job_id' => str()->ulid(),
            'claimed_by_connection_id' => $connection->id,
            'retryable' => false,
            'timeout_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_code' => OperationAttempt::ERROR_TIMEOUT,
        ]) : null;

        return [$actor, $operation, $attempt, $membership, $organization, $site];
    }
}
