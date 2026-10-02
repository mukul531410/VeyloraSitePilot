<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AutomationRunRecoveryException;
use App\Http\Requests\RecoverAutomationRunRequest;
use App\Models\AutomationRun;
use App\Models\AutomationRunIntent;
use App\Models\AutomationRunRecovery;
use App\Models\Operation;
use App\Models\User;
use App\Services\AutomationRunRecoveryService;
use Illuminate\Http\Request;

class AutomationRunsController extends BaseController
{
    public function __construct(
        private AutomationRunRecoveryService $recoveryService,
    ) {}

    public function show(Request $request, string $run)
    {
        $actor = $request->user();
        $automationRun = $this->findVisibleRun($actor, $run);

        if ($automationRun === null) {
            return $this->errorResponse('Automation run not found', 'not_found', 404);
        }

        $inspection = $this->recoveryService->inspect($automationRun);
        $intent = $inspection['intent'];
        $evidence = $inspection['execution_evidence'];
        $run = $inspection['run'];
        $recovery = $run->latestRecovery;

        return $this->successResponse([
            'run' => [
                'id' => $run->id,
                'automation_rule_id' => $run->automation_rule_id,
                'organization_id' => $run->organization_id,
                'site_id' => $run->site_id,
                'occurrence_key' => $run->occurrence_key,
                'status' => $run->status,
                'operation_id' => $run->operation_id,
                'failure_code' => $run->failure_code,
                'failure_message' => $run->failure_message,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ],
            'stranded' => $run->isStranded(),
            'intent' => $intent === null ? null : $this->transformIntent($intent),
            'recovery' => $recovery === null ? null : $this->transformRecovery($recovery),
            'linked_operation' => $this->transformOperationSummary($inspection['linked_operation']),
            'candidate_operation' => $this->transformOperationSummary($inspection['candidate_operation']),
            'execution_evidence' => $evidence,
            'recoverable' => $run->isStranded(),
            'allowed_actions' => $run->isStranded() ? AutomationRunRecovery::ACTIONS : [],
        ]);
    }

    public function recover(RecoverAutomationRunRequest $request, string $run)
    {
        /** @var User $actor */
        $actor = $request->user();
        $automationRun = $this->findVisibleRun($actor, $run);

        if ($automationRun === null) {
            return $this->errorResponse('Automation run not found', 'not_found', 404);
        }

        try {
            $result = $this->recoveryService->recover(
                $actor,
                $automationRun,
                $request->validated('action'),
                $request->validated('reason'),
                (string) $request->header('Idempotency-Key'),
                $request->header('X-Request-ID'),
            );
        } catch (AutomationRunRecoveryException $exception) {
            return $this->recoveryExceptionResponse($exception);
        }

        /** @var AutomationRunRecovery $recovery */
        $recovery = $result['recovery'];
        /** @var AutomationRun $recoveredRun */
        $recoveredRun = $result['run']->refresh();
        $operation = $result['operation'];

        $status = $recovery->action === AutomationRunRecovery::ACTION_RE_EVALUATE
            && in_array($recovery->state, [
                AutomationRunRecovery::STATE_SUBMITTED,
                AutomationRunRecovery::STATE_AWAITING_APPROVAL,
            ], true)
            ? 202
            : 200;

        return $this->successResponse([
            'run_id' => $recoveredRun->id,
            'action' => $recovery->action,
            'recovery_state' => $recovery->state,
            'run_status' => $recoveredRun->status,
            'operation_id' => $operation?->id ?? $recoveredRun->operation_id,
            'operation_status' => $operation?->status,
            'replayed' => $result['replayed'],
            'recovery' => $this->transformRecovery($recovery),
            'reconciliation' => $result['reconciliation'] ?? null,
        ], [], $status);
    }

