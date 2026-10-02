<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationRunIntent;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AutomationRunService
{
    public function __construct(private ScheduledOccurrenceResolver $occurrenceResolver) {}

    public function createForOccurrence(AutomationRule $rule, DateTimeInterface $scheduledAt): AutomationRun
    {
        if (! $rule->exists) {
            throw new InvalidArgumentException('Automation rule must be persisted.');
        }

        $currentRule = AutomationRule::query()->findOrFail($rule->getKey());
        $this->assertEligibleRule($currentRule);
        $occurrenceKey = $this->occurrenceResolver->occurrenceKey($currentRule, $scheduledAt);

        try {
            return DB::transaction(function () use ($currentRule, $occurrenceKey): AutomationRun {
                $run = $this->persistRun([
                    'automation_rule_id' => $currentRule->id,
                    'organization_id' => $currentRule->organization_id,
                    'site_id' => $currentRule->site_id,
                    'occurrence_key' => $occurrenceKey,
                    'status' => AutomationRun::STATUS_PENDING,
                ]);

                $site = $currentRule->site()->firstOrFail();
                $organization = $currentRule->organization()->firstOrFail();
                AutomationRunIntent::query()->create([
                    'automation_run_id' => $run->id,
                    'intent_version' => 1,
                    'captured_at' => now('UTC'),
                    'organization_id' => $currentRule->organization_id,
                    'site_id' => $currentRule->site_id,
                    'automation_rule_id' => $currentRule->id,
                    'occurrence_key' => $occurrenceKey,
                    'original_operation_type' => $currentRule->action_type,
                    'original_target_json' => $currentRule->target_json,
                    'original_requester_id' => $currentRule->created_by,
                    'original_idempotency_key' => sprintf('automation:run:%s:operation:v1', $run->id),
                    'policy_context_snapshot' => [
                        'organization_approval_policy' => $organization->approval_policy,
                        'site_business_criticality' => $site->business_criticality,
                        'rule_trigger_type' => $currentRule->trigger_type,
                        'rule_schedule_json' => $currentRule->schedule_json,
                        'rule_conditions_json' => $currentRule->conditions_json,
                    ],
                ]);

                return $run;
            });
        } catch (QueryException $exception) {
            if (! $this->isOccurrenceUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = AutomationRun::query()
                ->where('automation_rule_id', $currentRule->id)
                ->where('occurrence_key', $occurrenceKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            if ((string) $existing->organization_id !== (string) $currentRule->organization_id
                || (string) $existing->site_id !== (string) $currentRule->site_id) {
                throw new RuntimeException('Occurrence key conflict: existing run has inconsistent ownership.');
            }

            return $existing;
        }
    }

    protected function persistRun(array $attributes): AutomationRun
    {
        return AutomationRun::query()->create($attributes);
    }

    protected function isOccurrenceUniqueViolation(QueryException $exception): bool
    {
        $driver = DB::connection($exception->getConnectionName())->getDriverName();
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());
        $index = 'automation_runs_automation_rule_id_occurrence_key_unique';

        return match ($driver) {
            'sqlite' => $sqlState === '23000'
                && $driverCode === 19
                && str_contains($message, 'UNIQUE constraint failed: automation_runs.automation_rule_id, automation_runs.occurrence_key'),
            'mysql' => $sqlState === '23000' && $driverCode === 1062 && str_contains($message, $index),
            'pgsql' => $sqlState === '23505' && str_contains($message, $index),
            'sqlsrv' => in_array($driverCode, [2601, 2627], true) && str_contains($message, $index),
            default => false,
        };
    }

    private function assertEligibleRule(AutomationRule $rule): void
    {
        if (! $rule->enabled) {
            throw new InvalidArgumentException('Disabled automation rules cannot create runs.');
        }

        if ($rule->trigger_type !== AutomationRule::TRIGGER_SCHEDULE) {
            throw new InvalidArgumentException('Only scheduled automation rules are supported.');
        }

        if ($rule->action_type !== AutomationRule::ACTION_CACHE_CLEAR) {
            throw new InvalidArgumentException('Only action.cache_clear is supported.');
        }

        if ($rule->conditions_json !== null) {
            throw new InvalidArgumentException('Automation conditions are not supported yet.');
        }

        $siteOrganizationId = $rule->site()->value('organization_id');
        if ($siteOrganizationId === null || (string) $siteOrganizationId !== (string) $rule->organization_id) {
            throw new InvalidArgumentException('Automation rule site must belong to its organization.');
        }
    }
}
