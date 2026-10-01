<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Services\OperationRetryClassifier;
use App\Services\OperationRetryPreconditions;
use App\Services\PolicyEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

class DetectExpiredOperationAttempts implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(
        OperationRetryClassifier $retryClassifier,
        OperationRetryPreconditions $retryPreconditions,
        PolicyEngine $policyEngine,
    ): void
    {
        $detectedAt = now('UTC');

        OperationAttempt::query()
            ->whereIn('status', [OperationAttempt::STATUS_DISPATCHED, OperationAttempt::STATUS_ACCEPTED, OperationAttempt::STATUS_EXECUTING])
            ->whereNotNull('timeout_at')
            ->where('timeout_at', '<=', $detectedAt)
            ->whereHas('operation', fn ($query) => $query->where('status', Operation::STATUS_RUNNING))
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use ($retryClassifier, $retryPreconditions, $policyEngine): void {
                foreach ($attempts as $candidate) {
                    $retryScheduled = $this->processAttempt(
                        $candidate->id,
                        $retryClassifier,
                        $retryPreconditions,
                        $policyEngine,
                    );

                    if ($retryScheduled) {
                        try {
                            DispatchOperationJob::dispatch($candidate->operation_id);
                        } catch (\Throwable $exception) {
                            $this->recordRetryDispatchFailure(
                                $candidate->operation_id,
                                (int) $candidate->attempt_number + 1,
                                $exception,
                            );
                        }
                    }
                }
            });
    }

    private function processAttempt(
        string $attemptId,
        OperationRetryClassifier $retryClassifier,
        OperationRetryPreconditions $retryPreconditions,
        PolicyEngine $policyEngine,
    ): bool {
        return DB::transaction(function () use ($attemptId, $retryClassifier, $retryPreconditions, $policyEngine): bool {
            $attempt = OperationAttempt::whereKey($attemptId)->lockForUpdate()->first();
            if (! $attempt) {
                return false;
            }

            $operation = Operation::whereKey($attempt->operation_id)->lockForUpdate()->first();
            if (! $operation
                || $operation->isTerminal()
                || $operation->status !== Operation::STATUS_RUNNING
                || ! in_array($attempt->status, [
                    OperationAttempt::STATUS_DISPATCHED,
                    OperationAttempt::STATUS_ACCEPTED,
                    OperationAttempt::STATUS_EXECUTING,
                ], true)) {
                return false;
            }

            $detectedAt = now('UTC');
            if (! $attempt->timeout_at || $attempt->timeout_at->gt($detectedAt)) {
                return false;
            }

            $previousAttemptStatus = $attempt->status;
            $previousOperationStatus = $operation->status;
            $retryable = $previousAttemptStatus === OperationAttempt::STATUS_DISPATCHED;

            $attempt->update([
                'status' => OperationAttempt::STATUS_TIMEOUT,
                'retryable' => $retryable,
                'error_code' => OperationAttempt::ERROR_TIMEOUT,
                'finished_at' => $detectedAt,
            ]);

            if (! $retryable) {
                $operation->update([
                    'status' => Operation::STATUS_UNKNOWN,
                    'finished_at' => $detectedAt,
                ]);
            }

            $operation->loadMissing('site');
            $classification = $retryClassifier->classifyAttemptFailure($attempt->fresh());
            $metadata = [
                'operation_id' => $operation->id,
                'attempt_id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'connector_job_id' => $attempt->connector_job_id,
                'previous_attempt_status' => $previousAttemptStatus,
                'new_attempt_status' => OperationAttempt::STATUS_TIMEOUT,
                'previous_operation_status' => $previousOperationStatus,
                'new_operation_status' => $operation->fresh()->status,
                'timeout_at' => $attempt->timeout_at?->toIso8601String(),
                'detected_at' => $detectedAt->toIso8601String(),
                'retry_classification' => $classification,
            ];

            $this->audit($operation, 'attempt_timeout', $metadata);

            if (! $retryable) {
                $this->audit($operation, 'operation_unknown', $metadata);
                return false;
            }

            $classification = $retryClassifier->classifyAttemptFailure($attempt->fresh());
            $attemptCount = $operation->attempts()->count();
            if ($classification !== OperationRetryClassifier::SAFE_AUTOMATIC_RETRY) {
                return false;
            }

            $maxAttempts = (int) $operation->max_attempts;
            if ($attempt->attempt_number >= $maxAttempts || $attemptCount >= $maxAttempts) {
                $operation->update([
                    'status' => Operation::STATUS_DEAD_LETTER,
                    'finished_at' => $detectedAt,
                ]);

                $this->audit($operation, 'operation_dead_lettered', [
                    'operation_id' => $operation->id,
                    'attempt_id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
                    'max_attempts' => $maxAttempts,
                    'connector_job_id' => $attempt->connector_job_id,
                    'error_code' => OperationAttempt::ERROR_TIMEOUT,
                    'timeout_at' => $attempt->timeout_at?->toIso8601String(),
                    'detected_at' => $detectedAt->toIso8601String(),
                ]);

                return false;
            }

            $preconditionFailure = $retryPreconditions->failureReason($operation, $policyEngine);
            if ($preconditionFailure !== null) {
                $this->audit($operation, 'attempt_retry_blocked', array_merge($metadata, [
                    'retry_classification' => OperationRetryClassifier::NON_RETRYABLE,
                    'retry_reason' => 'precondition_failed',
                    'precondition_failure' => $preconditionFailure,
                    'max_attempts' => (int) $operation->max_attempts,
                ]));
                return false;
            }

            $nextAttemptNumber = $attempt->attempt_number + 1;
            $scheduledAt = now('UTC');
            $operation->update([
                'status' => Operation::STATUS_QUEUED,
                'finished_at' => null,
            ]);

            $this->audit($operation, 'attempt_retry_scheduled', [
                'operation_id' => $operation->id,
                'previous_attempt_id' => $attempt->id,
                'previous_attempt_number' => $attempt->attempt_number,
                'new_attempt_number' => $nextAttemptNumber,
                'previous_connector_job_id' => $attempt->connector_job_id,
                'retry_classification' => $classification,
                'retry_reason' => 'expired_dispatched_attempt',
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'max_attempts' => (int) $operation->max_attempts,
            ]);

            return true;
        });
    }

    private function audit(Operation $operation, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $operation->site->organization_id,
            'user_id' => null,
            'site_id' => $operation->site_id,
            'action' => $action,
            'target_type' => 'operation',
            'target_id' => $operation->id,
            'correlation_id' => $operation->id,
            'policy_result' => $operation->policy_result,
            'metadata_json' => $metadata,
        ]);
    }

    private function recordRetryDispatchFailure(string $operationId, int $newAttemptNumber, \Throwable $exception): void
    {
        DB::transaction(function () use ($operationId, $newAttemptNumber, $exception): void {
            $operation = Operation::whereKey($operationId)->lockForUpdate()->first();
            if (! $operation) {
                return;
            }

            $operation->loadMissing('site');
            $attemptCreated = $operation->attempts()->where('attempt_number', $newAttemptNumber)->exists();
            $this->audit($operation, $attemptCreated ? 'attempt_retry_dispatch_warning' : 'attempt_retry_dispatch_failed', [
                'operation_id' => $operation->id,
                'new_attempt_number' => $newAttemptNumber,
                'retry_classification' => OperationRetryClassifier::SAFE_AUTOMATIC_RETRY,
                'retry_reason' => $attemptCreated ? 'dispatch_cleanup_failed_after_attempt_creation' : 'retry_dispatch_failed',
                'failure_type' => $exception::class,
                'failed_at' => now('UTC')->toIso8601String(),
                'operation_status' => $operation->status,
            ]);
        });
    }
}
