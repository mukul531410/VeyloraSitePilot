<?php

namespace App\Services;

use App\Exceptions\AutomationRuleException;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Rule management is configuration only. It never submits an Operation, so the
 * PolicyEngine and OperationService authorization boundaries stay where they
 * are: at evaluation time, when the run is evaluated as the original requester.
 */
class AutomationRuleService
{
    public const AUDIT_CREATED = 'automation_rule_created';

    public const AUDIT_UPDATED = 'automation_rule_updated';

    public const AUDIT_DELETED = 'automation_rule_deleted';

    public function __construct(
        private AutomationRuleAuthorization $authorization,
        private AutomationScheduleResolver $scheduleResolver,
    ) {}

    /**
     * @param  array{organization_id: string, site_id: string, name: string, enabled?: bool, schedule_json: array<string, mixed>, target_json: array<string, mixed>}  $attributes
     */
    public function create(User $actor, array $attributes): AutomationRule
    {
        $organization = Organization::query()->find($attributes['organization_id']);
        if ($organization === null) {
            throw new AutomationRuleException(AutomationRuleException::ORGANIZATION_MISSING);
        }

        $site = Site::query()->find($attributes['site_id']);
        if ($site === null) {
            throw new AutomationRuleException(AutomationRuleException::SITE_MISSING);
        }

        $this->assertSiteBelongsToOrganization($site, $organization);
        $this->authorization->authorizeManage($actor, $organization, $site);

        $rule = new AutomationRule([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => $attributes['name'],
            'enabled' => (bool) ($attributes['enabled'] ?? false),
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => $attributes['schedule_json'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => $attributes['target_json'],
            'created_by' => $actor->id,
        ]);

        $this->assertSupportedSchedule($rule);

        return DB::transaction(function () use ($rule, $actor, $organization, $site): AutomationRule {
            $rule->save();

            $this->audit(
                $rule,
                $actor,
                $organization,
                $site,
                self::AUDIT_CREATED,
                null,
                $this->snapshot($rule),
            );

            return $rule;
        });
    }

    /**
     * Scope, trigger, action and conditions are immutable. Only presentation,
     * enabled state and schedule may change, so a rule can never be repointed at
     * another site or action while its runs keep referencing it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, AutomationRule $rule, array $attributes): AutomationRule
    {
        [$organization, $site] = $this->resolveScope($rule);
        $this->authorization->authorizeManage($actor, $organization, $site);

        $before = $this->snapshot($rule);

        foreach (['name', 'enabled', 'schedule_json'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $rule->{$field} = $field === 'enabled' ? (bool) $attributes[$field] : $attributes[$field];
        }

        $this->assertSupportedSchedule($rule);

        return DB::transaction(function () use ($rule, $actor, $organization, $site, $before): AutomationRule {
            $rule->save();

            $this->audit(
                $rule,
                $actor,
                $organization,
                $site,
                self::AUDIT_UPDATED,
                $before,
                $this->snapshot($rule),
            );

            return $rule;
        });
    }

    public function delete(User $actor, AutomationRule $rule): void
    {
        [$organization, $site] = $this->resolveScope($rule);
        $this->authorization->authorizeManage($actor, $organization, $site);

        if ($rule->runs()->exists()) {
            throw new AutomationRuleException(AutomationRuleException::RULE_HAS_RUNS);
        }

        $before = $this->snapshot($rule);

        DB::transaction(function () use ($rule, $actor, $organization, $site, $before): void {
            $rule->delete();

            $this->audit(
                $rule,
                $actor,
                $organization,
                $site,
                self::AUDIT_DELETED,
                $before,
                null,
            );
        });
    }

    /** @return array{0: Organization, 1: Site} */
    private function resolveScope(AutomationRule $rule): array
    {
        if (! $rule->exists || $rule->getKey() === null) {
            throw new AutomationRuleException(AutomationRuleException::RULE_MISSING);
        }

        $source = AutomationRule::query()->with('site.organization')->find($rule->getKey());
        if ($source === null || $source->site === null || $source->site->organization === null) {
            throw new AutomationRuleException(AutomationRuleException::WRONG_TENANT);
        }

        $organization = $source->site->organization;
        if ((string) $source->organization_id !== (string) $organization->id) {
            throw new AutomationRuleException(AutomationRuleException::WRONG_TENANT);
        }

        return [$organization, $source->site];
    }

    private function assertSiteBelongsToOrganization(Site $site, Organization $organization): void
    {
        if ((string) $site->organization_id !== (string) $organization->id) {
            throw new AutomationRuleException(AutomationRuleException::SITE_NOT_IN_ORGANIZATION);
        }
    }

    /**
     * The occurrence resolver owns the MVP interval schedule contract. Reusing it
     * here keeps the API from accepting a rule the scheduler would later skip as
     * `invalid_schedule`.
     */
    private function assertSupportedSchedule(AutomationRule $rule): void
    {
        try {
            $this->scheduleResolver->resolve($rule, CarbonImmutable::now('UTC'));
        } catch (InvalidArgumentException) {
            throw new AutomationRuleException(AutomationRuleException::INVALID_SCHEDULE);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(AutomationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'site_id' => $rule->site_id,
            'name' => $rule->name,
            'enabled' => (bool) $rule->enabled,
            'trigger_type' => $rule->trigger_type,
            'schedule_json' => $rule->schedule_json,
            'conditions_json' => $rule->conditions_json,
            'action_type' => $rule->action_type,
            'target_json' => $rule->target_json,
            'created_by' => $rule->created_by,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(
        AutomationRule $rule,
        User $actor,
        Organization $organization,
        Site $site,
        string $action,
        ?array $before,
        ?array $after,
    ): void {
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'site_id' => $site->id,
            'action' => $action,
            'target_type' => 'automation_rule',
            'target_id' => $rule->id,
            'correlation_id' => Str::ulid()->toString(),
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => [
                'automation_rule_id' => $rule->id,
                'site_id' => $site->id,
                'action_type' => $rule->action_type,
                'trigger_type' => $rule->trigger_type,
                'enabled' => (bool) $rule->enabled,
                'actor_id' => $actor->id,
                'remote_execution_claimed' => false,
            ],
        ]);
    }
}
