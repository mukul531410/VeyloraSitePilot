<?php

namespace App\Services;

use App\Exceptions\AutomationRunRecoveryException;
use App\Models\AuditLog;
use App\Models\AutomationOperationOrigin;
use App\Models\AutomationRun;
use App\Models\AutomationRunIntent;
use App\Models\AutomationRunRecovery;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint K recovery for a STRANDED automation run: `evaluating` with
 * operation_id NULL.
 *
 * Lock order is always AutomationRun -> automation_run_recoveries ->
 * automation_operation_origins/operations. Nothing here dispatches connector
 * work, retries an Operation, resolves an unknown Operation, or waits on a
 * connector while holding a database lock.
 */
class AutomationRunRecoveryService
{
    /**
     * Rejections that never became a recovery attempt. They are refused before or
     * at the claim boundary, so no recovery row is recorded for them: a conflict
     * belongs to the winning request, and an authorization or validation failure
     * must not disclose run state.
     */
    private const NON_ATTEMPT_REASONS = [
        AutomationRunRecoveryException::RECOVERY_CONFLICT,
        AutomationRunRecoveryException::RUN_MISSING,
        AutomationRunRecoveryException::WRONG_TENANT,
        AutomationRunRecoveryException::UNSUPPORTED_ACTION,
        AutomationRunRecoveryException::MISSING_IDEMPOTENCY_KEY,
        AutomationRunRecoveryException::MISSING_REASON,
        AutomationRunRecoveryException::UNAUTHORIZED_REASONS,
    ];

    public function __construct(
        private AutomationRunRecoveryAuthorization $authorization,
        private AutomationRunRecoveryPreconditions $preconditions,
        private OperationService $operationService,
        private AutomationRunReconciliationService $reconciliation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspect(AutomationRun $run): array
    {
        return $this->preconditions->inspect($run);
    }

    /**
     * @return array<string, mixed>
     */
    public function recover(
        User $actor,
        AutomationRun $run,
        string $action,
        ?string $reason,
        string $requestIdempotencyKey,
        ?string $correlationId = null,
        ?Site $expectedSite = null,
    ): array {
        if (! in_array($action, AutomationRunRecovery::ACTIONS, true)) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::UNSUPPORTED_ACTION);
        }

