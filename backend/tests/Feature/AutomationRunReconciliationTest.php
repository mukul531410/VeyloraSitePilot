<?php

namespace Tests\Feature;

use App\Jobs\DispatchOperationJob;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Operation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\AutomationRunReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomationRunReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public static function operationRunStatusPairs(): array
    {
        return [
            'approval pending' => [Operation::STATUS_PENDING_APPROVAL, AutomationRun::STATUS_EVALUATING, AutomationRun::STATUS_AWAITING_APPROVAL],
            'requested' => [Operation::STATUS_REQUESTED, AutomationRun::STATUS_EVALUATING, AutomationRun::STATUS_SUBMITTED],
            'approved' => [Operation::STATUS_APPROVED, AutomationRun::STATUS_EVALUATING, AutomationRun::STATUS_SUBMITTED],
            'queued' => [Operation::STATUS_QUEUED, AutomationRun::STATUS_EVALUATING, AutomationRun::STATUS_SUBMITTED],
            'running' => [Operation::STATUS_RUNNING, AutomationRun::STATUS_EVALUATING, AutomationRun::STATUS_SUBMITTED],
            'verification pending' => [Operation::STATUS_VERIFICATION_PENDING, AutomationRun::STATUS_EVALUATING, AutomationRun::STATUS_SUBMITTED],
            'succeeded' => [Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_SUBMITTED, AutomationRun::STATUS_COMPLETED],
            'failed' => [Operation::STATUS_FAILED, AutomationRun::STATUS_SUBMITTED, AutomationRun::STATUS_FAILED],
            'unknown' => [Operation::STATUS_UNKNOWN, AutomationRun::STATUS_SUBMITTED, AutomationRun::STATUS_UNKNOWN],
            'cancelled' => [Operation::STATUS_CANCELLED, AutomationRun::STATUS_SUBMITTED, AutomationRun::STATUS_CANCELLED],
            'dead letter' => [Operation::STATUS_DEAD_LETTER, AutomationRun::STATUS_SUBMITTED, AutomationRun::STATUS_FAILED],
        ];
    }

    #[DataProvider('operationRunStatusPairs')]
    public function test_operation_status_is_mirrored_through_guarded_transition(
        string $operationStatus,
        string $initialRunStatus,
        string $expectedRunStatus,
    ): void {
        Queue::fake();
        [$run, $operation] = $this->makeLinkedRun($operationStatus, $initialRunStatus);

        $result = app(AutomationRunReconciliationService::class)->reconcile($run);

        $this->assertSame('reconciled', $result['outcome']);
        $this->assertSame($expectedRunStatus, $run->fresh()->status);
        $this->assertSame($operation->id, $run->fresh()->operation_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_reconciled')->count());
        Queue::assertNotPushed(DispatchOperationJob::class);
    }

    public function test_repeated_reconciliation_is_idempotent_without_duplicate_audit_or_dispatch(): void
    {
        Queue::fake();
        [$run] = $this->makeLinkedRun(Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_SUBMITTED);
        $service = app(AutomationRunReconciliationService::class);

        $first = $service->reconcile($run);
        $second = $service->reconcile($run);

        $this->assertSame('reconciled', $first['outcome']);
        $this->assertSame('unchanged', $second['outcome']);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $second['run_status']);
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_reconciled')->count());
        Queue::assertNotPushed(DispatchOperationJob::class);
        $this->assertSame(1, Operation::query()->count());
    }

    public function test_terminal_run_conflict_is_reported_and_never_reopens_the_run(): void
    {
        [$run] = $this->makeLinkedRun(Operation::STATUS_FAILED, AutomationRun::STATUS_COMPLETED);
        $service = app(AutomationRunReconciliationService::class);

        $first = $service->reconcile($run);
        $service->reconcile($run);

        $this->assertSame('conflict', $first['outcome']);
        $this->assertSame('terminal_run_conflict', $first['reason']);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_reconciliation_conflict')->count());
    }

    public function test_unknown_only_resolves_to_authoritative_failed_or_cancelled_operation_status(): void
    {
        foreach ([Operation::STATUS_FAILED => AutomationRun::STATUS_FAILED, Operation::STATUS_CANCELLED => AutomationRun::STATUS_CANCELLED] as $operationStatus => $runStatus) {
            [$run, $operation] = $this->makeLinkedRun(Operation::STATUS_UNKNOWN, AutomationRun::STATUS_UNKNOWN);
            $operation->update([
                'status' => $operationStatus,
                'resolution' => $operationStatus === Operation::STATUS_FAILED ? Operation::RESOLUTION_FAILED : Operation::RESOLUTION_CANCELLED,
                'resolved_at' => now(),
                'resolved_by' => User::query()->firstOrFail()->id,
                'resolution_reason' => 'Reviewed by an operator',
            ]);

            $result = app(AutomationRunReconciliationService::class)->reconcile($run);

            $this->assertSame($runStatus, $result['run_status']);
        }
    }

    public function test_operator_success_disposition_keeps_unknown_run_unknown(): void
    {
        [$run, $operation] = $this->makeLinkedRun(Operation::STATUS_UNKNOWN, AutomationRun::STATUS_UNKNOWN);
        $operation->update([
            'resolution' => Operation::RESOLUTION_SUCCESS,
            'resolved_at' => now(),
            'resolved_by' => User::query()->firstOrFail()->id,
            'resolution_reason' => 'Operator believes the change succeeded',
        ]);

        $result = app(AutomationRunReconciliationService::class)->reconcile($run);

        $this->assertSame(Operation::STATUS_UNKNOWN, $operation->fresh()->status);
        $this->assertSame(AutomationRun::STATUS_UNKNOWN, $result['run_status']);
        $this->assertSame('unchanged', $result['outcome']);
    }

    public function test_wrong_site_operation_is_rejected_without_relinking(): void
    {
        [$run, $operation] = $this->makeLinkedRun(Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_SUBMITTED);
        $otherSite = Site::factory()->create(['organization_id' => $run->organization_id]);
        $wrongSiteOperation = $this->makeOperation($run, $operation->requested_by, $otherSite, Operation::STATUS_SUCCEEDED);
        DB::table('automation_runs')->where('id', $run->id)->update(['operation_id' => $wrongSiteOperation->id]);
        $run->refresh();

        $result = app(AutomationRunReconciliationService::class)->reconcile($run);

        $this->assertSame('operation_scope_mismatch', $result['reason']);
        $this->assertSame($wrongSiteOperation->id, $run->fresh()->operation_id);
        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $run->fresh()->status);
    }

    public function test_wrong_organization_operation_is_rejected_without_status_change(): void
    {
        [$run, $operation] = $this->makeLinkedRun(Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_SUBMITTED);
        $otherOrganization = Organization::factory()->create();
        DB::table('sites')->where('id', $operation->site_id)->update(['organization_id' => $otherOrganization->id]);

        $result = app(AutomationRunReconciliationService::class)->reconcile($run);

        $this->assertSame('operation_scope_mismatch', $result['reason']);
        $this->assertSame($operation->id, $run->fresh()->operation_id);
        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $run->fresh()->status);
    }

    public function test_operation_with_another_runs_idempotency_identity_is_rejected(): void
    {
        [$run, $operation] = $this->makeLinkedRun(Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_SUBMITTED);
        $operation->update(['idempotency_key' => 'automation:run:another-run:operation:v1']);

        $result = app(AutomationRunReconciliationService::class)->reconcile($run);

        $this->assertSame('operation_run_link_mismatch', $result['reason']);
        $this->assertSame($operation->id, $run->fresh()->operation_id);
        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $run->fresh()->status);
    }

    public function test_missing_and_stranded_runs_are_reported_without_creating_operations(): void
    {
        [$stranded] = $this->makeLinkedRun(Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_EVALUATING, link: false);
        [$unlinkedSubmitted] = $this->makeLinkedRun(Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_SUBMITTED, link: false);

        $result = app(AutomationRunReconciliationService::class)->reconcile($stranded);
        $missing = app(AutomationRunReconciliationService::class)->reconcile($unlinkedSubmitted);
        $exitCode = Artisan::call('sitepilot:automation-reconcile');
        $output = Artisan::output();

        $this->assertSame('stranded_evaluating', $result['outcome']);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $stranded->fresh()->status);
        $this->assertNull($stranded->fresh()->operation_id);
        $this->assertSame('missing_operation', $missing['outcome']);
        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $unlinkedSubmitted->fresh()->status);
        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('stranded_evaluating', $output);
    }

    public function test_command_reconciles_linked_operation_and_preserves_operation_authority(): void
    {
        [$run, $operation] = $this->makeLinkedRun(Operation::STATUS_SUCCEEDED, AutomationRun::STATUS_SUBMITTED);

        $exitCode = Artisan::call('sitepilot:automation-reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('completed', $output);
        $this->assertSame(Operation::STATUS_SUCCEEDED, $operation->fresh()->status);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(1, Operation::query()->count());
    }

    /** @return array{AutomationRun, Operation|null} */
    private function makeLinkedRun(string $operationStatus, string $runStatus, bool $link = true): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $rule = AutomationRule::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Reconciliation test rule',
            'enabled' => true,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $user->id,
        ]);
        $run = AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'occurrence_key' => 'schedule:v1:2026-10-01T10:00:00Z',
            'status' => AutomationRun::STATUS_PENDING,
        ]);
        $run->transitionToEvaluating();
        $run->save();
        if ($runStatus !== AutomationRun::STATUS_EVALUATING) {
            $run->refresh();
            $run->transitionTo($runStatus);
            $run->save();
        }

        if (! $link) {
            return [$run, null];
        }

        $operation = $this->makeOperation($run, $user->id, $site, $operationStatus);
        $run->refresh();
        $run->operation_id = $operation->id;
        $run->save();

        return [$run, $operation];
    }

    private function makeOperation(AutomationRun $run, int $userId, Site $site, string $status): Operation
    {
        return Operation::create([
            'site_id' => $site->id,
            'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => $status,
            'idempotency_key' => 'automation:run:'.$run->id.':operation:v1',
            'requested_by' => $userId,
        ]);
    }
}
