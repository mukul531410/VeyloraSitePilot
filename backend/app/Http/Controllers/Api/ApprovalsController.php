<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RejectApprovalRequest;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\ApprovalAuthorizationService;
use App\Services\OperationService;
use Illuminate\Http\Request;
use RuntimeException;

class ApprovalsController extends BaseController
{
    public function __construct(
        private ApprovalAuthorizationService $authorization,
        private OperationService $operationService,
    ) {}

    public function index(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();

        $status = $request->query('status');
        if (is_string($status) && $status !== '' && ! in_array($status, [
            ApprovalRequest::STATUS_PENDING,
            ApprovalRequest::STATUS_APPROVED,
            ApprovalRequest::STATUS_REJECTED,
            ApprovalRequest::STATUS_EXPIRED,
        ], true)) {
            return $this->errorResponse('Invalid approval status filter.', 'validation_error', 422, [
                'status' => ['The status filter must be one of: pending, approved, rejected, expired.'],
            ]);
        }

        $query = $this->visibleApprovalsQuery($actor);

        $siteId = $request->query('site_id');
        if (is_string($siteId) && $siteId !== '') {
            $query->where('site_id', $siteId);
        }

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        // Deterministic ordering: id is the ULID tiebreaker for equal timestamps.
        $approvals = $query->with(['operation:id,operation_type,status', 'site:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->successResponse(
            $approvals->getCollection()
                ->map(fn (ApprovalRequest $approval) => $this->transformApproval($approval))
                ->values()
                ->all(),
            $this->paginationMeta($approvals)
        );
    }

    public function approve(Request $request, string $approval)
    {
        return $this->review($request, $approval, function (ApprovalRequest $approvalRequest, User $reviewer) {
            return $this->operationService->approveOperation($approvalRequest, $reviewer);
        });
    }

    public function reject(RejectApprovalRequest $request, string $approval)
    {
        return $this->review($request, $approval, function (ApprovalRequest $approvalRequest, User $reviewer) use ($request) {
            return $this->operationService->rejectOperation(
                $approvalRequest,
                $reviewer,
                $request->validated('reason'),
            );
        });
    }

    /**
     * The active-membership scope shared by every approval-level operator
     * endpoint: an approval outside the actor's active organization sites is
     * simply not found, so the ULID cannot be used to probe another tenant.
     */
    private function visibleApprovalsQuery(User $actor)
    {
        return ApprovalRequest::query()
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

    private function perPage(Request $request): int
    {
        $requested = $request->query('per_page');

        if (! is_numeric($requested)) {
            return 25;
        }

        return max(1, min(100, (int) $requested));
    }

    private function transformApproval(ApprovalRequest $approval): array
    {
        $operation = $approval->relationLoaded('operation') ? $approval->operation : null;
        $site = $approval->relationLoaded('site') ? $approval->site : null;

        return [
            'id' => $approval->id,
            'organization_id' => $approval->organization_id,
            'site_id' => $approval->site_id,
            'operation_id' => $approval->operation_id,
            'status' => $approval->status,
            'requested_by' => $approval->requested_by,
            'reviewed_by' => $approval->reviewed_by,
            'reason' => $approval->reason,
            'expires_at' => $approval->expires_at?->toIso8601String(),
            'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
            'created_at' => $approval->created_at?->toIso8601String(),
            'updated_at' => $approval->updated_at?->toIso8601String(),
            'site' => $site === null ? null : [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'operation' => $operation === null ? null : [
                'id' => $operation->id,
                'operation_type' => $operation->operation_type,
                'status' => $operation->status,
            ],
        ];
    }

    private function review(Request $request, string $approvalId, callable $action)
    {
        $approval = ApprovalRequest::query()->find($approvalId);
        if ($approval === null) {
            return $this->errorResponse('Approval not found', 'not_found', 404);
        }

        /** @var User $reviewer */
        $reviewer = $request->user();
        $authorizationFailure = $this->authorization->failureReason($reviewer, $approval);
        if ($authorizationFailure === ApprovalAuthorizationService::NOT_FOUND) {
            return $this->errorResponse('Approval not found', 'not_found', 404);
        }
        if ($authorizationFailure !== null) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $approval->loadMissing('operation');
        if (! $approval->isPending()
            || $approval->operation?->status !== 'pending_approval') {
            return $this->errorResponse('Approval is no longer pending', 'approval_not_pending', 409);
        }

        try {
            $operation = $action($approval, $reviewer);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'expired')) {
                return $this->errorResponse('Approval request has expired', 'approval_expired', 409);
            }
            if (str_contains($exception->getMessage(), 'not pending')) {
                return $this->errorResponse('Approval is no longer pending', 'approval_not_pending', 409);
            }
            if (str_contains($exception->getMessage(), 'Self-')) {
                return $this->errorResponse('Requester cannot review their own operation', 'unauthorized', 403);
            }

            throw $exception;
        }

        return $this->successResponse([
            'approval_id' => $approval->id,
            'approval_status' => $approval->fresh()->status,
            'operation_id' => $operation->id,
            'operation_status' => $operation->status,
        ]);
    }
}
