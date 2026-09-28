<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Services\OperationRetryClassifier;
use App\Services\VerificationEngine;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class VerifyOperationAttempt implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(public string $attemptId) {}

    public function uniqueId(): string
    {
        return 'verify-operation-attempt:'.$this->attemptId;
    }

    public function handle(VerificationEngine $engine, ?OperationRetryClassifier $retryClassifier = null): void
    {
        $retryClassifier ??= app(OperationRetryClassifier::class);
        DB::transaction(function () use ($engine, $retryClassifier) {
            $attempt = OperationAttempt::whereKey($this->attemptId)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($attempt->operation_id)->lockForUpdate()->firstOrFail();
            $result = OperationResult::where('operation_attempt_id', $attempt->id)->lockForUpdate()->first();
            if (! $result
                || $attempt->status !== OperationAttempt::STATUS_RESULT_RECEIVED
                || $result->verification_status !== OperationResult::VERIFICATION_PENDING) {
                return;
            }
            $attempt->setRelation('operation', $operation);
            $connectionId = $attempt->claimed_by_connection_id;
            $startedAt = CarbonImmutable::now('UTC');
            $this->audit($attempt, 'verification_started', $connectionId, ['verification_started_at' => $startedAt->toIso8601String()]);

            if ($operation->status !== Operation::STATUS_VERIFICATION_PENDING) {
                $this->failVerification($attempt, $result, OperationResult::VERIFICATION_ERROR_OPERATION_STATE_CHANGED, $connectionId, $retryClassifier);

                return;
            }

            $target = $attempt->operation->target_json ?? [];
            $outcome = $engine->verify(
                $result->actual_state_json ?? [],
                $target['cache_type'] ?? '',
                $result->cache_generation,
                $startedAt,
            );

            if ($outcome['verified']) {
                $result->update(['verification_status' => OperationResult::VERIFICATION_PASSED, 'verification_error' => null, 'verified_at' => now('UTC')]);
                $attempt->update(['status' => OperationAttempt::STATUS_SUCCEEDED, 'finished_at' => now('UTC')]);
                $attempt->operation->update(['status' => Operation::STATUS_SUCCEEDED, 'finished_at' => now('UTC')]);
                $this->audit($attempt, 'verification_verified', $connectionId, ['verification_status' => OperationResult::VERIFICATION_PASSED]);
            } else {
                $this->failVerification($attempt, $result, $outcome['error'], $connectionId, $retryClassifier);
            }
        });
    }

    private function failVerification(
        OperationAttempt $attempt,
        OperationResult $result,
        string $error,
        ?string $connectionId,
        OperationRetryClassifier $retryClassifier,
    ): void
    {
        $result->update([
            'verification_status' => OperationResult::VERIFICATION_FAILED,
            'verification_error' => $error,
            'verified_at' => now('UTC'),
        ]);
        $attemptChanges = ['error_code' => $error];
        if ($retryClassifier->classifyVerificationError($error) === OperationRetryClassifier::NON_RETRYABLE) {
            $attemptChanges['status'] = OperationAttempt::STATUS_FAILED;
            $attemptChanges['finished_at'] = now('UTC');
        }
        $attempt->update($attemptChanges);
        $this->audit($attempt, 'verification_failed', $connectionId, [
            'verification_status' => OperationResult::VERIFICATION_FAILED,
            'verification_error' => $error,
        ]);
    }

    private function audit(OperationAttempt $attempt, string $action, ?string $connectionId, array $metadata): void
    {
        $operation = $attempt->operation;
        AuditLog::create([
            'organization_id' => $operation->site->organization_id,
            'user_id' => null,
            'site_id' => $operation->site_id,
            'action' => $action,
            'target_type' => 'operation',
            'target_id' => $operation->id,
            'correlation_id' => $operation->id,
            'policy_result' => $operation->policy_result,
            'metadata_json' => array_merge($metadata, [
                'attempt_id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'connector_job_id' => $attempt->connector_job_id,
                'connector_connection_id' => $connectionId,
            ]),
        ]);
    }
}
