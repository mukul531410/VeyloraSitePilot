<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OperationPolicyDeniedException;
use App\Http\Requests\StoreOperationRequest;
use App\Models\ApprovalRequest;
use App\Models\Operation;
use App\Models\Site;
use App\Services\OperationService;
use Illuminate\Http\Request;

class OperationsController extends BaseController
{
    public function __construct(
        private OperationService $operationService,
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
                'Operation denied: ' . $e->getMessage(),
                'policy_denied',
                403,
                ['checks' => $e->checks, 'policy_result' => $e->policyResult]
            );
        } catch (\RuntimeException $e) {
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
            'site_id' => $operation->site_id,
            'operation_type' => $operation->operation_type,
            'target_json' => $operation->target_json,
            'status' => $operation->status,
            'policy_result' => $operation->policy_result,
            'approval_required' => $operation->approval_required,
            'idempotency_key' => $operation->idempotency_key,
            'requested_by' => $operation->requested_by,
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
}