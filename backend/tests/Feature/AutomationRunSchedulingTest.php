<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\AutomationRunClaimService;
use App\Services\AutomationRunService;
use App\Services\ScheduledOccurrenceResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use Tests\TestCase;

class AutomationRunSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_rule_and_occurrence_returns_one_existing_run(): void
    {
        $rule = $this->makeRule();
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $at = CarbonImmutable::parse('2026-10-01T10:05:00Z');
        $service = app(AutomationRunService::class);

        $first = $service->createForOccurrence($rule, $at);
        $second = $service->createForOccurrence($rule, $at);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AutomationRun::query()->count());
        $this->assertSame(AutomationRun::STATUS_PENDING, $first->status);
    }

    public function test_different_occurrences_of_one_rule_create_distinct_runs(): void
    {
        $rule = $this->makeRule();
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $service = app(AutomationRunService::class);

        $first = $service->createForOccurrence($rule, CarbonImmutable::parse('2026-10-01T10:05:00Z'));
        $second = $service->createForOccurrence($rule, CarbonImmutable::parse('2026-10-01T10:10:00Z'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->occurrence_key, $second->occurrence_key);
        $this->assertSame(2, AutomationRun::query()->count());
    }

    public function test_same_timestamp_for_different_rules_creates_distinct_runs(): void
    {
        [$rule, $user, $organization, $site] = $this->makeRuleScope();
        $otherRule = $this->makeRule($user, $organization, $site, ['name' => 'Second rule']);
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $at = CarbonImmutable::parse('2026-10-01T10:05:00Z');
        $service = app(AutomationRunService::class);

        $first = $service->createForOccurrence($rule, $at);
        $second = $service->createForOccurrence($otherRule, $at);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->occurrence_key, $second->occurrence_key);
        $this->assertSame(2, AutomationRun::query()->count());
    }

    public function test_occurrence_key_is_stable_and_uses_normalized_utc_timestamp(): void
    {
        $rule = $this->makeRule(schedule: [
            'every_minutes' => 5,
            'starts_at_utc' => '2026-10-01T08:00:00Z',
        ]);
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $resolver = app(ScheduledOccurrenceResolver::class);

        $first = $resolver->occurrenceKey($rule, CarbonImmutable::parse('2026-10-01T08:05:00Z'));
        $sameInstantDifferentOffset = $resolver->occurrenceKey($rule, CarbonImmutable::parse('2026-10-01T10:05:00+02:00'));

        $this->assertSame('schedule:v1:2026-10-01T08:05:00Z', $first);
        $this->assertSame($first, $sameInstantDifferentOffset);
    }

    public function test_different_scheduled_timestamps_have_different_occurrence_keys(): void
    {
        $rule = $this->makeRule();
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $resolver = app(ScheduledOccurrenceResolver::class);

        $first = $resolver->occurrenceKey($rule, CarbonImmutable::parse('2026-10-01T10:05:00Z'));
        $second = $resolver->occurrenceKey($rule, CarbonImmutable::parse('2026-10-01T10:10:00Z'));

        $this->assertNotSame($first, $second);
    }

    public function test_occurrence_key_rejects_fractional_seconds(): void
    {
        $rule = $this->makeRule();

        $this->expectException(InvalidArgumentException::class);
        app(ScheduledOccurrenceResolver::class)->occurrenceKey(
            $rule,
            CarbonImmutable::parse('2026-10-01T10:05:00.000001Z'),
        );
    }

    public function test_schedule_resolution_rejects_invalid_shape_and_off_cadence_occurrences(): void
    {
        $rule = $this->makeRule(schedule: ['frequency' => 'hourly']);
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $resolver = app(ScheduledOccurrenceResolver::class);

        try {
            $resolver->occurrenceKey($rule, CarbonImmutable::parse('2026-10-01T10:00:00Z'));
            $this->fail('Unsupported schedule shape was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $rule->schedule_json = ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'];
        $this->expectException(InvalidArgumentException::class);
        $resolver->occurrenceKey($rule, CarbonImmutable::parse('2026-10-01T10:06:00Z'));
    }

    public function test_first_claim_transitions_pending_run_to_evaluating(): void
    {
        $run = $this->makeRun();

        $claimed = app(AutomationRunClaimService::class)->claim($run);

        $this->assertNotNull($claimed);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $claimed->status);
        $this->assertNotNull($claimed->started_at);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
    }

    public function test_second_claim_returns_not_claimable_and_preserves_state(): void
    {
        $run = $this->makeRun();
        $service = app(AutomationRunClaimService::class);
        $first = $service->claim($run);
        $second = $service->claim($run->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
    }

    public function test_only_pending_runs_are_claimable(): void
    {
        foreach ([
            AutomationRun::STATUS_EVALUATING,
            AutomationRun::STATUS_AWAITING_APPROVAL,
            AutomationRun::STATUS_SUBMITTED,
            AutomationRun::STATUS_COMPLETED,
            AutomationRun::STATUS_FAILED,
            AutomationRun::STATUS_SKIPPED,
        ] as $index => $status) {
            $run = $this->makeRun(status: $status, occurrenceKey: 'not-claimable-'.$index);
            $this->assertNull(app(AutomationRunClaimService::class)->claim($run));
            $this->assertSame($status, $run->fresh()->status);
        }
    }

    public function test_automation_run_model_rejects_arbitrary_status_changes(): void
    {
        $run = $this->makeRun();
        $run->status = AutomationRun::STATUS_EVALUATING;

        $this->expectException(InvalidArgumentException::class);
        $run->save();
    }

    public function test_disabled_or_inconsistent_rules_are_rejected(): void
    {
        $rule = $this->makeRule(enabled: false);
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $at = CarbonImmutable::parse('2026-10-01T10:05:00Z');

        try {
            app(AutomationRunService::class)->createForOccurrence($rule, $at);
            $this->fail('Disabled rule created a run.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $otherOrganization = Organization::factory()->create();
        DB::table('automation_rules')
            ->where('id', $rule->id)
            ->update(['organization_id' => $otherOrganization->id, 'enabled' => true]);

        $this->expectException(InvalidArgumentException::class);
        app(AutomationRunService::class)->createForOccurrence($rule, $at);
    }

    public function test_unsupported_trigger_action_and_conditions_are_rejected(): void
    {
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $at = CarbonImmutable::parse('2026-10-01T10:05:00Z');
        $service = app(AutomationRunService::class);

        foreach ([
            ['trigger_type' => 'manual'],
            ['action_type' => 'action.unsupported'],
            ['conditions_json' => json_encode(['anything' => true])],
        ] as $index => $overrides) {
            $rule = $this->makeRule(name: 'Invalid '.$index);
            DB::table('automation_rules')
                ->where('id', $rule->id)
                ->update($overrides);

            try {
                $service->createForOccurrence($rule, $at);
                $this->fail('Unsupported rule was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_unrelated_database_errors_are_not_swallowed(): void
    {
        $rule = $this->makeRule();
        Carbon::setTestNow('2026-10-01T11:00:00Z');
        $expected = new QueryException('sqlite', 'insert into automation_runs', [], new PDOException('unrelated database failure'));
        $service = new class(app(ScheduledOccurrenceResolver::class), $expected) extends AutomationRunService
        {
            public function __construct(ScheduledOccurrenceResolver $resolver, private QueryException $expected)
            {
                parent::__construct($resolver);
            }

            protected function persistRun(array $attributes): AutomationRun
            {
                throw $this->expected;
            }
        };

        try {
            $service->createForOccurrence($rule, CarbonImmutable::parse('2026-10-01T10:05:00Z'));
            $this->fail('Unrelated database error was swallowed.');
        } catch (QueryException $actual) {
            $this->assertSame($expected, $actual);
        }
    }

    private function makeRuleScope(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        return [$this->makeRule($user, $organization, $site), $user, $organization, $site];
    }

    private function makeRule(
        ?User $user = null,
        ?Organization $organization = null,
        ?Site $site = null,
        array $overrides = [],
        bool $enabled = true,
        ?string $name = null,
        ?array $schedule = null,
    ): AutomationRule {
        $user ??= User::factory()->create();
        $organization ??= Organization::factory()->create();
        $site ??= Site::factory()->create(['organization_id' => $organization->id]);

        return AutomationRule::create(array_merge([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => $name ?? 'Scheduled cache clear',
            'enabled' => $enabled,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => $schedule ?? [
                'every_minutes' => 5,
                'starts_at_utc' => '2026-10-01T10:00:00Z',
            ],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $user->id,
        ], $overrides));
    }

    private function makeRun(
        string $status = AutomationRun::STATUS_PENDING,
        string $occurrenceKey = 'schedule:v1:2026-10-01T10:05:00Z',
    ): AutomationRun {
        $rule = $this->makeRule();

        $run = AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'site_id' => $rule->site_id,
            'operation_id' => null,
            'occurrence_key' => $occurrenceKey,
            'status' => AutomationRun::STATUS_PENDING,
        ]);

        if ($status === AutomationRun::STATUS_PENDING) {
            return $run;
        }

        $run->transitionToEvaluating();
        $run->save();
        $run->refresh();
        if ($status === AutomationRun::STATUS_EVALUATING) {
            return $run;
        }

        $firstTransition = match ($status) {
            AutomationRun::STATUS_AWAITING_APPROVAL => AutomationRun::STATUS_AWAITING_APPROVAL,
            AutomationRun::STATUS_SUBMITTED,
            AutomationRun::STATUS_COMPLETED => AutomationRun::STATUS_SUBMITTED,
            AutomationRun::STATUS_FAILED,
            AutomationRun::STATUS_SKIPPED => $status,
        };
        $run->transitionTo($firstTransition);
        $run->save();

        if ($status === AutomationRun::STATUS_COMPLETED) {
            $run->refresh();
            $run->transitionTo(AutomationRun::STATUS_COMPLETED);
            $run->save();
        }

        return $run;
    }
}
