<?php

namespace App\Services;

use App\Exceptions\AutomationOperationLinkException;
use App\Exceptions\OperationPolicyDeniedException;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AutomationRunEvaluationService
{
    private const IDEMPOTENCY_KEY_FORMAT = 'automation:run:%s:operation:v1';

    public function __construct(
        private AutomationRequesterAuthorization $requesterAuthorization,
        private OperationService $operationService,
    ) {}

    public function idempotencyKeyFor(AutomationRun|string $run): string
    {
        $runId = $run instanceof AutomationRun ? (string) $run->getKey() : $run;

        return sprintf(self::IDEMPOTENCY_KEY_FORMAT, $runId);
    }

    /** @return array<string, mixed> */
    public function evaluate(AutomationRun|string $run): array
    {
        $runId = $run instanceof AutomationRun ? (string) $run->getKey() : $run;

        try {
            return DB::transaction(function () use ($runId): array {
                $lockedRun = AutomationRun::query()
                    ->whereKey($runId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->operation_id !== null) {
                    return $this->handleAlreadyLinked($lockedRun);
                }

                if ($lockedRun->status !== AutomationRun::STATUS_EVALUATING) {
                    return $this->result($lockedRun, 'not_evaluatable');
                }

                $rule = AutomationRule::query()->find($lockedRun->automation_rule_id);
                $failure = $this->ruleFailure($lockedRun, $rule);
                if ($failure !== null) {
                    return $this->fail($lockedRun, $failure, null, $this->requesterId($rule), null);
                }

                $requester = User::query()->find($rule->created_by);

                if (! $this->requesterAuthorization->canRequest($rule)) {
                    return $this->fail(
                        $lockedRun,
                        'requester_unauthorized',
                        'The rule creator is no longer authorized to request this operation.',
                        $requester?->id,
                        null,
                    );
                }

                if ($requester === null) {
                    return $this->fail(
                        $lockedRun,
                        'requester_unauthorized',
                        'The rule creator no longer exists.',
                        null,
                        null,
                    );
                }
                $idempotencyKey = $this->idempotencyKeyFor($lockedRun);
                $operation = null;

                try {
                    $operation = $this->operationService->createOperation(
                        $requester,
                        $lockedRun->site()->firstOrFail(),
                        AutomationRule::ACTION_CACHE_CLEAR,
                        $rule->target_json ?? [],
                        $idempotencyKey,
                        $lockedRun,
                    );
                } catch (OperationPolicyDeniedException $exception) {
                    return $this->fail(
                        $lockedRun,
                        'policy_denied',
                        'Operation policy denied this automation request.',
                        $requester->id,
                        null,
                        ['policy_checks' => $exception->checks, 'policy_result' => $exception->policyResult],
                    );
                } catch (RuntimeException $exception) {
                    if (! str_contains($exception->getMessage(), 'Idempotency conflict')
                        || str_contains($exception->getMessage(), 'provenance mismatch')) {
                        if (str_contains($exception->getMessage(), 'provenance mismatch')) {
                            throw new AutomationOperationLinkException('operation_origin_mismatch');
                        }

                        throw $exception;
                    }

                    $operation = Operation::query()
                        ->where('site_id', $lockedRun->site_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($operation === null) {
                        return $this->fail(
                            $lockedRun,
                            'idempotency_conflict',
                            'The automation operation key conflicts with another request.',
                            $requester->id,
                            null,
                        );
                    }
                }

                $mismatch = $this->operationMismatch($lockedRun, $rule, $requester, $operation, $idempotencyKey);
                if ($mismatch !== null) {
                    throw new AutomationOperationLinkException($mismatch, $operation?->id);
                }

                $targetStatus = $this->runStatusForOperation($operation->status);
                if ($targetStatus === null) {
                    throw new AutomationOperationLinkException('unsupported_operation_status', $operation->id);
                }

                $lockedRun->operation_id = $operation->id;
                $lockedRun->evaluation_metadata_json = array_merge(
                    $lockedRun->evaluation_metadata_json ?? [],
                    [
                        'operation_status' => $operation->status,
                        'operation_idempotency_key' => $idempotencyKey,
                        'outcome' => 'linked',
                    ],
                );
                $lockedRun->failure_code = null;
                $lockedRun->failure_message = null;
                $lockedRun->transitionTo($targetStatus);
                $lockedRun->save();

                $this->auditEvaluation($lockedRun, $requester->id, $operation, $targetStatus, null, $idempotencyKey);

                return $this->result($lockedRun, 'evaluated', $operation);
            });
        } catch (AutomationOperationLinkException $exception) {
            return DB::transaction(function () use ($runId, $exception): array {
                $lockedRun = AutomationRun::query()
                    ->whereKey($runId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRun->status === AutomationRun::STATUS_EVALUATING
                    && $lockedRun->operation_id === null) {
                    return $this->fail(
                        $lockedRun,
                        'operation_link_invalid',
                        'Operation ownership could not be validated; no operation was linked.',
                        $this->requesterId($lockedRun->automationRule()->first()),
                        null,
                        [
                            'validation_reason' => $exception->reason,
                            'returned_operation_id' => $exception->operationId,
                        ],
                    );
                }

                return $this->result($lockedRun, 'not_evaluatable');
            });
        }
    }

    private function handleAlreadyLinked(AutomationRun $run): array
    {
        $operation = Operation::query()->find($run->operation_id);
        if ($operation === null) {
            return $this->result($run, 'link_invalid', null, 'linked_operation_missing');
        }

        $rule = AutomationRule::query()->find($run->automation_rule_id);
        $requester = $rule ? User::query()->find($rule->created_by) : null;
        $expectedKey = $this->idempotencyKeyFor($run);
        $mismatch = $this->operationMismatch($run, $rule, $requester, $operation, $expectedKey);
        if ($mismatch !== null) {
            return $this->result($run, 'link_invalid', $operation, 'operation_link_invalid');
        }

        return $this->result($run, 'already_linked', $operation);
    }

    private function ruleFailure(AutomationRun $run, ?AutomationRule $rule): ?array
    {
        if ($rule === null) {
            return ['code' => 'rule_missing', 'message' => 'The automation rule no longer exists.'];
        }

        if ((string) $rule->organization_id !== (string) $run->organization_id
            || (string) $rule->site_id !== (string) $run->site_id) {
            return ['code' => 'rule_scope_mismatch', 'message' => 'The automation rule scope does not match this run.'];
        }

        $siteOrganizationId = $rule->site()->value('organization_id');
        if ($siteOrganizationId === null || (string) $siteOrganizationId !== (string) $run->organization_id) {
            return ['code' => 'rule_scope_mismatch', 'message' => 'The automation rule site does not belong to its organization.'];
        }

        if (! $rule->enabled) {
            return ['code' => 'rule_disabled', 'message' => 'The automation rule is disabled.'];
        }

        if ($rule->trigger_type !== AutomationRule::TRIGGER_SCHEDULE) {
            return ['code' => 'unsupported_trigger', 'message' => 'The automation trigger is unsupported.'];
        }

        if ($rule->action_type !== AutomationRule::ACTION_CACHE_CLEAR) {
            return ['code' => 'unsupported_action', 'message' => 'The automation action is unsupported.'];
        }

        if ($rule->conditions_json !== null) {
            return ['code' => 'conditions_unsupported', 'message' => 'Automation conditions are not supported.'];
        }

        if (! is_array($rule->target_json)) {
            return ['code' => 'invalid_target', 'message' => 'The automation target is invalid.'];
        }

        return null;
    }

    private function operationMismatch(
        AutomationRun $run,
        ?AutomationRule $rule,
        ?User $requester,
        ?Operation $operation,
        string $expectedIdempotencyKey,
    ): ?string {
        if ($rule === null || $requester === null || $operation === null
            || ! $operation->exists || $operation->getKey() === null
            || ! Operation::query()->whereKey($operation->getKey())->exists()) {
            return 'operation_not_persisted';
        }

        $operation->loadMissing('site.organization');
        $origin = $operation->automationOperationOrigin()->first();
        if ($operation->site === null || $operation->site->organization === null) {
            return 'operation_scope_missing';
        }

        if ($origin === null
            || (string) $origin->automation_run_id !== (string) $run->id
            || (string) $origin->operation_id !== (string) $operation->id
            || (string) $origin->site_id !== (string) $run->site_id
            || (string) $origin->organization_id !== (string) $run->organization_id
            || ($run->operation_id !== null && (string) $run->operation_id !== (string) $origin->operation_id)) {
            return 'operation_origin_mismatch';
        }

        if ((string) $rule->organization_id !== (string) $run->organization_id
            || (string) $rule->site_id !== (string) $run->site_id
            || (string) $operation->site->organization_id !== (string) $run->organization_id
            || (string) $operation->site_id !== (string) $run->site_id) {
            return 'operation_scope_mismatch';
        }

        if ($operation->operation_type !== AutomationRule::ACTION_CACHE_CLEAR) {
            return 'operation_type_mismatch';
        }

        if ($operation->target_json !== $rule->target_json) {
            return 'operation_target_mismatch';
        }

        if ((string) $operation->requested_by !== (string) $requester->id) {
            return 'operation_requester_mismatch';
        }

        if ($operation->idempotency_key !== $expectedIdempotencyKey) {
            return 'operation_idempotency_key_mismatch';
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

    private function requesterId(?AutomationRule $rule): ?int
    {
        if ($rule === null) {
            return null;
        }

        return User::query()->whereKey($rule->created_by)->value('id');
    }

    private function fail(
        AutomationRun $run,
        string|array $failure,
        ?string $message,
        ?int $requesterId,
        ?Operation $operation,
        array $metadata = [],
    ): array {
        if (is_array($failure)) {
            $message = $failure['message'];
            $failure = $failure['code'];
        }

        $run->failure_code = $failure;
        $run->failure_message = $message;
        $run->evaluation_metadata_json = array_merge(
            $run->evaluation_metadata_json ?? [],
            ['outcome' => 'failed'],
        );
        $run->transitionTo(AutomationRun::STATUS_FAILED);
        $run->save();

        $this->auditEvaluation($run, $requesterId, $operation, AutomationRun::STATUS_FAILED, $failure, null, $metadata);

        return $this->result($run, 'failed', $operation, $failure);
    }

    private function auditEvaluation(
        AutomationRun $run,
        ?int $requesterId,
        ?Operation $operation,
        string $outcome,
        ?string $failureCode,
        ?string $idempotencyKey,
        array $metadata = [],
    ): void {
        AuditLog::query()->create([
            'organization_id' => $run->organization_id,
            'user_id' => $requesterId,
            'site_id' => $run->site_id,
            'action' => 'automation_run_evaluated',
            'target_type' => 'automation_run',
            'target_id' => $run->id,
            'correlation_id' => $run->id,
            'policy_result' => $failureCode === null ? 'allowed' : 'denied',
            'after_json' => [
                'status' => $run->status,
                'operation_id' => $operation?->id,
                'failure_code' => $failureCode,
            ],
            'metadata_json' => array_merge([
                'automation_rule_id' => $run->automation_rule_id,
                'automation_run_id' => $run->id,
                'operation_id' => $operation?->id,
                'requester_id' => $requesterId,
                'outcome' => $outcome,
                'operation_idempotency_key' => $idempotencyKey,
            ], $metadata),
        ]);
    }

    private function result(
        AutomationRun $run,
        string $outcome,
        ?Operation $operation = null,
        ?string $failureCode = null,
    ): array {
        return [
            'run_id' => $run->id,
            'outcome' => $outcome,
            'run_status' => $run->status,
            'operation_id' => $operation?->id ?? $run->operation_id,
            'operation_status' => $operation?->status,
            'failure_code' => $failureCode ?? $run->failure_code,
        ];
    }
}
