<?php

namespace App\Services;

use App\Exceptions\OperationPolicyDeniedException;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\AutomationOperationOrigin;
use App\Models\AutomationRun;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        ?AutomationRun $automationRun = null,
    ): Operation {
        if ($automationRun === null && str_starts_with($idempotencyKey, 'automation:run:')) {
            throw new InvalidArgumentException('Automation idempotency keys are reserved for server-created automation operations.');
        }

        try {
            return DB::transaction(function () use ($user, $site, $operationType, $targetJson, $idempotencyKey, $automationRun) {
                $existingQuery = Operation::where('idempotency_key', $idempotencyKey);
                if ($automationRun === null) {
                    $existingQuery->where('site_id', $site->id);
                }
                $existing = $existingQuery->first();

                if ($existing) {
                    if ($automationRun !== null) {
                        $this->assertExistingAutomationOrigin($existing, $user, $site, $operationType, $targetJson, $idempotencyKey, $automationRun);
                    }

                    return $this->returnExistingOrRejectConflict($existing, $operationType, $targetJson);
                }

                $policyEvaluation = $this->policyEngine->evaluateOperationRequest(
                    $user, $site, $operationType, $targetJson
                );

                if (! $policyEvaluation['allowed']) {
                    throw new OperationPolicyDeniedException(
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

                if ($automationRun !== null) {
                    $this->createAutomationOrigin($automationRun, $operation);
                }

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

                return $operation->refresh();
            });
        } catch (QueryException $exception) {
            return $this->resolveIdempotencyCollision(
                $exception,
                (string) $site->id,
                $operationType,
                $targetJson,
                $idempotencyKey,
                $automationRun,
                $user,
                $site,
            );
        }
    }

    protected function resolveIdempotencyCollision(
        QueryException $exception,
        string $siteId,
        string $operationType,
        array $targetJson,
        string $idempotencyKey,
        ?AutomationRun $automationRun = null,
        ?User $user = null,
        ?Site $site = null,
    ): Operation {
        if (! $this->isOperationIdempotencyUniqueViolation($exception)) {
            throw $exception;
        }

        $existingQuery = Operation::query()->where('idempotency_key', $idempotencyKey);
        if ($automationRun === null) {
            $existingQuery->where('site_id', $siteId);
        }
        $existing = $existingQuery->first();

        if ($existing === null) {
            throw $exception;
        }

        if ($automationRun !== null && $user !== null && $site !== null) {
            $this->assertExistingAutomationOrigin($existing, $user, $site, $operationType, $targetJson, $idempotencyKey, $automationRun);
        }

        return $this->returnExistingOrRejectConflict($existing, $operationType, $targetJson);
    }

    private function createAutomationOrigin(AutomationRun $run, Operation $operation): void
    {
        $operation->loadMissing('site.organization');
        if ((string) $run->site_id !== (string) $operation->site_id
            || (string) $run->organization_id !== (string) $operation->site->organization_id) {
            throw new RuntimeException('Automation operation origin scope mismatch.');
        }

        AutomationOperationOrigin::query()->create([
            'automation_run_id' => $run->id,
            'operation_id' => $operation->id,
            'site_id' => $run->site_id,
            'organization_id' => $run->organization_id,
            'linked_at' => now('UTC'),
            'version' => 1,
        ]);
    }

    private function assertExistingAutomationOrigin(
        Operation $operation,
        User $user,
        Site $site,
        string $operationType,
        array $targetJson,
        string $idempotencyKey,
        AutomationRun $run,
    ): void {
        $origin = AutomationOperationOrigin::query()->where('operation_id', $operation->id)->first();
        $operation->loadMissing('site.organization');
        if ($origin === null
            || (string) $origin->automation_run_id !== (string) $run->id
            || (string) $origin->site_id !== (string) $site->id
            || (string) $origin->organization_id !== (string) $run->organization_id
            || (string) $run->site_id !== (string) $site->id
            || (string) $operation->site_id !== (string) $site->id
            || (string) $operation->site->organization_id !== (string) $run->organization_id
            || $operation->operation_type !== $operationType
            || $operation->target_json !== $targetJson
            || (string) $operation->requested_by !== (string) $user->id
            || $operation->idempotency_key !== $idempotencyKey) {
            throw new RuntimeException('Idempotency conflict: automation operation provenance mismatch');
        }
    }

    protected function isOperationIdempotencyUniqueViolation(QueryException $exception): bool
    {
        $driver = DB::connection($exception->getConnectionName())->getDriverName();
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());
        $index = 'operations_site_id_idempotency_key_unique';

        return match ($driver) {
            'sqlite' => $sqlState === '23000'
                && $driverCode === 19
                && str_contains($message, 'UNIQUE constraint failed: operations.site_id, operations.idempotency_key'),
            'mysql' => $sqlState === '23000'
                && $driverCode === 1062
                && str_contains($message, $index),
            'pgsql' => $sqlState === '23505'
                && str_contains($message, $index),
            'sqlsrv' => in_array($driverCode, [2601, 2627], true)
                && str_contains($message, $index),
            default => false,
        };
    }

    private function returnExistingOrRejectConflict(Operation $existing, string $operationType, array $targetJson): Operation
    {
        if ($existing->operation_type !== $operationType || $existing->target_json !== $targetJson) {
            throw new RuntimeException('Idempotency conflict: same key with different request');
        }

        return $existing;
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
            $this->expireApprovalRequest($approvalRequest);
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
                Str::ulid()->toString()
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

        if ($approvalRequest->isExpired()) {
            $this->expireApprovalRequest($approvalRequest);
            throw new RuntimeException('Approval request has expired');
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
                Str::ulid()->toString()
            );

            return $operation->fresh();
        });
    }

    private function expireApprovalRequest(ApprovalRequest $approvalRequest): void
    {
        DB::transaction(function () use ($approvalRequest): void {
            $lockedApproval = ApprovalRequest::query()
                ->whereKey($approvalRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedApproval->isPending()) {
                return;
            }

            $lockedApproval->update(['status' => ApprovalRequest::STATUS_EXPIRED]);
            $lockedApproval->operation()->update(['status' => Operation::STATUS_CANCELLED]);
        });
    }

    public function getOperationForSite(User $user, Site $site, string $operationId): ?Operation
    {
        if (! $user->organizations()->whereHas('sites', fn ($q) => $q->whereKey($site->id))->exists()) {
            return null;
        }

        return $site->operations()->where('id', $operationId)->first();
    }

    public function getOperationsForSite(User $user, Site $site): Collection
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
