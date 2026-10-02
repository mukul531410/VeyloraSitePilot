<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Operation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AutomationFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_stores_supported_scheduled_cache_clear_configuration_and_relationships(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $rule = $this->makeRule($user, $organization, $site);

        $this->assertTrue($rule->exists);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $rule->id);
        $this->assertSame($organization->id, $rule->organization->id);
        $this->assertSame($site->id, $rule->site->id);
        $this->assertSame($user->id, $rule->creator->id);
        $this->assertSame(['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'], $rule->schedule_json);
        $this->assertNull($rule->conditions_json);
        $this->assertSame(['cache_type' => 'wordpress'], $rule->target_json);
        $this->assertSame(AutomationRule::ACTION_CACHE_CLEAR, $rule->action_type);
        $this->assertFalse($rule->enabled);
    }

    public function test_rule_rejects_non_null_conditions_and_unsupported_action_or_trigger(): void
    {
        [$user, $organization, $site] = $this->makeScope();

        foreach ([
            ['conditions_json' => ['unsupported' => true]],
            ['action_type' => 'action.other'],
            ['trigger_type' => 'manual'],
        ] as $overrides) {
            try {
                $this->makeRule($user, $organization, $site, $overrides);
                $this->fail('Unsupported automation rule data was saved.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_rule_rejects_a_site_from_another_organization(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $otherOrganization = Organization::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->makeRule($user, $organization, $site, ['site_id' => Site::factory()->create([
            'organization_id' => $otherOrganization->id,
        ])->id]);
    }

    public function test_run_has_supported_status_nullable_operation_and_timestamps(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $rule = $this->makeRule($user, $organization, $site);
        $run = $this->makeRun($rule, '2026-10-01T10:00:00Z');

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $run->id);
        $this->assertSame($rule->id, $run->automationRule->id);
        $this->assertSame($organization->id, $run->organization->id);
        $this->assertSame($site->id, $run->site->id);
        $this->assertNull($run->operation_id);
        $this->assertNull($run->operation);
        $this->assertSame(AutomationRun::STATUS_PENDING, $run->status);
        $this->assertNotNull($run->created_at);
        $this->assertNotNull($run->updated_at);
    }

    public function test_run_can_reference_an_operation_from_its_site(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $rule = $this->makeRule($user, $organization, $site);
        $operation = Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_QUEUED,
            'idempotency_key' => 'automation-test-op',
            'requested_by' => $user->id,
        ]);

        $run = $this->makeRun($rule, 'occurrence-with-operation', ['operation_id' => $operation->id]);

        $this->assertSame($operation->id, $run->operation->id);
    }

    public function test_run_rejects_invalid_status_and_cross_scope_associations(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $rule = $this->makeRule($user, $organization, $site);

        try {
            $this->makeRun($rule, 'invalid-status', ['status' => 'unknown']);
            $this->fail('Invalid run status was saved.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $otherOrganization = Organization::factory()->create();
        $otherSite = Site::factory()->create(['organization_id' => $otherOrganization->id]);
        $this->expectException(InvalidArgumentException::class);
        $this->makeRun($rule, 'cross-scope', ['site_id' => $otherSite->id]);
    }

    public function test_unknown_and_cancelled_statuses_are_reached_only_through_allowed_transitions(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $rule = $this->makeRule($user, $organization, $site);
        $unknown = $this->makeRun($rule, 'unknown-transition');
        $unknown->transitionToEvaluating();
        $unknown->save();
        $unknown->refresh();
        $unknown->transitionTo(AutomationRun::STATUS_SUBMITTED);
        $unknown->save();
        $unknown->refresh();
        $unknown->transitionTo(AutomationRun::STATUS_UNKNOWN);
        $unknown->save();
        $this->assertSame(AutomationRun::STATUS_UNKNOWN, $unknown->fresh()->status);

        $cancelled = $this->makeRun($rule, 'cancelled-transition');
        $cancelled->transitionToEvaluating();
        $cancelled->save();
        $cancelled->refresh();
        $cancelled->transitionTo(AutomationRun::STATUS_AWAITING_APPROVAL);
        $cancelled->save();
        $cancelled->refresh();
        $cancelled->transitionTo(AutomationRun::STATUS_CANCELLED);
        $cancelled->save();
        $this->assertSame(AutomationRun::STATUS_CANCELLED, $cancelled->fresh()->status);

        $cancelled->refresh();
        $this->expectException(InvalidArgumentException::class);
        $cancelled->transitionTo(AutomationRun::STATUS_SUBMITTED);
    }

    public function test_operation_can_be_linked_to_only_one_automation_run(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $rule = $this->makeRule($user, $organization, $site);
        $operation = Operation::create([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_QUEUED,
            'idempotency_key' => 'unique-run-operation',
            'requested_by' => $user->id,
        ]);
        $this->makeRun($rule, 'linked-operation-1', ['operation_id' => $operation->id]);

        $this->expectException(QueryException::class);
        $this->makeRun($rule, 'linked-operation-2', ['operation_id' => $operation->id]);
    }

    public function test_run_occurrence_is_unique_per_rule_but_reusable_by_another_rule(): void
    {
        [$user, $organization, $site] = $this->makeScope();
        $firstRule = $this->makeRule($user, $organization, $site);
        $this->makeRun($firstRule, 'same-occurrence');

        try {
            $this->makeRun($firstRule, 'same-occurrence');
            $this->fail('Duplicate occurrence for one rule was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $secondRule = $this->makeRule($user, $organization, $site, ['name' => 'Second rule']);
        $secondRun = $this->makeRun($secondRule, 'same-occurrence');
        $this->assertSame('same-occurrence', $secondRun->occurrence_key);
    }

    public function test_automation_foreign_keys_reject_missing_references(): void
    {
        $this->expectException(QueryException::class);

        AutomationRule::query()->create([
            'organization_id' => '01J00000000000000000000000',
            'site_id' => '01J00000000000000000000001',
            'name' => 'Invalid FK',
            'enabled' => false,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => [],
            'created_by' => 999999,
        ]);
    }

    private function makeScope(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        return [$user, $organization, $site];
    }

    private function makeRule(User $user, Organization $organization, Site $site, array $overrides = []): AutomationRule
    {
        return AutomationRule::create(array_merge([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Scheduled cache clear',
            'enabled' => false,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $user->id,
        ], $overrides));
    }

    private function makeRun(AutomationRule $rule, string $occurrenceKey, array $overrides = []): AutomationRun
    {
        return AutomationRun::create(array_merge([
            'automation_rule_id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'site_id' => $rule->site_id,
            'operation_id' => null,
            'occurrence_key' => $occurrenceKey,
            'status' => AutomationRun::STATUS_PENDING,
            'evaluation_metadata_json' => null,
            'failure_code' => null,
            'failure_message' => null,
        ], $overrides));
    }
}
