<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OperationCancellationException;
use App\Exceptions\OperationPolicyDeniedException;
use App\Exceptions\OperationRecoveryChainException;
use App\Exceptions\OperationRecoveryException;
use App\Exceptions\OperationUnknownResolutionException;
use App\Http\Requests\CancelOperationRequest;
use App\Http\Requests\ResolveUnknownOperationRequest;
use App\Http\Requests\RetryOperationRequest;
use App\Http\Requests\StoreOperationRequest;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;
use App\Services\OperationCancellationService;
use App\Services\OperationRecoveryService;
use App\Services\OperationService;
use App\Services\OperationUnknownResolutionService;
use Illuminate\Http\Request;
use RuntimeException;

class OperationsController extends BaseController
{
    public function __construct(
        private OperationService $operationService,
        private OperationCancellationService $operationCancellationService,
        private OperationRecoveryService $operationRecoveryService,
        private OperationUnknownResolutionService $operationUnknownResolutionService,
    ) {}

    public function store(StoreOperationRequest $request, Site $site)
    {
        if (! $this->userCanAccessSite($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $validated = $request->validated();

        try {
            $operation = $this->operationService->createOperation(
                $request->user(),
                $site,
                $validated['operation_type'],
                $validated['target_json'] ?? [],
                $validated['idempotency_key'],
            );

            return $this->successResponse(
                $this->transformOperation($operation),
                [],
                201
            );
        } catch (OperationPolicyDeniedException $e) {
            return $this->errorResponse(
                'Operation denied: '.$e->getMessage(),
                'policy_denied',
                403,
                ['checks' => $e->checks, 'policy_result' => $e->policyResult]
            );
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Idempotency conflict')) {
                return $this->errorResponse('Idempotency conflict: same key with different payload', 'idempotency_conflict', 409);
            }
            throw $e;
        }
    }

    public function show(Request $request, Site $site, Operation $operation)
    {
        if (! $this->userCanAccessSite($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if ($operation->site_id !== $site->id) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        $operation->load(['latestAttempt', 'result', 'approvalRequest']);

        return $this->successResponse(
            $this->transformOperation($operation)
        );
    }

    /**
     * The active-membership scope shared by every operation-level operator
     * endpoint: an operation outside the actor's active organization sites is
     * simply not found, so the ULID cannot be used to probe another tenant.
     */
    private function visibleOperationsQuery(User $actor)
    {
        return Operation::query()
            ->whereHas('site', function ($siteQuery) use ($actor): void {
                $siteQuery->where('status', 'active')
                    ->whereHas('organization', function ($organizationQuery) use ($actor): void {
                        $organizationQuery->where('status', 'active')
                            ->whereHas('members', function ($memberQuery) use ($actor): void {
                                $memberQuery->where('user_id', $actor->id)
                                    ->where('status', 'active');
                            });
                    });
            });
    }

    public function index(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();

        $query = $this->visibleOperationsQuery($actor);

        $siteId = $request->query('site_id');
        if (is_string($siteId) && $siteId !== '') {
            $query->where('site_id', $siteId);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        // Deterministic ordering: id is the ULID tiebreaker for equal timestamps.
        $operations = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->successResponse(
            $operations->getCollection()
                ->map(fn (Operation $operation) => $this->transformOperation($operation))
                ->values()
                ->all(),
            $this->paginationMeta($operations)
        );
    }

    public function showOperation(Request $request, string $operation)
    {
        /** @var User $actor */
        $actor = $request->user();
        $source = $this->visibleOperationsQuery($actor)->find($operation);

        if ($source === null) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        $source->load(['attempts', 'latestAttempt', 'result', 'approvalRequest']);

        return $this->successResponse($this->transformOperation($source));
    }

    public function cancel(CancelOperationRequest $request, string $operation)
    {
        /** @var User $actor */
        $actor = $request->user();
        $source = $this->visibleOperationsQuery($actor)->find($operation);

        if ($source === null) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        try {
            $cancelled = $this->operationCancellationService->cancel(
                $actor,
                $source,
                $request->validated('reason'),
                $request->header('X-Request-ID'),
                $source->site,
            );
        } catch (OperationCancellationException $exception) {
            return $this->cancellationExceptionResponse($exception);
        }

        $cancelled->load(['latestAttempt', 'result', 'approvalRequest']);

        return $this->successResponse($this->transformOperation($cancelled));
    }

    private function perPage(Request $request): int
    {
        $requested = $request->query('per_page');

        if (! is_numeric($requested)) {
            return 25;
        }

        return max(1, min(100, (int) $requested));
    }

    private function cancellationExceptionResponse(OperationCancellationException $exception)
    {
        if (in_array($exception->reason, OperationCancellationException::UNAUTHORIZED_REASONS, true)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if ($exception->reason === OperationCancellationException::WRONG_TENANT) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        if ($exception->reason === OperationCancellationException::OPERATION_MISSING) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        if ($exception->reason === OperationCancellationException::MISSING_REASON) {
            return $this->errorResponse(
                'Reason is required for cancellation',
                'validation_error',
                422,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationCancellationException::OPERATION_APPROVAL_PENDING) {
            return $this->errorResponse(
                'Operation has a pending approval request; reject the approval to cancel it',
                'operation_approval_pending',
                409,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationCancellationException::ALREADY_CANCELLED) {
            return $this->errorResponse(
                'Operation is already cancelled',
                'already_cancelled',
                409,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationCancellationException::OPERATION_TERMINAL) {
            return $this->errorResponse(
                'Operation has already reached a terminal state',
                'operation_terminal',
                409,
                ['reason' => $exception->reason],
            );
        }

        return $this->errorResponse(
            'Operation is already in flight; resolve the unknown operation instead',
            'operation_in_flight',
            409,
            ['reason' => $exception->reason],
        );
    }

    public function retry(RetryOperationRequest $request, string $operation)
    {
        /** @var User $actor */
        $actor = $request->user();
        $source = Operation::query()
            ->whereKey($operation)
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

        if ($source === null) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        try {
            $successor = $this->operationRecoveryService->createSuccessor(
                $actor,
                $source,
                $request->validated('idempotency_key'),
                $request->header('X-Request-ID'),
                $source->site,
            );

            $successor->load(['latestAttempt', 'result', 'approvalRequest']);

            return $this->successResponse($this->transformOperation($successor), [], 201);
        } catch (OperationRecoveryException $exception) {
            return $this->recoveryExceptionResponse($exception);
        } catch (OperationRecoveryChainException $exception) {
            return $this->recoveryChainExceptionResponse($exception);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Idempotency conflict')) {
                return $this->errorResponse(
                    'Idempotency conflict: same key with a different recovery request',
                    'idempotency_conflict',
                    409,
                );
            }
            if (str_contains($exception->getMessage(), 'Recovery conflict: source already has a successor')) {
                return $this->errorResponse(
                    'Recovery source already has a successor',
                    'recovery_conflict',
                    409,
                );
            }

            throw $exception;
        }
    }

    public function resolveUnknown(ResolveUnknownOperationRequest $request, string $operation)
    {
        /** @var User $actor */
        $actor = $request->user();
        $source = Operation::query()
            ->whereKey($operation)
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

        if ($source === null) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        try {
            $resolved = $this->operationUnknownResolutionService->resolve(
                $actor,
                $source,
                $request->validated('resolution'),
                $request->validated('reason'),
                $request->header('X-Request-ID'),
                $source->site,
            );

            $resolved->load(['latestAttempt', 'result', 'approvalRequest']);

            return $this->successResponse($this->transformOperation($resolved));
        } catch (OperationUnknownResolutionException $exception) {
            return $this->unknownResolutionExceptionResponse($exception);
        }
    }

    protected function userCanAccessSite(Request $request, Site $site): bool
    {
        return $request->user()
            ->organizations()
            ->whereKey($site->organization_id)
            ->exists();
    }

    protected function transformOperation(Operation $operation): array
    {
        $data = [
            'id' => $operation->id,
            'recovery_of_operation_id' => $operation->recovery_of_operation_id,
            'site_id' => $operation->site_id,
            'operation_type' => $operation->operation_type,
            'target_json' => $operation->target_json,
            'status' => $operation->status,
            'policy_result' => $operation->policy_result,
            'approval_required' => $operation->approval_required,
            'idempotency_key' => $operation->idempotency_key,
            'max_attempts' => $operation->max_attempts,
            'requested_by' => $operation->requested_by,
            'resolution' => $operation->resolution,
            'resolved_at' => $operation->resolved_at?->toIso8601String(),
            'resolved_by' => $operation->resolved_by,
            'resolution_reason' => $operation->resolution_reason,
            'started_at' => $operation->started_at?->toIso8601String(),
            'finished_at' => $operation->finished_at?->toIso8601String(),
            'created_at' => $operation->created_at?->toIso8601String(),
            'updated_at' => $operation->updated_at?->toIso8601String(),
        ];

        if ($operation->relationLoaded('latestAttempt') && $operation->latestAttempt) {
            $attempt = $operation->latestAttempt;
            $data['latest_attempt'] = [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'connector_job_id' => $attempt->connector_job_id,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'finished_at' => $attempt->finished_at?->toIso8601String(),
                'error_code' => $attempt->error_code,
            ];
        }

        if ($operation->relationLoaded('result') && $operation->result) {
            $result = $operation->result;
            $data['result'] = [
                'result_status' => $result->result_status,
                'verification_status' => $result->verification_status,
                'result_summary' => $result->result_summary,
            ];
        }

        if ($operation->relationLoaded('approvalRequest') && $operation->approvalRequest) {
            $approval = $operation->approvalRequest;
            $data['approval'] = [
                'id' => $approval->id,
                'status' => $approval->status,
                'requested_by' => $approval->requested_by,
                'reviewed_by' => $approval->reviewed_by,
                'reason' => $approval->reason,
                'expires_at' => $approval->expires_at?->toIso8601String(),
                'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
            ];
        }

        return $data;
    }

    private function recoveryExceptionResponse(OperationRecoveryException $exception)
    {
        if (in_array($exception->reason, [
            OperationRecoveryException::UNAUTHORIZED_ACTOR,
            OperationRecoveryException::INACTIVE_USER,
            OperationRecoveryException::INACTIVE_MEMBERSHIP,
            OperationRecoveryException::INACTIVE_ORGANIZATION,
            OperationRecoveryException::INACTIVE_SITE,
            OperationRecoveryException::WRONG_TENANT,
        ], true)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if ($exception->reason === OperationRecoveryException::SOURCE_MISSING) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        if ($exception->reason === OperationRecoveryException::CURRENT_POLICY_DENIED) {
            return $this->errorResponse(
                'Recovery denied by current policy',
                'policy_denied',
                403,
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

    private function recoveryChainExceptionResponse(OperationRecoveryChainException $exception)
    {
        if ($exception->reason === OperationRecoveryChainException::SOURCE_MISSING) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        if ($exception->reason === OperationRecoveryChainException::LIMIT_EXCEEDED) {
            return $this->errorResponse(
                'Recovery chain limit reached',
                'recovery_chain_limit_reached',
                409,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationRecoveryChainException::SOURCE_INELIGIBLE) {
            return $this->errorResponse(
                'Recovery source is not dead-lettered',
                'source_not_dead_letter',
                409,
                ['reason' => $exception->reason],
            );
        }

        return $this->errorResponse(
            'Recovery source or lineage is not eligible',
            'recovery_precondition_failed',
            409,
            ['reason' => $exception->reason],
        );
    }

    private function unknownResolutionExceptionResponse(OperationUnknownResolutionException $exception)
    {
        if (in_array($exception->reason, [
            OperationUnknownResolutionException::UNAUTHORIZED_ACTOR,
            OperationUnknownResolutionException::INACTIVE_USER,
            OperationUnknownResolutionException::INACTIVE_MEMBERSHIP,
            OperationUnknownResolutionException::INACTIVE_ORGANIZATION,
            OperationUnknownResolutionException::INACTIVE_SITE,
            OperationUnknownResolutionException::WRONG_TENANT,
        ], true)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if ($exception->reason === OperationUnknownResolutionException::SOURCE_MISSING) {
            return $this->errorResponse('Operation not found', 'not_found', 404);
        }

        if ($exception->reason === OperationUnknownResolutionException::SOURCE_NOT_UNKNOWN) {
            return $this->errorResponse(
                'Operation is not in unknown state',
                'operation_not_unknown',
                409,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationUnknownResolutionException::ALREADY_RESOLVED) {
            return $this->errorResponse(
                'Operation has already been resolved',
                'already_resolved',
                409,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationUnknownResolutionException::INVALID_RESOLUTION) {
            return $this->errorResponse(
                'Invalid resolution type',
                'validation_error',
                422,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationUnknownResolutionException::MISSING_REASON) {
            return $this->errorResponse(
                'Reason is required for resolution',
                'validation_error',
                422,
                ['reason' => $exception->reason],
            );
        }

        if ($exception->reason === OperationUnknownResolutionException::EVIDENCE_MISSING) {
            return $this->errorResponse(
                'Insufficient evidence to resolve unknown operation',
                'evidence_missing',
                409,
                ['reason' => $exception->reason],
            );
        }

        return $this->errorResponse(
            'Unknown resolution failed',
            'unknown_resolution_failed',
            409,
            ['reason' => $exception->reason],
        );
    }
}
