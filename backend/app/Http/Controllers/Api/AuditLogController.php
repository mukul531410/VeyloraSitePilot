<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuditLogController extends BaseController
{
    public function index(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->status !== 'active') {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $validator = Validator::make($request->query(), [
            'action' => ['sometimes', 'string', 'max:191'],
            'target_type' => ['sometimes', 'string', 'max:191'],
            'site_id' => ['sometimes', 'string', 'max:26'],
            'user_id' => ['sometimes', 'string', 'max:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->errorResponse('Invalid activity filters.', 'validation_error', 422, $validator->errors()->toArray());
        }

        $query = $this->visibleAuditLogsQuery($actor);

        foreach (['action', 'target_type', 'site_id', 'user_id'] as $filter) {
            $value = $request->query($filter);
            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        // Deterministic ordering: id is the ULID tiebreaker for equal timestamps.
        $logs = $query->with(['user:id,name', 'site:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->successResponse(
            $logs->getCollection()
                ->map(fn (AuditLog $log) => $this->transformAuditLog($log))
                ->values()
                ->all(),
            $this->paginationMeta($logs)
        );
    }

    /**
     * Organization-first scope: audit rows carry a required organization and only
     * an optional site, so a site-first scope would hide org-level events
     * (site_id = null). Active membership in the active organization is required,
     * and a site-linked row must sit on an active site owned by that same
     * organization, matching the documented list rule in docs/16-api-contract.md.
     */
    private function visibleAuditLogsQuery(User $actor)
    {
        return AuditLog::query()
            ->whereHas('organization', function ($organizationQuery) use ($actor): void {
                $organizationQuery->where('status', 'active')
                    ->whereHas('members', function ($memberQuery) use ($actor): void {
                        $memberQuery->where('user_id', $actor->id)
                            ->where('status', 'active');
                    });
            })
            ->where(function ($query): void {
                $query->whereNull('site_id')
                    ->orWhereHas('site', function ($siteQuery): void {
                        $siteQuery->where('status', 'active')
                            ->whereColumn('sites.organization_id', 'audit_logs.organization_id');
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

    /**
     * Summary fields only. before_json, after_json and metadata_json are
     * deliberately never read here: they can carry payload snapshots, so the
     * read surface exposes who/what/when, not the recorded payloads.
     */
    private function transformAuditLog(AuditLog $log): array
    {
        $user = $log->relationLoaded('user') ? $log->user : null;
        $site = $log->relationLoaded('site') ? $log->site : null;

        return [
            'id' => $log->id,
            'organization_id' => $log->organization_id,
            'site_id' => $log->site_id,
            'user_id' => $log->user_id,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'correlation_id' => $log->correlation_id,
            'policy_result' => $log->policy_result,
            'created_at' => $log->created_at?->toIso8601String(),
            'user' => $user === null ? null : [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'site' => $site === null ? null : [
                'id' => $site->id,
                'name' => $site->name,
            ],
        ];
    }
}