        $requestIdempotencyKey = trim($requestIdempotencyKey);
        if ($requestIdempotencyKey === '') {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::MISSING_IDEMPOTENCY_KEY);
        }

        $reason = $reason === null ? null : trim($reason);
        if ($action === AutomationRunRecovery::ACTION_ABANDON && ($reason === null || $reason === '')) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::MISSING_REASON);
        }

        if (! $run->exists || $run->getKey() === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::RUN_MISSING);
        }

        $runId = (string) $run->getKey();

        try {
            $result = DB::transaction(fn (): array => $this->recoverLocked(
                $actor,
                $runId,
                $action,
                $reason,
                $requestIdempotencyKey,
                $correlationId,
                $expectedSite,
            ));
        } catch (AutomationRunRecoveryException $exception) {
            // The attempt is rolled back, so both the conflict audit and a refused
            // precondition are recorded in their own transaction. A blocked recovery
            // must never vanish, and it releases the run so a later legitimate
            // attempt stays possible.
            if ($exception->reason === AutomationRunRecoveryException::RECOVERY_CONFLICT) {
                $this->auditConflict($runId, $actor, $action, $requestIdempotencyKey, $correlationId);
            } elseif (! in_array($exception->reason, self::NON_ATTEMPT_REASONS, true)) {
                $this->persistBlockedRecovery(
                    $runId,
                    $actor,
                    $action,
                    $reason,
                    $requestIdempotencyKey,
                    $exception->reason,
                    $correlationId,
                );
            }

            throw $exception;
        }

        // Reconciliation mirrors authoritative Operation state after the link has
        // committed. It never dispatches and never re-submits.
        if ($result['recovery']->state === AutomationRunRecovery::STATE_LINKED) {
            $result['reconciliation'] = $this->reconciliation->reconcile($runId);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function recoverLocked(
        User $actor,
        string $runId,
        string $action,
        ?string $reason,
        string $requestIdempotencyKey,
        ?string $correlationId,
        ?Site $expectedSite,
    ): array {
        // Lock 1: the run. Every concurrent recovery for this run serialises here.
        $run = AutomationRun::query()
            ->with('site.organization')
            ->whereKey($runId)
            ->lockForUpdate()
            ->first();

        if ($run === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::RUN_MISSING);
        }

        $this->authorization->authorize($actor, $run, $expectedSite);

        // A duplicate request replays its stored outcome before any precondition is
        // re-evaluated, so a retry after the run already left `evaluating` is still
        // answered from the durable recovery record.
        $replay = AutomationRunRecovery::query()
            ->where('automation_run_id', $run->id)
            ->where('request_idempotency_key', $requestIdempotencyKey)
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if ($replay !== null) {
            if ($replay->action !== $action) {
                throw new AutomationRunRecoveryException(AutomationRunRecoveryException::RECOVERY_CONFLICT);
            }

            return $this->result($replay, true);
        }

        // At most one active recovery per run: a different concurrent request for a
        // run that is already claimed or already recovered conflicts.
        $occupying = AutomationRunRecovery::query()
            ->where('active_automation_run_id', $run->id)
            ->lockForUpdate()
            ->first();

        if ($occupying !== null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::RECOVERY_CONFLICT);
        }

        $this->preconditions->assertStranded($run);

        $recovery = $this->openRecovery($run, $actor, $action, $reason, $requestIdempotencyKey, $correlationId);

        return match ($action) {
            AutomationRunRecovery::ACTION_LINK => $this->performLink($run, $recovery, $correlationId),
            AutomationRunRecovery::ACTION_RE_EVALUATE => $this->performReevaluation($run, $recovery, $correlationId),
            AutomationRunRecovery::ACTION_ABANDON => $this->performAbandon($run, $recovery, $reason, $correlationId),
            default => throw new AutomationRunRecoveryException(AutomationRunRecoveryException::UNSUPPORTED_ACTION),
        };
    }

    /**
     * Records a refused attempt durably, outside the rolled-back attempt. A
     * blocked recovery never claims the run, so it is persisted directly in the
     * blocked state rather than passing through an occupying state. The
     * requested -> authorized -> blocked sequence is still written to the audit
     * trail so it matches the recovery state machine.
     */
    private function persistBlockedRecovery(
        string $runId,
        User $actor,
        string $action,
        ?string $reason,
        string $requestIdempotencyKey,
        string $blockedReason,
        ?string $correlationId,
    ): void {
        DB::transaction(function () use ($runId, $actor, $action, $reason, $requestIdempotencyKey, $blockedReason, $correlationId): void {
            $run = AutomationRun::query()->whereKey($runId)->first();
            if ($run === null) {
                return;
            }

            $alreadyRecorded = AutomationRunRecovery::query()
                ->where('automation_run_id', $run->id)
                ->where('request_idempotency_key', $requestIdempotencyKey)
                ->exists();
            if ($alreadyRecorded) {
                return;
            }

            $recovery = AutomationRunRecovery::query()->create([
                'automation_run_id' => $run->id,
                'organization_id' => $run->organization_id,
                'site_id' => $run->site_id,
                'actor_id' => $actor->id,
                'action' => $action,
                'request_idempotency_key' => $requestIdempotencyKey,
                'reason' => $reason,
                'state' => AutomationRunRecovery::STATE_BLOCKED,
                'active_automation_run_id' => null,
                'failure_reason' => $blockedReason,
                'requested_at' => now('UTC'),
                'authorized_at' => now('UTC'),
                'started_at' => now('UTC'),
                'completed_at' => now('UTC'),
            ]);

            $this->audit($run, $actor, 'automation_run_recovery_requested', $recovery, $correlationId);
            $this->audit($run, $actor, 'automation_run_recovery_authorized', $recovery, $correlationId);
            $this->audit(
                $run,
                $actor,
                'automation_run_recovery_blocked',
                $recovery,
                $correlationId,
                ['blocked_reason' => $blockedReason],
            );
        });
    }

    private function openRecovery(
        AutomationRun $run,
        User $actor,
        string $action,
        ?string $reason,
        string $requestIdempotencyKey,
        ?string $correlationId,
    ): AutomationRunRecovery {
        $recovery = AutomationRunRecovery::query()->create([
            'automation_run_id' => $run->id,
            'organization_id' => $run->organization_id,
            'site_id' => $run->site_id,
            'actor_id' => $actor->id,
            'action' => $action,
            'request_idempotency_key' => $requestIdempotencyKey,
            'reason' => $reason,
            'state' => AutomationRunRecovery::STATE_REQUESTED,
            'requested_at' => now('UTC'),
        ]);

        $this->audit($run, $actor, 'automation_run_recovery_requested', $recovery, $correlationId);

        $recovery->state = AutomationRunRecovery::STATE_AUTHORIZED;
        $recovery->authorized_at = now('UTC');
        $recovery->save();

        $this->audit($run, $actor, 'automation_run_recovery_authorized', $recovery, $correlationId);

        $recovery->state = AutomationRunRecovery::STATE_IN_PROGRESS;
        $recovery->started_at = now('UTC');
        $recovery->save();

        return $recovery;
    }

    /**
     * Link an Operation that immutable provenance already proves belongs to this
     * run. Provenance is never created or repaired here, and the link never
     * dispatches, retries or approves anything.
     *
     * @return array<string, mixed>
     */
    private function performLink(
        AutomationRun $run,
        AutomationRunRecovery $recovery,
        ?string $correlationId,
    ): array {
        $intent = $this->preconditions->assertIntent($run);
        $provenance = $this->preconditions->assertProvenanceProvesOwnership($run, $intent);

        // Lock 3: provenance and Operation.
        AutomationOperationOrigin::query()
            ->whereKey($provenance['origin']->getKey())
            ->lockForUpdate()
            ->first();
        $operation = Operation::query()
            ->whereKey($provenance['operation']->getKey())
            ->lockForUpdate()
            ->first();

        if ($operation === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        // Reread under the run lock before mutating.
        $run->refresh();
        $this->preconditions->assertStranded($run);

        $run->operation_id = $operation->id;
        $run->evaluation_metadata_json = array_merge($run->evaluation_metadata_json ?? [], [
            'outcome' => 'recovered_linked',
            'recovery_action' => AutomationRunRecovery::ACTION_LINK,
            'operation_idempotency_key' => $operation->idempotency_key,
        ]);
        $run->save();

        $recovery->state = AutomationRunRecovery::STATE_LINKED;
        $recovery->completed_at = now('UTC');
        $recovery->result_metadata_json = [
            'operation_id' => $operation->id,
            'operation_status' => $operation->status,
            'origin_id' => $provenance['origin']->id,
        ];
        $recovery->save();

        $this->audit(
            $run,
            null,
            'automation_run_recovery_operation_linked',
            $recovery,
            $correlationId,
            ['operation' => $operation],
        );

        return $this->result($recovery, false, $operation);
    }

    /**
     * Re-submit the original intent from its immutable snapshot. The original
     * requester, target and idempotency key are preserved; no successor run and
     * no new idempotency key are created.
     *
     * @return array<string, mixed>
     */
    private function performReevaluation(
        AutomationRun $run,
        AutomationRunRecovery $recovery,
        ?string $correlationId,
    ): array {
        $intent = $this->preconditions->assertIntent($run);
        $this->preconditions->assertNoExecutionEvidence($run, $intent);

        $this->audit(
            $run,
            null,
            'automation_run_recovery_reevaluation_started',
            $recovery,
            $correlationId,
            [],
            $intent,
        );

        $live = $this->preconditions->assertReevaluationAllowed($run, $intent);

        // The recovery operator is never the requester. The original requester
        // from the immutable intent snapshot submits the recovered request.
        $operation = $this->operationService->createOperation(
            $live['requester'],
            $live['site'],
            $intent->original_operation_type,
            $intent->original_target_json,
            $intent->original_idempotency_key,
            $run,
        );

        $targetStatus = $this->runStatusForOperation($operation->status);
        if ($targetStatus === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::OPERATION_NOT_SUBMITTED);
        }

        $run->refresh();
        $run->operation_id = $operation->id;
        $run->failure_code = null;
        $run->failure_message = null;
        $run->evaluation_metadata_json = array_merge($run->evaluation_metadata_json ?? [], [
            'operation_status' => $operation->status,
            'operation_idempotency_key' => $intent->original_idempotency_key,
            'outcome' => 'recovered_re_evaluated',
            'recovery_action' => AutomationRunRecovery::ACTION_RE_EVALUATE,
        ]);
        $run->transitionTo($targetStatus);
        $run->save();

        $recovery->state = $operation->status === Operation::STATUS_PENDING_APPROVAL
            ? AutomationRunRecovery::STATE_AWAITING_APPROVAL
            : AutomationRunRecovery::STATE_SUBMITTED;
        $recovery->completed_at = now('UTC');
        $recovery->result_metadata_json = [
            'operation_id' => $operation->id,
            'operation_status' => $operation->status,
            'requester_id' => $intent->original_requester_id,
            'idempotency_key' => $intent->original_idempotency_key,
            'approval_required' => $live['approval_required'],
        ];
        $recovery->save();

        $this->audit(
            $run,
            null,
            'automation_run_recovery_submitted',
            $recovery,
            $correlationId,
            ['operation' => $operation],
            $intent,
        );

        return $this->result($recovery, false, $operation);
    }

    /**
     * Abandon a stranded run whose intent was never submitted. No Operation is
     * created, modified or claimed, and the audit never asserts that a remote
     * WordPress operation failed.
     *
     * @return array<string, mixed>
     */
    private function performAbandon(
        AutomationRun $run,
        AutomationRunRecovery $recovery,
        ?string $reason,
        ?string $correlationId,
    ): array {
        $intent = $this->preconditions->assertIntent($run);
        $this->preconditions->assertAbandonable($run, $intent);

        $run->refresh();
        $this->preconditions->assertStranded($run);

        $run->evaluation_metadata_json = array_merge($run->evaluation_metadata_json ?? [], [
            'outcome' => 'recovered_abandoned',
            'recovery_action' => AutomationRunRecovery::ACTION_ABANDON,
            'abandoned_reason' => $reason,
            'remote_execution_claimed' => false,
        ]);
        $run->transitionTo(AutomationRun::STATUS_ABANDONED);
        $run->save();

        $recovery->state = AutomationRunRecovery::STATE_ABANDONED;
        $recovery->completed_at = now('UTC');
        $recovery->result_metadata_json = [
            'run_status' => AutomationRun::STATUS_ABANDONED,
            'intent_submitted' => false,
        ];
        $recovery->save();

        $this->audit(
            $run,
            null,
            'automation_run_recovery_abandoned',
            $recovery,
            $correlationId,
            [
                'remote_execution_claimed' => false,
                'abandoned_reason' => $reason,
            ],
            $intent,
        );

        return $this->result($recovery, false);
    }

    /**
     * Audits a rejected concurrent recovery. The losing request never becomes a
     * recovery attempt, so this runs outside the rolled-back attempt and reports
     * the winning recovery it collided with.
     */
    private function auditConflict(
        string $runId,
        User $actor,
        string $action,
        string $requestIdempotencyKey,
        ?string $correlationId,
    ): void {
        DB::transaction(function () use ($runId, $actor, $action, $requestIdempotencyKey, $correlationId): void {
            $run = AutomationRun::query()->whereKey($runId)->first();
            if ($run === null) {
                return;
            }

            $existing = AutomationRunRecovery::query()
                ->where('automation_run_id', $run->id)
                ->where(function ($query) use ($requestIdempotencyKey, $run): void {
                    $query->where('request_idempotency_key', $requestIdempotencyKey)
                        ->orWhere('active_automation_run_id', $run->id);
                })
                ->orderByDesc('created_at')
                ->first();

            $this->audit($run, $actor, 'automation_run_recovery_conflict', null, $correlationId, [
                'requested_action' => $action,
                'requested_idempotency_key' => $requestIdempotencyKey,
                'existing_recovery_id' => $existing?->id,
                'existing_action' => $existing?->action,
                'existing_state' => $existing?->state,
            ]);
        });
    }

    private function audit(
        AutomationRun $run,
        ?User $actor,
        string $action,
        ?AutomationRunRecovery $recovery,
        ?string $correlationId,
        array $metadata = [],
        ?AutomationRunIntent $intent = null,
        ?Operation $operation = null,
    ): void {
        AuditLog::query()->create([
            'organization_id' => $run->organization_id,
            'user_id' => $actor?->id ?? $recovery?->actor_id,
            'site_id' => $run->site_id,
            'action' => $action,
            'target_type' => 'automation_run',
            'target_id' => $run->id,
            'correlation_id' => $this->correlationIdFor($run, $correlationId),
            'metadata_json' => array_merge([
                'automation_run_id' => $run->id,
                'automation_rule_id' => $run->automation_rule_id,
                'recovery_id' => $recovery?->id,
                'recovery_action' => $recovery?->action,
                'recovery_state' => $recovery?->state,
                'recovery_actor_id' => $recovery?->actor_id ?? $actor?->id,
                'run_status' => $run->status,
                'request_idempotency_key' => $recovery?->request_idempotency_key,
                'original_requester_id' => $intent?->original_requester_id,
                'original_operation_type' => $intent?->original_operation_type,
                'original_idempotency_key' => $intent?->original_idempotency_key,
                'operation_id' => $operation?->id,
                'operation_status' => $operation?->status,
            ], $metadata),
        ]);
    }

    /**
     * `audit_logs.correlation_id` is a 26-character ULID. A caller-supplied
     * X-Request-ID is only used when it already fits; anything else falls back to
     * the run id so a long request header can never break an audit write.
     */
    private function correlationIdFor(AutomationRun $run, ?string $correlationId): string
    {
        $correlationId = $correlationId === null ? '' : trim($correlationId);
        if ($correlationId !== '' && strlen($correlationId) <= 26) {
            return $correlationId;
        }

        return (string) $run->id;
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

    /**
     * @return array<string, mixed>
     */
    private function result(
        AutomationRunRecovery $recovery,
        bool $replayed,
        ?Operation $operation = null,
    ): array {
        $run = AutomationRun::query()->find($recovery->automation_run_id);

        return [
            'run' => $run,
            'recovery' => $recovery->refresh(),
            'operation' => $operation,
            'replayed' => $replayed,
        ];
    }
}
