<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\AutomationScheduleResolver;
use App\Services\AutomationSchedulerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AutomationSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_before_start_has_no_due_occurrence(): void
    {
        $rule = $this->makeRule();

        $this->assertNull(app(AutomationScheduleResolver::class)->resolve(
            $rule,
            CarbonImmutable::parse('2026-10-01T09:59:00Z'),
        ));
    }

    public function test_start_and_interval_boundaries_are_occurrences(): void
    {
        $rule = $this->makeRule();
        $resolver = app(AutomationScheduleResolver::class);

        $atStart = $resolver->resolve($rule, CarbonImmutable::parse('2026-10-01T10:00:00Z'));
        $atBoundary = $resolver->resolve($rule, CarbonImmutable::parse('2026-10-01T10:05:00Z'));

        $this->assertSame('2026-10-01T10:00:00Z', $atStart['scheduled_at']->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('schedule:v1:2026-10-01T10:00:00Z', $atStart['occurrence_key']);
        $this->assertSame('2026-10-01T10:05:00Z', $atBoundary['scheduled_at']->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_between_intervals_resolves_to_latest_occurrence(): void
    {
        $rule = $this->makeRule();
        $occurrence = app(AutomationScheduleResolver::class)->resolve(
            $rule,
            CarbonImmutable::parse('2026-10-01T10:07:59Z'),
        );

        $this->assertSame('2026-10-01T10:05:00Z', $occurrence['scheduled_at']->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_long_missed_period_resolves_only_the_latest_due_occurrence(): void
    {
        $rule = $this->makeRule(schedule: [
            'every_minutes' => 60,
            'starts_at_utc' => '2026-10-01T10:00:00Z',
        ]);
        $occurrence = app(AutomationScheduleResolver::class)->resolve(
            $rule,
            CarbonImmutable::parse('2026-10-01T15:30:00Z'),
        );

        $this->assertSame('2026-10-01T15:00:00Z', $occurrence['scheduled_at']->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_scheduler_creates_only_the_latest_run_after_multiple_missed_occurrences(): void
    {
        $rule = $this->makeRule(schedule: [
            'every_minutes' => 60,
            'starts_at_utc' => '2026-10-01T10:00:00Z',
        ]);

        $result = collect(app(AutomationSchedulerService::class)->processDueRules(
            CarbonImmutable::parse('2026-10-01T15:30:00Z'),
        ))->firstWhere('rule_id', $rule->id);

        $this->assertSame('2026-10-01T15:00:00Z', $result['scheduled_at']);
        $this->assertSame('schedule:v1:2026-10-01T15:00:00Z', $result['occurrence_key']);
        $this->assertSame(1, AutomationRun::query()->where('automation_rule_id', $rule->id)->count());
    }

    public function test_evaluation_time_is_normalized_to_utc_and_is_explicit(): void
    {
        $rule = $this->makeRule(schedule: [
            'every_minutes' => 5,
            'starts_at_utc' => '2026-10-01T08:00:00Z',
        ]);
        $resolver = app(AutomationScheduleResolver::class);

        $utc = $resolver->resolve($rule, CarbonImmutable::parse('2026-10-01T08:07:00Z'));
        $offset = $resolver->resolve($rule, CarbonImmutable::parse('2026-10-01T10:07:00+02:00'));
        $futureEvaluation = $resolver->resolve($rule, CarbonImmutable::parse('2030-01-01T00:00:00Z'));

        $this->assertSame($utc['occurrence_key'], $offset['occurrence_key']);
        $this->assertSame('2026-10-01T08:05:00Z', $offset['scheduled_at']->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2030-01-01T00:00:00Z', $futureEvaluation['scheduled_at']->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_schedule_resolver_rejects_invalid_shapes_and_intervals(): void
    {
        foreach ([
            ['frequency' => 'hourly'],
            ['every_minutes' => 0, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            ['every_minutes' => '5', 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00+00:00'],
        ] as $schedule) {
            $rule = $this->makeRule(schedule: $schedule);
            try {
                app(AutomationScheduleResolver::class)->resolve($rule, CarbonImmutable::parse('2026-10-01T12:00:00Z'));
                $this->fail('Invalid schedule was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_scheduler_skips_ineligible_rules_without_creating_runs(): void
    {
        $disabled = $this->makeRule(enabled: false);
        $wrongTrigger = $this->makeRule(name: 'wrong trigger');
        $unsupportedAction = $this->makeRule(name: 'unsupported action');
        $conditions = $this->makeRule(name: 'conditions');
        $badSchedule = $this->makeRule(name: 'bad schedule', schedule: ['bad' => true]);
        DB::table('automation_rules')->where('id', $wrongTrigger->id)->update(['trigger_type' => 'manual']);
        DB::table('automation_rules')->where('id', $unsupportedAction->id)->update(['action_type' => 'action.other']);
        DB::table('automation_rules')->where('id', $conditions->id)->update(['conditions_json' => '{"field":"x"}']);

        $results = app(AutomationSchedulerService::class)->processDueRules(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
        $byRule = collect($results)->keyBy('rule_id');

        $this->assertSame('disabled', $byRule[$disabled->id]['skip_reason']);
        $this->assertSame('unsupported_trigger', $byRule[$wrongTrigger->id]['skip_reason']);
        $this->assertSame('unsupported_action', $byRule[$unsupportedAction->id]['skip_reason']);
        $this->assertSame('conditions_unsupported', $byRule[$conditions->id]['skip_reason']);
        $this->assertSame('invalid_schedule', $byRule[$badSchedule->id]['skip_reason']);
        $this->assertSame(0, AutomationRun::query()->count());
    }

    public function test_scheduler_creates_and_claims_one_run_then_reuses_it_on_repeated_invocation(): void
    {
        $rule = $this->makeRule();
        $scheduler = app(AutomationSchedulerService::class);
        $evaluatedAt = CarbonImmutable::parse('2026-10-01T10:07:00Z');

        $first = collect($scheduler->processDueRules($evaluatedAt))->firstWhere('rule_id', $rule->id);
        $second = collect($scheduler->processDueRules($evaluatedAt))->firstWhere('rule_id', $rule->id);

        $this->assertTrue($first['run_created']);
        $this->assertFalse($first['run_reused']);
        $this->assertTrue($first['claimed']);
        $this->assertSame(AutomationRun::STATUS_FAILED, $first['run_status']);
        $this->assertSame('requester_unauthorized', $first['evaluation']['failure_code']);
        $this->assertFalse($second['run_created']);
        $this->assertTrue($second['run_reused']);
        $this->assertFalse($second['claimed']);
        $this->assertSame(AutomationRun::STATUS_FAILED, $second['run_status']);
        $this->assertSame(1, AutomationRun::query()->count());
    }

    public function test_scheduler_processes_a_new_occurrence_and_keeps_rules_isolated(): void
    {
        $firstRule = $this->makeRule();
        $secondRule = $this->makeRule(name: 'Second rule');
        $scheduler = app(AutomationSchedulerService::class);

        $first = $scheduler->processDueRules(CarbonImmutable::parse('2026-10-01T10:07:00Z'));
        $later = $scheduler->processDueRules(CarbonImmutable::parse('2026-10-01T10:12:00Z'));
        $byRuleFirst = collect($first)->keyBy('rule_id');
        $byRuleLater = collect($later)->keyBy('rule_id');

        $this->assertSame('schedule:v1:2026-10-01T10:05:00Z', $byRuleFirst[$firstRule->id]['occurrence_key']);
        $this->assertSame('schedule:v1:2026-10-01T10:10:00Z', $byRuleLater[$firstRule->id]['occurrence_key']);
        $this->assertSame($byRuleFirst[$firstRule->id]['occurrence_key'], $byRuleFirst[$secondRule->id]['occurrence_key']);
        $this->assertSame(4, AutomationRun::query()->count());
    }

    public function test_invalid_scope_and_nonclaimable_existing_run_are_reported_without_reset(): void
    {
        $rule = $this->makeRule();
        $otherOrganization = Organization::factory()->create();
        DB::table('automation_rules')->where('id', $rule->id)->update([
            'organization_id' => $otherOrganization->id,
        ]);
        $scopeResult = collect(app(AutomationSchedulerService::class)->processDueRules(
            CarbonImmutable::parse('2026-10-01T10:07:00Z'),
        ))->firstWhere('rule_id', $rule->id);
        $this->assertSame('invalid_scope', $scopeResult['skip_reason']);

        $validRule = $this->makeRule(name: 'Valid rule');
        $run = $this->makeRunFor($validRule, 'schedule:v1:2026-10-01T10:05:00Z', AutomationRun::STATUS_COMPLETED);
        $failedRule = $this->makeRule(name: 'Failed rule');
        $failedRun = $this->makeRunFor($failedRule, 'schedule:v1:2026-10-01T10:05:00Z', AutomationRun::STATUS_FAILED);
        $result = collect(app(AutomationSchedulerService::class)->processDueRules(
            CarbonImmutable::parse('2026-10-01T10:07:00Z'),
        ));
        $completedResult = $result->firstWhere('rule_id', $validRule->id);
        $failedResult = $result->firstWhere('rule_id', $failedRule->id);

        $this->assertTrue($completedResult['run_reused']);
        $this->assertFalse($completedResult['claimed']);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $completedResult['run_status']);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertTrue($failedResult['run_reused']);
        $this->assertFalse($failedResult['claimed']);
        $this->assertSame(AutomationRun::STATUS_FAILED, $failedResult['run_status']);
        $this->assertSame(AutomationRun::STATUS_FAILED, $failedRun->fresh()->status);
    }

    public function test_command_accepts_explicit_time_and_fails_closed_for_unauthorized_creator(): void
    {
        $rule = $this->makeRule();

        $exitCode = Artisan::call('sitepilot:automation-schedule', [
            '--at' => '2026-10-01T10:07:00Z',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('schedule:v1:2026-10-01T10:05:00Z', $output);
        $this->assertSame(1, AutomationRun::query()->where('automation_rule_id', $rule->id)->count());
        $this->assertSame(0, DB::table('operations')->count());
    }

    public function test_not_due_rule_is_skipped_without_creating_a_run(): void
    {
        $rule = $this->makeRule();
        $result = collect(app(AutomationSchedulerService::class)->processDueRules(
            CarbonImmutable::parse('2026-10-01T09:59:00Z'),
        ))->firstWhere('rule_id', $rule->id);

        $this->assertSame('not_due', $result['skip_reason']);
        $this->assertSame(0, AutomationRun::query()->count());
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
            'name' => $name ?? 'Scheduled automation',
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

    private function makeRunFor(AutomationRule $rule, string $key, string $status): AutomationRun
    {
        $run = AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'site_id' => $rule->site_id,
            'occurrence_key' => $key,
            'status' => AutomationRun::STATUS_PENDING,
        ]);

        $run->transitionToEvaluating();
        $run->save();
        $run->refresh();
        $run->transitionTo($status === AutomationRun::STATUS_COMPLETED
            ? AutomationRun::STATUS_SUBMITTED
            : $status);
        $run->save();
        if ($status === AutomationRun::STATUS_COMPLETED) {
            $run->refresh();
            $run->transitionTo(AutomationRun::STATUS_COMPLETED);
            $run->save();
        }

        return $run;
    }
}
