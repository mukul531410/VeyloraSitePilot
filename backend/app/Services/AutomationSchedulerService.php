<?php

namespace App\Services;

use App\Models\AutomationRule;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class AutomationSchedulerService
{
    public function __construct(
        private AutomationScheduleResolver $scheduleResolver,
        private AutomationRunService $runService,
        private AutomationRunClaimService $claimService,
        private AutomationRunEvaluationService $evaluationService,
    ) {}

    /** @return list<array<string, mixed>> */
    public function processDueRules(DateTimeInterface $evaluatedAt): array
    {
        $results = [];

        AutomationRule::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $rules) use ($evaluatedAt, &$results): void {
                foreach ($rules as $rule) {
                    $results[] = $this->processRule($rule, $evaluatedAt);
                }
            });

        return $results;
    }

    private function processRule(AutomationRule $rule, DateTimeInterface $evaluatedAt): array
    {
        $ineligibleReason = $this->ineligibleReason($rule);
        if ($ineligibleReason !== null) {
            return $this->skipped($rule, $ineligibleReason);
        }

        try {
            $occurrence = $this->scheduleResolver->resolve($rule, $evaluatedAt);
        } catch (InvalidArgumentException) {
            return $this->skipped($rule, 'invalid_schedule');
        }

        if ($occurrence === null) {
            return $this->skipped($rule, 'not_due');
        }

        try {
            $run = $this->runService->createForOccurrence($rule, $occurrence['scheduled_at']);
        } catch (InvalidArgumentException) {
            // The persisted rule may have changed after discovery. Revalidation in
            // AutomationRunService prevents creating a run for that stale rule.
            return $this->skipped($rule, 'rule_invalidated');
        }

        $created = $run->wasRecentlyCreated;
        $claimedRun = $this->claimService->claim($run);
        $evaluation = $claimedRun === null ? null : $this->evaluationService->evaluate($claimedRun);
        $currentRun = $claimedRun === null ? $run->fresh() : $claimedRun->fresh();

        return [
            'rule_id' => $rule->id,
            'scheduled_at' => $occurrence['scheduled_at']->format('Y-m-d\TH:i:s\Z'),
            'occurrence_key' => $occurrence['occurrence_key'],
            'run_id' => $run->id,
            'run_created' => $created,
            'run_reused' => ! $created,
            'skipped' => false,
            'skip_reason' => null,
            'claimed' => $claimedRun !== null,
            'run_status' => $currentRun->status,
            'evaluation' => $evaluation,
        ];
    }

    private function ineligibleReason(AutomationRule $rule): ?string
    {
        if (! $rule->enabled) {
            return 'disabled';
        }

        if ($rule->trigger_type !== AutomationRule::TRIGGER_SCHEDULE) {
            return 'unsupported_trigger';
        }

        if ($rule->action_type !== AutomationRule::ACTION_CACHE_CLEAR) {
            return 'unsupported_action';
        }

        if ($rule->conditions_json !== null) {
            return 'conditions_unsupported';
        }

        $siteOrganizationId = $rule->site()->value('organization_id');
        if ($siteOrganizationId === null || (string) $siteOrganizationId !== (string) $rule->organization_id) {
            return 'invalid_scope';
        }

        return null;
    }

    private function skipped(AutomationRule $rule, string $reason): array
    {
        return [
            'rule_id' => $rule->id,
            'scheduled_at' => null,
            'occurrence_key' => null,
            'run_id' => null,
            'run_created' => false,
            'run_reused' => false,
            'skipped' => true,
            'skip_reason' => $reason,
            'claimed' => false,
            'run_status' => null,
        ];
    }
}