    private function findVisibleRun(User $actor, string $runId): ?AutomationRun
    {
        return AutomationRun::query()
            ->whereKey($runId)
            ->whereHas('site', function ($siteQuery) use ($actor): void {
                $siteQuery->where('status', 'active')
                    ->whereHas('organization', function ($organizationQuery) use ($actor): void {
                        $organizationQuery->where('status', 'active')
                            ->whereHas('members', function ($memberQuery) use ($actor): void {
                                $memberQuery->where('user_id', $actor->id)
                                    ->where('status', 'active');
                            });
                    });
            })
            ->first();
    }

    private function transformIntent(AutomationRunIntent $intent): array
    {
        return [
            'id' => $intent->id,
            'intent_version' => $intent->intent_version,
            'captured_at' => $intent->captured_at?->toIso8601String(),
            'automation_rule_id' => $intent->automation_rule_id,
            'occurrence_key' => $intent->occurrence_key,
            'organization_id' => $intent->organization_id,
            'site_id' => $intent->site_id,
            'original_operation_type' => $intent->original_operation_type,
            'original_target_json' => $intent->original_target_json,
            'original_requester_id' => $intent->original_requester_id,
            'original_idempotency_key' => $intent->original_idempotency_key,
        ];
    }

    private function transformRecovery(AutomationRunRecovery $recovery): array
    {
        return [
            'id' => $recovery->id,
            'automation_run_id' => $recovery->automation_run_id,
            'action' => $recovery->action,
            'state' => $recovery->state,
            'actor_id' => $recovery->actor_id,
            'reason' => $recovery->reason,
            'failure_reason' => $recovery->failure_reason,
            'result' => $recovery->result_metadata_json,
            'requested_at' => $recovery->requested_at?->toIso8601String(),
            'authorized_at' => $recovery->authorized_at?->toIso8601String(),
            'started_at' => $recovery->started_at?->toIso8601String(),
            'completed_at' => $recovery->completed_at?->toIso8601String(),
        ];
    }

    private function transformOperationSummary(?Operation $operation): ?array
    {
        if ($operation === null) {
            return null;
        }

        return [
            'id' => $operation->id,
            'site_id' => $operation->site_id,
            'organization_id' => $operation->site?->organization_id,
            'operation_type' => $operation->operation_type,
            'status' => $operation->status,
            'requested_by' => $operation->requested_by,
            'idempotency_key' => $operation->idempotency_key,
        ];
    }

    private function recoveryExceptionResponse(AutomationRunRecoveryException $exception)
    {
        if (in_array($exception->reason, [
            AutomationRunRecoveryException::WRONG_TENANT,
            AutomationRunRecoveryException::RUN_MISSING,
        ], true)) {
            return $this->errorResponse('Automation run not found', 'not_found', 404);
        }

        if (in_array($exception->reason, AutomationRunRecoveryException::UNAUTHORIZED_REASONS, true)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if (in_array($exception->reason, [
            AutomationRunRecoveryException::UNSUPPORTED_ACTION,
            AutomationRunRecoveryException::MISSING_IDEMPOTENCY_KEY,
            AutomationRunRecoveryException::MISSING_REASON,
        ], true)) {
            return $this->errorResponse('The given data was invalid.', 'validation_error', 422, [
                'reason' => $exception->reason,
            ]);
        }

        if ($exception->reason === AutomationRunRecoveryException::CURRENT_POLICY_DENIED) {
            return $this->errorResponse(
                'Recovery denied by current policy',
                'policy_denied',
                403,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === AutomationRunRecoveryException::LINK_REQUIRED) {
            return $this->errorResponse(
                'Immutable provenance proves an Operation already belongs to this run; use link',
                'link_required',
                409,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === AutomationRunRecoveryException::RECOVERY_CONFLICT) {
            return $this->errorResponse(
                'Another recovery is already active for this run',
                'recovery_conflict',
                409,
                ['reason' => $exception->reason],
            );
        }

        return $this->errorResponse(
            'Recovery preconditions are not satisfied',
            'recovery_precondition_failed',
            409,
            ['reason' => $exception->reason],
        );
    }
}
