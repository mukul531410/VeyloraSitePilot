<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\ConnectorCapability;
use App\Services\MaintenanceLock;
use App\Services\OperationRetryClassifier;
use App\Services\OperationRetryPreconditions;
use App\Services\PolicyEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DispatchOperationJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, Queueable;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public string $operationId,
    ) {}

    public function uniqueId(): string
    {
        return 'dispatch-operation:' . $this->operationId;
    }

    public function handle(
        MaintenanceLock $maintenanceLock,
        PolicyEngine $policyEngine,
        ?OperationRetryPreconditions $retryPreconditions = null,
    ): void {
        $lock = null;

        try {
            DB::transaction(function () use ($maintenanceLock, $policyEngine, $retryPreconditions, &$lock) {
                $operation = Operation::whereKey($this->operationId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $operation->load(['site', 'site.organization']);

                if ($operation->operation_type !== 'action.cache_clear') {
                    throw new RuntimeException('Unsupported operation type: ' . $operation->operation_type);
                }

                if ($operation->isTerminal() || $operation->status !== Operation::STATUS_QUEUED) {
                    return;
                }

                $attemptCount = $operation->attempts()->count();
                $previousAttempt = $operation->attempts()->orderByDesc('attempt_number')->first();
                $isSafeTimeoutRetry = $previousAttempt
                    && $previousAttempt->status === OperationAttempt::STATUS_TIMEOUT
                    && $previousAttempt->retryable === true;

                if ($isSafeTimeoutRetry && $attemptCount >= (int) $operation->max_attempts) {
                    $this->blockRetry($operation, $previousAttempt, 'max_attempts_exhausted', OperationRetryClassifier::NON_RETRYABLE);
                    return;
                }

                if ($isSafeTimeoutRetry) {
                    $retryPreconditions ??= app(OperationRetryPreconditions::class);
                    $preconditionFailure = $retryPreconditions->failureReason($operation, $policyEngine);
                    if ($preconditionFailure !== null) {
                        $this->blockRetry($operation, $previousAttempt, $preconditionFailure, OperationRetryClassifier::NON_RETRYABLE);
                        return;
                    }
                } elseif ($attemptCount >= (int) $operation->max_attempts) {
                    throw new RuntimeException('Maximum attempts reached for operation ' . $operation->id);
                }

                $site = $operation->site;

                $connection = $this->getActiveConnection($site);
                if (! $connection) {
                    throw new RuntimeException('No active site connection');
                }

                $capability = $this->getCapability($connection, $operation->operation_type);
                if (! $capability || ! $capability->enabled) {
                    throw new RuntimeException('Capability not granted: ' . $operation->operation_type);
                }

                $attemptNumber = ((int) ($operation->attempts()->max('attempt_number') ?? 0)) + 1;

                $connectorJobId = (string) Str::ulid();
                $lockToken = $operation->id . ':' . $attemptNumber;

                $lockAcquired = $maintenanceLock->acquire($site->id, $operation->id, $attemptNumber);
                if (! $lockAcquired) {
                    throw new RuntimeException('Could not acquire maintenance lock for site ' . $site->id);
                }

                $lock = [$site->id, $operation->id, $attemptNumber];

                $attempt = OperationAttempt::create([
                    'operation_id' => $operation->id,
                    'attempt_number' => $attemptNumber,
                    'status' => OperationAttempt::STATUS_DISPATCHED,
                    'connector_job_id' => $connectorJobId,
                    'lock_token' => $lockToken,
                    'started_at' => now(),
                    'timeout_at' => now()->addMinutes(2),
                ]);

                $operation->update([
                    'status' => Operation::STATUS_RUNNING,
                    'started_at' => now(),
                ]);

                $this->auditLog($operation, 'attempt_dispatched', [
                    'operation_id' => $operation->id,
                    'attempt_id' => $attempt->id,
                    'attempt_number' => $attemptNumber,
                    'connector_job_id' => $connectorJobId,
                    'lock_acquired' => true,
                ]);
            });
        } finally {
            if ($lock !== null) {
                $maintenanceLock->release($lock[0], $lock[1], $lock[2]);
            }
        }
    }

    private function getActiveConnection(Site $site): ?SiteConnection
    {
        return $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }

    private function getCapability(SiteConnection $connection, string $operationType): ?ConnectorCapability
    {
        return $connection->capabilities()
            ->where('capability_key', $operationType)
            ->where('enabled', true)
            ->first();
    }

    private function auditLog(Operation $operation, string $action, array $metadata): void
    {
        \App\Models\AuditLog::create([
            'organization_id' => $operation->site->organization_id,
            'user_id' => $operation->requested_by,
            'site_id' => $operation->site_id,
            'action' => $action,
            'target_type' => 'operation',
            'target_id' => $operation->id,
            'correlation_id' => $operation->id,
            'policy_result' => $operation->policy_result,
            'metadata_json' => array_merge(['operation_type' => $operation->operation_type], $metadata),
        ]);
    }

    private function blockRetry(
        Operation $operation,
        OperationAttempt $previousAttempt,
        string $reason,
        string $classification,
    ): void {
        $operation->update([
            'status' => Operation::STATUS_RUNNING,
            'finished_at' => null,
        ]);

        $this->auditLog($operation, 'attempt_retry_blocked', [
            'operation_id' => $operation->id,
            'previous_attempt_id' => $previousAttempt->id,
            'previous_attempt_number' => $previousAttempt->attempt_number,
            'previous_connector_job_id' => $previousAttempt->connector_job_id,
            'retry_classification' => $classification,
            'retry_reason' => $reason === 'max_attempts_exhausted' ? $reason : 'precondition_failed',
            'precondition_failure' => $reason === 'max_attempts_exhausted' ? null : $reason,
            'max_attempts' => (int) $operation->max_attempts,
            'blocked_at' => now('UTC')->toIso8601String(),
        ]);
    }
}
