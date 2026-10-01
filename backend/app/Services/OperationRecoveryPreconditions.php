<?php

namespace App\Services;

use App\Exceptions\OperationRecoveryChainException;
use App\Exceptions\OperationRecoveryException;
use App\Models\ApprovalRequest;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\SiteConnection;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class OperationRecoveryPreconditions
{
    public function __construct(
        private OperationRecoveryAuthorization $authorization,
        private OperationRecoveryChain $recoveryChain,
        private PolicyEngine $policyEngine,
    ) {}

    /** @return array{approval_required: bool} Current policy result for a future successor flow. */
    public function assess(?User $actor, Operation $operation, ?\App\Models\Site $expectedSite = null): array
    {
        $this->authorization->authorize($actor, $operation, $expectedSite);

        $source = Operation::query()->with([
            'site.organization',
            'approvalRequest',
        ])->find($operation->getKey());

        if ($source === null) {
            throw new OperationRecoveryException(OperationRecoveryException::SOURCE_MISSING);
        }
        if ($source->status !== Operation::STATUS_DEAD_LETTER) {
            throw new OperationRecoveryException(OperationRecoveryException::SOURCE_NOT_DEAD_LETTER);
        }

        $site = $source->site;
        $organization = $site?->organization;
        if ($site === null || $organization === null
            || (string) $site->organization_id !== (string) $organization->id) {
            throw new OperationRecoveryException(OperationRecoveryException::WRONG_TENANT);
        }

        $this->assertRecoveryChainAvailable($source);
        $this->assertSafeAttemptHistory($source);

        if (OperationResult::query()->where('operation_id', $source->id)->exists()) {
            throw new OperationRecoveryException(OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
        }

        if ($source->approval_required) {
            $approval = $source->approvalRequest;
            if ($approval === null
                || $approval->status !== ApprovalRequest::STATUS_APPROVED
                || (string) $approval->operation_id !== (string) $source->id
                || (string) $approval->site_id !== (string) $source->site_id
                || (string) $approval->organization_id !== (string) $organization->id) {
                throw new OperationRecoveryException(OperationRecoveryException::ORIGINAL_APPROVAL_UNAVAILABLE);
            }
        }

        $approvalPolicy = $organization->approval_policy;
        if (! is_array($approvalPolicy)
            || ! array_key_exists('require_approval', $approvalPolicy)
            || ! array_key_exists('high_criticality_requires_approval', $approvalPolicy)
            || ! is_bool($approvalPolicy['require_approval'])
            || ! is_bool($approvalPolicy['high_criticality_requires_approval'])) {
            throw new OperationRecoveryException(OperationRecoveryException::CURRENT_POLICY_UNAVAILABLE);
        }

        $connection = $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
        if (! $connection instanceof SiteConnection || ! $connection->isActive()) {
            throw new OperationRecoveryException(OperationRecoveryException::CONNECTION_UNAVAILABLE);
        }

        if ($source->operation_type !== 'action.cache_clear'
            || ! $connection->capabilities()
                ->where('capability_key', 'action.cache_clear')
                ->where('enabled', true)
                ->exists()) {
            throw new OperationRecoveryException(OperationRecoveryException::CAPABILITY_NOT_GRANTED);
        }

        try {
            $policy = $this->policyEngine->evaluateOperationRequest(
                User::query()->findOrFail($actor->getKey()),
                $site,
                $source->operation_type,
                $source->target_json ?? [],
            );
        } catch (\Throwable) {
            throw new OperationRecoveryException(OperationRecoveryException::CURRENT_POLICY_UNAVAILABLE);
        }

        if (! ($policy['allowed'] ?? false) || ! array_key_exists('approval_required', $policy)) {
            throw new OperationRecoveryException(OperationRecoveryException::CURRENT_POLICY_DENIED);
        }

        return ['approval_required' => (bool) $policy['approval_required']];
    }

    private function assertSafeAttemptHistory(Operation $operation): void
    {
        if (! Schema::hasColumn('operation_attempts', 'claimed_by_connection_id')) {
            throw new OperationRecoveryException(OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
        }

        $attempts = $operation->attempts()->orderBy('attempt_number')->get();
        $maximum = (int) $operation->max_attempts;
        if ($maximum < 1 || $attempts->count() !== $maximum) {
            throw new OperationRecoveryException(OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
        }

        foreach ($attempts as $index => $attempt) {
            if ((int) $attempt->attempt_number !== $index + 1
                || $attempt->status !== OperationAttempt::STATUS_TIMEOUT
                || $attempt->retryable !== true
                || $attempt->error_code !== OperationAttempt::ERROR_TIMEOUT
                || $attempt->connector_job_id === null
                || $attempt->timeout_at === null
                || $attempt->finished_at === null
                || $attempt->claimed_by_connection_id !== null) {
                throw new OperationRecoveryException(OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
            }
        }

        $finalAttempt = $attempts->last();
        if ($finalAttempt === null || (int) $finalAttempt->attempt_number !== $maximum) {
            throw new OperationRecoveryException(OperationRecoveryException::EXECUTION_EVIDENCE_UNCERTAIN);
        }
    }

    private function assertRecoveryChainAvailable(Operation $operation): void
    {
        if (! Schema::hasColumn('operations', 'recovery_of_operation_id')) {
            throw new OperationRecoveryException(OperationRecoveryException::RECOVERY_CHAIN_UNAVAILABLE);
        }

        try {
            $maximum = $this->recoveryChain->maximumLength();
            $length = $this->recoveryChain->length($operation);
        } catch (OperationRecoveryChainException $exception) {
            $reason = $exception->reason === OperationRecoveryChainException::LIMIT_EXCEEDED
                ? OperationRecoveryException::RECOVERY_CHAIN_LIMIT_REACHED
                : OperationRecoveryException::RECOVERY_CHAIN_UNAVAILABLE;
            throw new OperationRecoveryException($reason);
        }

        if ($length >= $maximum) {
            throw new OperationRecoveryException(OperationRecoveryException::RECOVERY_CHAIN_LIMIT_REACHED);
        }
    }
}
