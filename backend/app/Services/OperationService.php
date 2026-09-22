<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OperationService
{
    public function __construct(
        private PolicyEngine $policyEngine,
        private MaintenanceLock $maintenanceLock,
    ) {}

    public function createOperation(
        User $user,
        Site $site,
        string $operationType,
        array $targetJson,
        string $idempotencyKey,
    ): Operation {
        return DB::transaction(function () use ($user, $site, $operationType, $targetJson, $idempotencyKey) {
            $existing = Operation::where('site_id', $site->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ($existing->target_json !== $targetJson) {
                    throw new RuntimeException('Idempotency conflict: same key with different payload');
                }
                return $existing;
            }

            $policyEvaluation = $this->policyEngine->evaluateOperationRequest(
                $user, $site, $operationType, $targetJson
            );

            if (! $policyEvaluation['allowed']) {
                throw new \App\Exceptions\OperationPolicyDeniedException(
                    'Operation denied by policy',
                    $policyEvaluation['checks'],
                    $policyEvaluation['policy_result']
                );
            }

            $initialStatus = $policyEvaluation['approval_required'] ? Operation::STATUS_PENDING_APPROVAL : Operation::STATUS_QUEUED;

            $operation = Operation::create([
                'site_id' => $site->id,
                'operation_type' => $operationType,
                'target_json' => $targetJson,
                'status' => $initialStatus,
                'policy_result' => $policyEvaluation['policy_result'],
                'approval_required' => $policyEvaluation['approval_required'],
                'idempotency_key' => $idempotencyKey,
                'requested_by' => $user->id,
            ]);

            $this->auditLog(
                $operation,
                'operation_requested',
                $user,
                null,
                [
                    'operation_type' => $operationType,
                    'target_json' => $targetJson,
                    'idempotency_key' => $idempotencyKey,
                    'policy_result' => $policyEvaluation['policy_result'],
                    'approval_required' => $policyEvaluation['approval_required'],
                ],
                $policyEvaluation['correlation_id']
            );

            $this->auditLog(
                $operation,
                'policy_evaluated',
                $user,
                null,
                [
                    'checks' => $policyEvaluation['checks'],
                    'allowed' => $policyEvaluation['allowed'],
                ],
                $policyEvaluation['correlation_id']
            );

            if ($policyEvaluation['approval_required'] && $policyEvaluation['allowed']) {
                $this->createApprovalRequest($operation, $user);
            }

            return $operation;
        });
    }

    private function createApprovalRequest(Operation $operation, User $user): void
    {
        $expiresAt = now()->addHours(24);

        ApprovalRequest::create([
            'organization_id' => $operation->site->organization_id,
            'site_id' => $operation->site_id,
            'operation_id' => $operation->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_by' => $user->id,
            'reason' => "Operation {$operation->operation_type} requires approval",
            'expires_at' => $expiresAt,
        ]);

        $operation->update(['status' => Operation::STATUS_PENDING_APPROVAL]);
    }

    public function approveOperation(ApprovalRequest $approvalRequest, User $reviewer): Operation
    {
        if (! $approvalRequest->isPending()) {
            throw new RuntimeException('Approval request is not pending');
        }

        if ($approvalRequest->requested_by === $reviewer->id) {
            throw new RuntimeException('Self-approval is not allowed');
        }

        if ($approvalRequest->isExpired()) {
            $approvalRequest->update(['status' => ApprovalRequest::STATUS_EXPIRED]);
            $approvalRequest->operation->update(['status' => Operation::STATUS_CANCELLED]);
            throw new RuntimeException('Approval request has expired');
        }

        return DB::transaction(function () use ($approvalRequest, $reviewer) {
            $approvalRequest->update([
                'status' => ApprovalRequest::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $operation = $approvalRequest->operation;
            $operation->update([
                'status' => Operation::STATUS_QUEUED,
                'policy_result' => 'approved',
            ]);

            $this->auditLog(
                $operation,
                'approval_granted',
                $reviewer,
                ['status' => 'pending'],
                ['status' => 'approved'],
                Str::uuid()->toString()
            );

            return $operation->fresh();
        });
    }

    public function rejectOperation(ApprovalRequest $approvalRequest, User $reviewer, string $reason): Operation
    {
        if (! $approvalRequest->isPending()) {
            throw new RuntimeException('Approval request is not pending');
        }

        if ($approvalRequest->requested_by === $reviewer->id) {
            throw new RuntimeException('Self-rejection is not allowed');
        }

        return DB::transaction(function () use ($approvalRequest, $reviewer, $reason) {
            $approvalRequest->update([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reason' => $reason,
            ]);

            $operation = $approvalRequest->operation;
            $operation->update([
                'status' => Operation::STATUS_CANCELLED,
                'policy_result' => 'rejected',
            ]);

            $this->auditLog(
                $operation,
                'approval_rejected',
                $reviewer,
                ['status' => 'pending'],
                ['status' => 'rejected', 'reason' => $reason],
                Str::uuid()->toString()
            );

            return $operation->fresh();
        });
    }

    public function getOperationForSite(User $user, Site $site, string $operationId): ?Operation
    {
        if (! $user->organizations()->whereHas('sites', fn ($q) => $q->whereKey($site->id))->exists()) {
            return null;
        }

        return $site->operations()->where('id', $operationId)->first();
    }

    public function getOperationsForSite(User $user, Site $site): \Illuminate\Database\Eloquent\Collection
    {
        if (! $user->organizations()->whereHas('sites', fn ($q) => $q->whereKey($site->id))->exists()) {
            return collect();
        }

        return $site->operations()->orderBy('created_at', 'desc')->get();
    }

    private function auditLog(
        Operation $operation,
        string $action,
        User $user,
        ?array $before,
        array $after,
        string $correlationId
    ): void {
        AuditLog::create([
            'organization_id' => $operation->site->organization_id,
            'user_id' => $user->id,
            'site_id' => $operation->site_id,
            'action' => $action,
            'target_type' => 'operation',
            'target_id' => $operation->id,
            'correlation_id' => $correlationId,
            'policy_result' => $operation->policy_result,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => ['operation_type' => $operation->operation_type],
        ]);
    }
}