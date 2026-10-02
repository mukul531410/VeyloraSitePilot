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
