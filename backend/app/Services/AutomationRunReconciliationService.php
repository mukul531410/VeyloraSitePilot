<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AutomationRun;
use App\Models\Operation;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class AutomationRunReconciliationService
{
    private const TERMINAL_RUN_STATUSES = [
        AutomationRun::STATUS_COMPLETED,
        AutomationRun::STATUS_FAILED,
        AutomationRun::STATUS_SKIPPED,
        AutomationRun::STATUS_UNKNOWN,
        AutomationRun::STATUS_CANCELLED,
        AutomationRun::STATUS_ABANDONED,
    ];

    /** @return array<string, mixed> */
    public function reconcile(AutomationRun|string $run): array
    {
        $runId = $run instanceof AutomationRun ? (string) $run->getKey() : $run;

        return DB::transaction(function () use ($runId): array {
            $lockedRun = AutomationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $previousStatus = $lockedRun->status;

            if ($lockedRun->operation_id === null) {
                return $this->result(
                    $lockedRun,
                    $previousStatus === AutomationRun::STATUS_EVALUATING ? 'stranded_evaluating' : 'missing_operation',
                    null,
                    null,
                    $previousStatus,
                );
            }

            $operation = Operation::query()->whereKey($lockedRun->operation_id)->lockForUpdate()->first();
            if ($operation === null) {
                return $this->conflict($lockedRun, null, 'linked_operation_missing', $previousStatus, null);
            }

            $site = Site::query()->find($operation->site_id);
            $reason = $this->ownershipFailure($lockedRun, $operation, $site);
            if ($reason !== null) {
                return $this->conflict($lockedRun, $operation, $reason, $previousStatus, $operation->status);
            }

            $targetStatus = $this->runStatusForOperation($operation->status);
            if ($targetStatus === null) {
                return $this->conflict($lockedRun, $operation, 'unsupported_operation_status', $previousStatus, $operation->status);
            }

            if ($targetStatus === $previousStatus) {
                return $this->result($lockedRun, 'unchanged', $operation, null, $previousStatus);
            }

            if (in_array($previousStatus, self::TERMINAL_RUN_STATUSES, true)
                && ! ($previousStatus === AutomationRun::STATUS_UNKNOWN
                    && in_array($targetStatus, [AutomationRun::STATUS_FAILED, AutomationRun::STATUS_CANCELLED], true))) {
                return $this->conflict($lockedRun, $operation, 'terminal_run_conflict', $previousStatus, $operation->status);
            }

            if (! $lockedRun->canTransitionTo($targetStatus)) {
                return $this->conflict($lockedRun, $operation, 'transition_not_allowed', $previousStatus, $operation->status);
            }

            $lockedRun->evaluation_metadata_json = array_merge(
                $lockedRun->evaluation_metadata_json ?? [],
                ['operation_status' => $operation->status, 'outcome' => 'reconciled'],
            );
            $lockedRun->transitionTo($targetStatus);
            $lockedRun->save();

            AuditLog::query()->create([
                'organization_id' => $lockedRun->organization_id,
                'site_id' => $lockedRun->site_id,
                'action' => 'automation_run_reconciled',
                'target_type' => 'automation_run',
                'target_id' => $lockedRun->id,
                'correlation_id' => $lockedRun->id,
                'after_json' => [
                    'status' => $lockedRun->status,
                    'operation_id' => $operation->id,
                ],
                'metadata_json' => [
                    'automation_rule_id' => $lockedRun->automation_rule_id,
                    'automation_run_id' => $lockedRun->id,
                    'operation_id' => $operation->id,
                    'previous_run_status' => $previousStatus,
                    'operation_status' => $operation->status,
                    'resulting_run_status' => $lockedRun->status,
                ],
            ]);

            return $this->result($lockedRun, 'reconciled', $operation, null, $previousStatus);
        });
    }

    private function ownershipFailure(AutomationRun $run, Operation $operation, ?Site $site): ?string
    {
        if ($site === null || $site->organization_id === null) {
            return 'operation_scope_missing';
        }

        if ((string) $operation->site_id !== (string) $run->site_id
            || (string) $site->organization_id !== (string) $run->organization_id) {
            return 'operation_scope_mismatch';
        }

        $expectedKey = 'automation:run:'.$run->id.':operation:v1';
        if ($operation->idempotency_key !== $expectedKey) {
            return 'operation_run_link_mismatch';
        }

        if (AutomationRun::query()
            ->where('operation_id', $operation->id)
            ->whereKeyNot($run->id)
            ->exists()) {
            return 'operation_linked_to_another_run';
        }

        return null;
    }

    private function runStatusForOperation(string $status): ?string
    {
        return match ($status) {
            Operation::STATUS_PENDING_APPROVAL => AutomationRun::STATUS_AWAITING_APPROVAL,
            Operation::STATUS_REQUESTED,
            Operation::STATUS_APPROVED,
            Operation::STATUS_QUEUED,
            Operation::STATUS_RUNNING,
            Operation::STATUS_VERIFICATION_PENDING => AutomationRun::STATUS_SUBMITTED,
            Operation::STATUS_SUCCEEDED => AutomationRun::STATUS_COMPLETED,
            Operation::STATUS_FAILED,
            Operation::STATUS_DEAD_LETTER => AutomationRun::STATUS_FAILED,
            Operation::STATUS_UNKNOWN => AutomationRun::STATUS_UNKNOWN,
            Operation::STATUS_CANCELLED => AutomationRun::STATUS_CANCELLED,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function conflict(
        AutomationRun $run,
        ?Operation $operation,
        string $reason,
        string $previousStatus,
        ?string $operationStatus,
    ): array {
        $metadata = [
            'automation_rule_id' => $run->automation_rule_id,
            'automation_run_id' => $run->id,
            'operation_id' => $operation?->id ?? $run->operation_id,
            'previous_run_status' => $previousStatus,
            'operation_status' => $operationStatus,
            'reason' => $reason,
        ];

        $alreadyAudited = AuditLog::query()
            ->where('action', 'automation_run_reconciliation_conflict')
            ->where('target_type', 'automation_run')
            ->where('target_id', $run->id)
            ->get(['metadata_json'])
            ->contains(fn (AuditLog $audit): bool => $audit->metadata_json === $metadata);

        if (! $alreadyAudited) {
            AuditLog::query()->create([
                'organization_id' => $run->organization_id,
                'site_id' => $run->site_id,
                'action' => 'automation_run_reconciliation_conflict',
                'target_type' => 'automation_run',
                'target_id' => $run->id,
                'correlation_id' => $run->id,
                'metadata_json' => $metadata,
            ]);
        }

        return $this->result($run, 'conflict', $operation, $reason, $previousStatus);
    }

    /** @return array<string, mixed> */
    private function result(
        AutomationRun $run,
        string $outcome,
        ?Operation $operation,
        ?string $reason,
        string $previousStatus,
    ): array {
        return [
            'run_id' => $run->id,
            'outcome' => $outcome,
            'previous_run_status' => $previousStatus,
            'run_status' => $run->status,
            'operation_id' => $operation?->id ?? $run->operation_id,
            'operation_status' => $operation?->status,
            'reason' => $reason,
        ];
    }
}
