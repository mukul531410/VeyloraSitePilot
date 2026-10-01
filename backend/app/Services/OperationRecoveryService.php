<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OperationRecoveryService
{
    public function __construct(
        private OperationRecoveryAuthorization $authorization,
        private OperationRecoveryPreconditions $preconditions,
        private OperationRecoveryChain $recoveryChain,
    ) {}

    public function createSuccessor(
        User $actor,
        Operation $source,
        string $idempotencyKey,
        ?string $correlationId = null,
        ?Site $expectedSite = null,
    ): Operation {
        try {
            return DB::transaction(function () use ($actor, $source, $idempotencyKey, $correlationId, $expectedSite): Operation {
                $this->authorization->authorize($actor, $source, $expectedSite);

                return $this->recoveryChain->withLockedSource(
                    $source,
                    function (Operation $lockedSource, int $chainLength) use ($actor, $idempotencyKey, $correlationId): Operation {
                        // This rechecks the source and its evidence after the source and
                        // lineage rows have been locked. These checks are reads only; do
                        // not lock attempts or approvals after locking the operation.
                        $policy = $this->preconditions->assess($actor, $lockedSource);

                        $existingByKey = Operation::query()
                            ->where('site_id', $lockedSource->site_id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();

                        if ($existingByKey !== null) {
                            return $this->resolveExistingByKey(
                                $existingByKey,
                                (string) $lockedSource->id,
                                $lockedSource->operation_type,
                                $lockedSource->target_json ?? [],
                                $idempotencyKey,
                            );
                        }

                        $existingSuccessor = Operation::query()
                            ->where('recovery_of_operation_id', $lockedSource->id)
                            ->first();
                        if ($existingSuccessor !== null) {
                            throw new RuntimeException('Recovery conflict: source already has a successor');
                        }

                        $approvalRequired = $policy['approval_required'];
                        $policyResult = $approvalRequired ? 'pending_approval' : 'allowed';
                        $successor = Operation::create([
                            'site_id' => $lockedSource->site_id,
                            'operation_type' => $lockedSource->operation_type,
                            'target_json' => $lockedSource->target_json,
                            'status' => $approvalRequired
                                ? Operation::STATUS_PENDING_APPROVAL
                                : Operation::STATUS_QUEUED,
                            'policy_result' => $policyResult,
                            'approval_required' => $approvalRequired,
                            'idempotency_key' => $idempotencyKey,
                            'recovery_of_operation_id' => $lockedSource->id,
                            'requested_by' => $actor->id,
                        ]);

                        if ($approvalRequired) {
                            $this->createApprovalRequest($successor, $lockedSource, $actor);
                        }

                        $correlationId ??= (string) Str::uuid();
                        AuditLog::create([
                            'organization_id' => $lockedSource->site->organization_id,
                            'user_id' => $actor->id,
                            'site_id' => $lockedSource->site_id,
                            'action' => 'operation_retry_requested',
                            'target_type' => 'operation',
                            'target_id' => $successor->id,
                            'correlation_id' => $correlationId,
                            'policy_result' => $policyResult,
                            'metadata_json' => [
                                'source_operation_id' => $lockedSource->id,
                                'successor_operation_id' => $successor->id,
                                'recovery_actor_id' => $actor->id,
                                'recovery_chain_position' => $chainLength + 1,
                                'idempotency_key' => $idempotencyKey,
                                'correlation_id' => $correlationId,
                            ],
                        ]);

                        return $successor->refresh();
                    },
                );
            });
        } catch (QueryException $exception) {
            return $this->resolveUniqueCollision(
                $exception,
                (string) $source->site_id,
                (string) $source->id,
                $source->operation_type,
                $source->target_json ?? [],
                $idempotencyKey,
            );
        }
    }

    protected function resolveUniqueCollision(
        QueryException $exception,
        string $siteId,
        string $sourceId,
        string $operationType,
        array $targetJson,
        string $idempotencyKey,
    ): Operation {
        $collision = $this->expectedUniqueCollision($exception);
        if ($collision === null) {
            throw $exception;
        }

        $existing = $collision === 'idempotency'
            ? Operation::query()->where('site_id', $siteId)->where('idempotency_key', $idempotencyKey)->first()
            : Operation::query()->where('recovery_of_operation_id', $sourceId)->first();

        if ($existing === null) {
            throw $exception;
        }

        if ($collision === 'idempotency') {
            return $this->resolveExistingByKey($existing, $sourceId, $operationType, $targetJson, $idempotencyKey);
        }

        if ($this->isExpectedSuccessor($existing, $sourceId, $operationType, $targetJson, $idempotencyKey)) {
            return $existing;
        }

        throw new RuntimeException('Recovery conflict: source already has a successor');
    }

    protected function expectedUniqueCollision(QueryException $exception): ?string
    {
        $driver = DB::connection($exception->getConnectionName())->getDriverName();
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());

        foreach ([
            'idempotency' => 'operations_site_id_idempotency_key_unique',
            'successor' => 'operations_recovery_of_operation_id_unique',
        ] as $kind => $index) {
            $matches = match ($driver) {
                'sqlite' => $sqlState === '23000'
                    && $driverCode === 19
                    && str_contains($message, $kind === 'idempotency'
                        ? 'UNIQUE constraint failed: operations.site_id, operations.idempotency_key'
                        : 'UNIQUE constraint failed: operations.recovery_of_operation_id'),
                'mysql' => $sqlState === '23000' && $driverCode === 1062 && str_contains($message, $index),
                'pgsql' => $sqlState === '23505' && str_contains($message, $index),
                'sqlsrv' => in_array($driverCode, [2601, 2627], true) && str_contains($message, $index),
                default => false,
            };

            if ($matches) {
                return $kind === 'successor' ? 'recovery_source' : $kind;
            }
        }

        return null;
    }

    private function resolveExistingByKey(
        Operation $existing,
        string $sourceId,
        string $operationType,
        array $targetJson,
        string $idempotencyKey,
    ): Operation {
        if (! $this->isExpectedSuccessor($existing, $sourceId, $operationType, $targetJson, $idempotencyKey)) {
            throw new RuntimeException('Idempotency conflict: same key with different recovery request');
        }

        return $existing;
    }

    private function isExpectedSuccessor(
        Operation $operation,
        string $sourceId,
        string $operationType,
        array $targetJson,
        string $idempotencyKey,
    ): bool {
        return (string) $operation->recovery_of_operation_id === $sourceId
            && $operation->operation_type === $operationType
            && $operation->target_json === $targetJson
            && $operation->idempotency_key === $idempotencyKey;
    }

    private function createApprovalRequest(Operation $successor, Operation $source, User $actor): void
    {
        ApprovalRequest::create([
            'organization_id' => $source->site->organization_id,
            'site_id' => $successor->site_id,
            'operation_id' => $successor->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_by' => $actor->id,
            'reason' => "Operation {$successor->operation_type} requires approval",
            'expires_at' => now()->addHours(24),
        ]);
    }
}
