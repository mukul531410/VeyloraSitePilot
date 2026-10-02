<?php

namespace Tests\Feature;

use App\Jobs\DispatchOperationJob;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\AutomationOperationOrigin;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationRunIntent;
use App\Models\ConnectorCapability;
use App\Models\Operation;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\AutomationRunClaimService;
use App\Services\AutomationRunEvaluationService;
use App\Services\AutomationRunService;
use App\Services\AutomationSchedulerService;
use App\Services\OperationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PDOException;
use Tests\TestCase;

class AutomationRunEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_authorized_claimed_run_creates_one_operation_with_deterministic_key_and_audit(): void
    {
        [$rule, $run, $user, $site] = $this->makeCase(withCapability: true);
        $run = app(AutomationRunClaimService::class)->claim($run);
        $evaluator = app(AutomationRunEvaluationService::class);

        $this->assertSame('automation:run:'.$run->id.':operation:v1', $evaluator->idempotencyKeyFor($run));
        $result = $evaluator->evaluate($run);
        $operation = Operation::findOrFail($result['operation_id']);

        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $result['run_status']);
        $this->assertSame(Operation::STATUS_QUEUED, $operation->status);
        $this->assertSame($operation->id, $this->runFor($run)->operation_id);
        $this->assertSame($user->id, $operation->requested_by);
        $this->assertSame($site->id, $operation->site_id);
        $this->assertSame('automation:run:'.$run->id.':operation:v1', $operation->idempotency_key);
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(1, AutomationOperationOrigin::query()->count());
        $this->assertSame($run->id, AutomationOperationOrigin::query()->firstOrFail()->automation_run_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_evaluated')->count());
        $this->assertSame(26, strlen(AuditLog::query()->where('action', 'policy_evaluated')->value('correlation_id')));
        Queue::assertNotPushed(DispatchOperationJob::class);
    }

    public function test_different_runs_have_different_keys_and_repeat_reuses_existing_link(): void
    {
        [$rule, $firstRun] = $this->makeCase(withCapability: true);
        $firstRun = app(AutomationRunClaimService::class)->claim($firstRun);
        $service = app(AutomationRunEvaluationService::class);
        $firstKey = $service->idempotencyKeyFor($firstRun);
        $service->evaluate($firstRun);

        $secondRun = AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'site_id' => $rule->site_id,
            'occurrence_key' => 'schedule:v1:2026-10-01T10:10:00Z',
            'status' => AutomationRun::STATUS_PENDING,
        ]);
        $this->assertNotSame($firstKey, $service->idempotencyKeyFor($secondRun));

        $mock = Mockery::mock(OperationService::class);
        $mock->shouldNotReceive('createOperation');
        $this->app->instance(OperationService::class, $mock);
        $result = app(AutomationRunEvaluationService::class)->evaluate($this->runFor($firstRun));

        $this->assertSame('already_linked', $result['outcome']);
        $this->assertSame(1, Operation::query()->count());
    }

    public function test_new_run_captures_immutable_intent_once_and_duplicate_occurrence_does_not_replace_it(): void
    {
        [$rule, $run, $user, $site, $organization] = $this->makeCase();
        $intent = $run->intent()->firstOrFail();
        $originalTarget = $rule->target_json;

        $this->assertSame(1, AutomationRunIntent::query()->where('automation_run_id', $run->id)->count());
        $this->assertSame(1, $intent->intent_version);
        $this->assertNotNull($intent->captured_at);
        $this->assertSame($rule->id, $intent->automation_rule_id);
        $this->assertSame($site->id, $intent->site_id);
        $this->assertSame($organization->id, $intent->organization_id);
        $this->assertSame($run->occurrence_key, $intent->occurrence_key);
        $this->assertSame($rule->action_type, $intent->original_operation_type);
        $this->assertSame($rule->target_json, $intent->original_target_json);
        $this->assertSame($user->id, $intent->original_requester_id);
        $this->assertSame('automation:run:'.$run->id.':operation:v1', $intent->original_idempotency_key);
        $this->assertArrayHasKey('organization_approval_policy', $intent->policy_context_snapshot);

        try {
            $intent->original_target_json = ['cache_type' => 'changed'];
            $intent->save();
            $this->fail('Intent snapshot was mutable.');
        } catch (\LogicException) {
            $this->assertSame($rule->target_json, $intent->fresh()->original_target_json);
        }

        $rule->update(['target_json' => ['cache_type' => 'changed']]);
        $reused = app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new \DateTimeImmutable('2026-10-01T10:05:00Z'),
        );

        $this->assertSame($run->id, $reused->id);
        $this->assertSame($originalTarget, $reused->intent()->firstOrFail()->original_target_json);
        $this->assertSame(1, AutomationRunIntent::query()->where('automation_run_id', $run->id)->count());
    }

    public function test_legacy_run_without_intent_is_not_reconstructed(): void
    {
        [$rule, $run] = $this->makeCase();
        DB::table('automation_run_intents')->where('automation_run_id', $run->id)->delete();
        $this->assertNull($run->fresh()->intent);

        $reused = app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new \DateTimeImmutable('2026-10-01T10:05:00Z'),
        );

        $this->assertSame($run->id, $reused->id);
        $this->assertNull($reused->intent()->first());
    }

    public function test_operation_creation_origin_link_state_and_evaluation_audit_roll_back_together(): void
    {
        [, $run] = $this->makeCase(withCapability: true);
        $run = app(AutomationRunClaimService::class)->claim($run);
        $realService = app(OperationService::class);
        $mock = Mockery::mock(OperationService::class);
        $mock->shouldReceive('createOperation')->once()->andReturnUsing(function (...$arguments) use ($realService): Operation {
            $realService->createOperation(...$arguments);
            throw new \RuntimeException('forced post-origin failure');
        });
        $this->app->instance(OperationService::class, $mock);

        try {
            app(AutomationRunEvaluationService::class)->evaluate($run);
            $this->fail('Forced transaction failure did not propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced post-origin failure', $exception->getMessage());
        }

        $this->assertSame(AutomationRun::STATUS_EVALUATING, $this->runFor($run)->status);
        $this->assertNull($this->runFor($run)->operation_id);
        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(0, AutomationOperationOrigin::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'automation_run_evaluated')->count());
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['operation_requested', 'policy_evaluated'])->count());
    }

    public function test_same_operation_cannot_be_claimed_by_another_run_and_missing_origin_key_is_rejected(): void
    {
        [$rule, $firstRun, $user, $site] = $this->makeCase(withCapability: true);
        $firstRun = app(AutomationRunClaimService::class)->claim($firstRun);
        $firstResult = app(AutomationRunEvaluationService::class)->evaluate($firstRun);
        $operation = Operation::findOrFail($firstResult['operation_id']);
        $secondRun = app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new \DateTimeImmutable('2026-10-01T10:10:00Z'),
        );

        try {
            AutomationOperationOrigin::query()->create([
                'automation_run_id' => $secondRun->id,
                'operation_id' => $operation->id,
                'site_id' => $site->id,
                'organization_id' => $firstRun->organization_id,
                'linked_at' => now('UTC'),
                'version' => 1,
            ]);
            $this->fail('An Operation was allowed to have a second run origin.');
        } catch (QueryException) {
            $this->assertSame(1, AutomationOperationOrigin::query()->count());
        }

        $runWithoutProof = app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new \DateTimeImmutable('2026-10-01T10:15:00Z'),
        );
        $collision = $this->makeOperation(
            $user,
            $site,
            'automation:run:'.$runWithoutProof->id.':operation:v1',
            $rule->target_json,
        );
        $runWithoutProof = app(AutomationRunClaimService::class)->claim($runWithoutProof);

        $result = app(AutomationRunEvaluationService::class)->evaluate($runWithoutProof);

        $this->assertSame(AutomationRun::STATUS_FAILED, $result['run_status']);
        $this->assertNull($this->runFor($runWithoutProof)->operation_id);
        $this->assertSame($collision->id, Operation::query()->whereKey($collision->id)->value('id'));
        $this->assertSame(1, AutomationOperationOrigin::query()->count());
    }

    public function test_same_run_cannot_have_two_operation_origins(): void
    {
        [$rule, $run, $user, $site] = $this->makeCase();
        $firstOperation = $this->makeOperation($user, $site, 'first-key', $rule->target_json);
        $secondOperation = $this->makeOperation($user, $site, 'second-key', $rule->target_json);
        AutomationOperationOrigin::query()->create([
            'automation_run_id' => $run->id,
            'operation_id' => $firstOperation->id,
            'site_id' => $site->id,
            'organization_id' => $run->organization_id,
            'linked_at' => now('UTC'),
            'version' => 1,
        ]);

        try {
            AutomationOperationOrigin::query()->create([
                'automation_run_id' => $run->id,
                'operation_id' => $secondOperation->id,
                'site_id' => $site->id,
                'organization_id' => $run->organization_id,
                'linked_at' => now('UTC'),
                'version' => 1,
            ]);
            $this->fail('A run was allowed to have two Operation origins.');
        } catch (QueryException) {
            $this->assertSame(1, AutomationOperationOrigin::query()->count());
        }
    }

    public function test_cross_tenant_operation_origin_is_rejected(): void
    {
        [$rule, $run, $user, $site] = $this->makeCase();
        $operation = $this->makeOperation($user, $site, 'cross-tenant-test-key', $rule->target_json);
        $otherOrganization = Organization::factory()->create();

        $this->expectException(\LogicException::class);
        AutomationOperationOrigin::query()->create([
            'automation_run_id' => $run->id,
            'operation_id' => $operation->id,
            'site_id' => $site->id,
            'organization_id' => $otherOrganization->id,
            'linked_at' => now('UTC'),
            'version' => 1,
        ]);
    }

    public function test_operation_with_automation_key_in_another_site_is_not_adopted_or_duplicated(): void
    {
        [$rule, $run, $user] = $this->makeCase(withCapability: true);
        $otherOrganization = Organization::factory()->create();
        $otherSite = Site::factory()->create(['organization_id' => $otherOrganization->id]);
        $existing = $this->makeOperation(
            $user,
            $otherSite,
            app(AutomationRunEvaluationService::class)->idempotencyKeyFor($run),
            $rule->target_json,
        );
        $run = app(AutomationRunClaimService::class)->claim($run);

        $result = app(AutomationRunEvaluationService::class)->evaluate($run);

        $this->assertSame(AutomationRun::STATUS_FAILED, $result['run_status']);
        $this->assertNull($this->runFor($run)->operation_id);
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(0, AutomationOperationOrigin::query()->count());
        $this->assertSame($existing->id, Operation::query()->firstOrFail()->id);
    }

    public function test_manual_operation_service_call_cannot_use_a_reserved_automation_key(): void
    {
        [, $run, $user, $site] = $this->makeCase();

        $this->expectException(\InvalidArgumentException::class);
        app(OperationService::class)->createOperation(
            $user,
            $site,
            AutomationRule::ACTION_CACHE_CLEAR,
            ['cache_type' => 'wordpress'],
            app(AutomationRunEvaluationService::class)->idempotencyKeyFor($run),
        );
    }

    public function test_origin_cannot_disagree_with_the_run_operation_link(): void
    {
        [, $run, $user, $site] = $this->makeCase(withCapability: true);
        $run = app(AutomationRunClaimService::class)->claim($run);
        $result = app(AutomationRunEvaluationService::class)->evaluate($run);
        $originOperationId = $result['operation_id'];
        $differentOperation = $this->makeOperation($user, $site, 'other-operation-key', ['cache_type' => 'wordpress']);
        DB::table('automation_runs')->where('id', $run->id)->update(['operation_id' => $differentOperation->id]);

        $result = app(AutomationRunEvaluationService::class)->evaluate($run->fresh());

        $this->assertSame('link_invalid', $result['outcome']);
        $this->assertSame($differentOperation->id, $result['operation_id']);
        $this->assertNotSame($originOperationId, $result['operation_id']);
    }

    public function test_unauthorized_creator_and_invalid_rule_fail_without_creating_an_operation(): void
    {
        foreach (['inactive_user', 'inactive_membership', 'inactive_organization', 'inactive_site', 'lost_role', 'disabled_rule', 'scope_mismatch'] as $case) {
            [$rule, $run, $user, $site, $organization, $membership] = $this->makeCase();
            match ($case) {
                'inactive_user' => $user->update(['status' => 'inactive']),
                'inactive_membership' => $membership->update(['status' => 'inactive']),
                'inactive_organization' => $organization->update(['status' => 'inactive']),
                'inactive_site' => $site->update(['status' => 'inactive']),
                'lost_role' => $membership->role()->update(['key' => 'viewer']),
                'disabled_rule' => $rule->update(['enabled' => false]),
                'scope_mismatch' => DB::table('automation_rules')->where('id', $rule->id)->update([
                    'organization_id' => Organization::factory()->create()->id,
                ]),
            };
            $run = app(AutomationRunClaimService::class)->claim($run);

            $result = app(AutomationRunEvaluationService::class)->evaluate($run);

            $this->assertSame(AutomationRun::STATUS_FAILED, $result['run_status'], $case);
            $this->assertNull($result['operation_id'], $case);
            $this->assertSame(0, Operation::query()->count(), $case);
        }
    }

    public function test_policy_denial_fails_run_without_operation(): void
    {
        [, $run] = $this->makeCase();
        $run = app(AutomationRunClaimService::class)->claim($run);

        $result = app(AutomationRunEvaluationService::class)->evaluate($run);

        $this->assertSame('policy_denied', $result['failure_code']);
        $this->assertSame(AutomationRun::STATUS_FAILED, $result['run_status']);
        $this->assertSame(0, Operation::query()->count());
        $event = AuditLog::query()->where('action', 'automation_run_evaluated')->firstOrFail();
        $this->assertSame('denied', $event->metadata_json['policy_result']);
    }

    public function test_approval_policy_creates_pending_approval_without_approving_or_dispatching(): void
    {
        [, $run, , , $organization] = $this->makeCase(withCapability: true);
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        $run = app(AutomationRunClaimService::class)->claim($run);

        $result = app(AutomationRunEvaluationService::class)->evaluate($run);
        $operation = Operation::findOrFail($result['operation_id']);
        $approval = ApprovalRequest::where('operation_id', $operation->id)->firstOrFail();

        $this->assertSame(AutomationRun::STATUS_AWAITING_APPROVAL, $result['run_status']);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $approval->status);
        $this->assertSame(Operation::STATUS_PENDING_APPROVAL, $operation->status);
        Queue::assertNotPushed(DispatchOperationJob::class);
    }

    public function test_idempotency_conflict_never_attaches_an_unrelated_operation(): void
    {
        [$rule, $run, $user, $site] = $this->makeCase(withCapability: true);
        $key = app(AutomationRunEvaluationService::class)->idempotencyKeyFor($run);
        $existing = $this->makeOperation($user, $site, $key, ['cache_type' => 'other']);
        $run = app(AutomationRunClaimService::class)->claim($run);

        $result = app(AutomationRunEvaluationService::class)->evaluate($run);

        $this->assertSame(AutomationRun::STATUS_FAILED, $result['run_status']);
        $this->assertSame('operation_link_invalid', $result['failure_code']);
        $this->assertNull($this->runFor($run)->operation_id);
        $this->assertSame($existing->id, Operation::query()->firstOrFail()->id);
        $this->assertSame(1, Operation::query()->count());
    }

    public function test_invalid_existing_operation_link_is_never_overwritten(): void
    {
        [$rule, $run, $user, $site] = $this->makeCase();
        $wrongTargetOperation = $this->makeOperation(
            $user,
            $site,
            'unrelated-existing-key',
            ['cache_type' => 'other'],
        );
        $run->operation_id = $wrongTargetOperation->id;
        $run->save();

        $result = app(AutomationRunEvaluationService::class)->evaluate($this->runFor($run));

        $this->assertSame('link_invalid', $result['outcome']);
        $this->assertSame($wrongTargetOperation->id, $this->runFor($run)->operation_id);
        $this->assertSame(1, Operation::query()->count());
    }

    public function test_returned_operation_must_match_scope_type_target_requester_and_key(): void
    {
        foreach (['site', 'organization', 'type', 'target', 'requester', 'key'] as $mismatch) {
            [$rule, $run, $user, $site] = $this->makeCase();
            $expectedKey = app(AutomationRunEvaluationService::class)->idempotencyKeyFor($run);
            $operationSite = $site;
            $operationType = AutomationRule::ACTION_CACHE_CLEAR;
            $target = $rule->target_json;
            $requester = $user;
            $key = $expectedKey;

            if (in_array($mismatch, ['site', 'organization'], true)) {
                $otherOrganization = Organization::factory()->create();
                $operationSite = Site::factory()->create(['organization_id' => $otherOrganization->id]);
            } elseif ($mismatch === 'type') {
                $operationType = 'action.other';
            } elseif ($mismatch === 'target') {
                $target = ['cache_type' => 'other'];
            } elseif ($mismatch === 'requester') {
                $requester = User::factory()->create();
            } else {
                $key .= ':wrong';
            }

            $operation = Operation::create([
                'site_id' => $operationSite->id,
                'operation_type' => $operationType,
                'target_json' => $target,
                'status' => Operation::STATUS_QUEUED,
                'idempotency_key' => $key,
                'requested_by' => $requester->id,
            ]);
            $mock = Mockery::mock(OperationService::class);
            $mock->shouldReceive('createOperation')->once()->andReturn($operation);
            $this->app->instance(OperationService::class, $mock);
            $run = app(AutomationRunClaimService::class)->claim($run);

            $result = app(AutomationRunEvaluationService::class)->evaluate($run);

            $this->assertSame(AutomationRun::STATUS_FAILED, $result['run_status'], $mismatch);
            $this->assertNull($this->runFor($run)->operation_id, $mismatch);
            $this->assertDatabaseHas('operations', ['id' => $operation->id]);
        }
    }

    public function test_unexpected_operation_service_error_propagates_and_rolls_back(): void
    {
        [, $run] = $this->makeCase();
        $run = app(AutomationRunClaimService::class)->claim($run);
        $exception = new QueryException('sqlite', 'insert into operations', [], new PDOException('database unavailable'));
        $mock = Mockery::mock(OperationService::class);
        $mock->shouldReceive('createOperation')->once()->andThrow($exception);
        $this->app->instance(OperationService::class, $mock);

        try {
            app(AutomationRunEvaluationService::class)->evaluate($run);
            $this->fail('Unexpected infrastructure failure was swallowed.');
        } catch (QueryException $actual) {
            $this->assertSame($exception, $actual);
        }

        $this->assertSame(AutomationRun::STATUS_EVALUATING, $this->runFor($run)->status);
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_non_evaluating_states_never_submit_operations(): void
    {
        foreach ([
            AutomationRun::STATUS_PENDING,
            AutomationRun::STATUS_AWAITING_APPROVAL,
            AutomationRun::STATUS_SUBMITTED,
            AutomationRun::STATUS_COMPLETED,
            AutomationRun::STATUS_FAILED,
            AutomationRun::STATUS_SKIPPED,
            AutomationRun::STATUS_UNKNOWN,
            AutomationRun::STATUS_CANCELLED,
        ] as $index => $status) {
            [, $run] = $this->makeCase();
            $run = $this->setRunStatus($run, $status);
            $mock = Mockery::mock(OperationService::class);
            $mock->shouldNotReceive('createOperation');
            $this->app->instance(OperationService::class, $mock);

            $result = app(AutomationRunEvaluationService::class)->evaluate($run);

            $this->assertSame('not_evaluatable', $result['outcome'], $status);
            $this->assertSame($status, $result['run_status'], $status);
            $this->assertSame(0, Operation::query()->count(), $status);
        }
    }

    public function test_immediate_operation_statuses_map_to_the_declared_run_statuses(): void
    {
        $mapping = [
            Operation::STATUS_PENDING_APPROVAL => AutomationRun::STATUS_AWAITING_APPROVAL,
            Operation::STATUS_REQUESTED => AutomationRun::STATUS_SUBMITTED,
            Operation::STATUS_APPROVED => AutomationRun::STATUS_SUBMITTED,
            Operation::STATUS_QUEUED => AutomationRun::STATUS_SUBMITTED,
            Operation::STATUS_RUNNING => AutomationRun::STATUS_SUBMITTED,
            Operation::STATUS_VERIFICATION_PENDING => AutomationRun::STATUS_SUBMITTED,
            Operation::STATUS_SUCCEEDED => AutomationRun::STATUS_COMPLETED,
            Operation::STATUS_FAILED => AutomationRun::STATUS_FAILED,
            Operation::STATUS_UNKNOWN => AutomationRun::STATUS_UNKNOWN,
            Operation::STATUS_CANCELLED => AutomationRun::STATUS_CANCELLED,
            Operation::STATUS_DEAD_LETTER => AutomationRun::STATUS_FAILED,
        ];

        foreach ($mapping as $operationStatus => $runStatus) {
            [$rule, $run, $user, $site] = $this->makeCase();
            $operation = $this->makeOperation(
                $user,
                $site,
                app(AutomationRunEvaluationService::class)->idempotencyKeyFor($run),
                $rule->target_json,
                $operationStatus,
            );
            AutomationOperationOrigin::query()->create([
                'automation_run_id' => $run->id,
                'operation_id' => $operation->id,
                'site_id' => $site->id,
                'organization_id' => $run->organization_id,
                'linked_at' => now('UTC'),
                'version' => 1,
            ]);
            $mock = Mockery::mock(OperationService::class);
            $mock->shouldReceive('createOperation')->once()->andReturn($operation);
            $this->app->instance(OperationService::class, $mock);
            $run = app(AutomationRunClaimService::class)->claim($run);

            $result = app(AutomationRunEvaluationService::class)->evaluate($run);

            $this->assertSame($runStatus, $result['run_status'], $operationStatus);
            $this->assertSame($operation->id, $this->runFor($run)->operation_id, $operationStatus);
        }
    }

    public function test_scheduler_evaluates_newly_claimed_run_through_operation_service(): void
    {
        [, $run] = $this->makeCase(withCapability: true);

        $result = collect(app(AutomationSchedulerService::class)->processDueRules(
            new \DateTimeImmutable('2026-10-01T10:07:00Z'),
        ))->firstWhere('run_id', $run->id);

        $this->assertTrue($result['claimed']);
        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $result['run_status']);
        $this->assertSame('evaluated', $result['evaluation']['outcome']);
        $this->assertSame(1, Operation::query()->count());
    }

    private function makeCase(bool $withCapability = false): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => 'owner']);
        $membership = OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $rule = AutomationRule::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Scheduled cache clear',
            'enabled' => true,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $user->id,
        ]);
        $run = app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new \DateTimeImmutable('2026-10-01T10:05:00Z'),
        );

        if ($withCapability) {
            $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
            ConnectorCapability::create([
                'site_connection_id' => $connection->id,
                'capability_key' => AutomationRule::ACTION_CACHE_CLEAR,
                'enabled' => true,
                'discovered_at' => now(),
            ]);
        }

        return [$rule, $run, $user, $site, $organization, $membership];
    }

    private function makeOperation(
        User $requester,
        Site $site,
        string $key,
        array $target,
        string $status = Operation::STATUS_QUEUED,
    ): Operation {
        return Operation::create([
            'site_id' => $site->id,
            'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => $target,
            'status' => $status,
            'idempotency_key' => $key,
            'requested_by' => $requester->id,
        ]);
    }

    private function setRunStatus(AutomationRun $run, string $status): AutomationRun
    {
        if ($status === AutomationRun::STATUS_PENDING) {
            return $run;
        }

        $run = app(AutomationRunClaimService::class)->claim($run);
        if ($status === AutomationRun::STATUS_EVALUATING) {
            return $run;
        }

        $run->transitionTo(match ($status) {
            AutomationRun::STATUS_AWAITING_APPROVAL => AutomationRun::STATUS_AWAITING_APPROVAL,
            AutomationRun::STATUS_SUBMITTED,
            AutomationRun::STATUS_COMPLETED => AutomationRun::STATUS_SUBMITTED,
            default => $status,
        });
        $run->save();
        $run->refresh();
        if ($status === AutomationRun::STATUS_COMPLETED) {
            $run->transitionTo(AutomationRun::STATUS_COMPLETED);
            $run->save();
        }

        return $run;
    }

    private function runFor(AutomationRun $run): AutomationRun
    {
        return AutomationRun::query()->findOrFail($run->id);
    }
}
