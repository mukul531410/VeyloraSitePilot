<?php

namespace App\Http\Controllers\Api;

use App\Models\AvailableUpdate;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

class AvailableUpdatesController extends BaseController
{
    public function index(Request $request, Site $site)
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $this->siteIsVisibleTo($actor, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        foreach ([
            'status' => [AvailableUpdate::STATUS_OPEN, AvailableUpdate::STATUS_RESOLVED],
            'type' => [AvailableUpdate::TYPE_CORE, AvailableUpdate::TYPE_PLUGIN, AvailableUpdate::TYPE_THEME],
            'severity' => [AvailableUpdate::SEVERITY_INFO],
        ] as $filter => $allowed) {
            $value = $request->query($filter);
            if ($value !== null && ! in_array($value, $allowed, true)) {
                return $this->errorResponse('Invalid available update filter.', 'validation_error', 422, [
                    'field' => $filter,
                    'allowed' => $allowed,
                ]);
            }
        }

        $query = $site->availableUpdates()
            ->where('status', $request->query('status', AvailableUpdate::STATUS_OPEN));

        foreach (['type', 'severity'] as $filter) {
            if ($request->query($filter) !== null) {
                $query->where($filter, $request->query($filter));
            }
        }

        $requestedPerPage = $request->query('per_page');
        $perPage = is_numeric($requestedPerPage)
            ? max(1, min(100, (int) $requestedPerPage))
            : 25;

        $findings = $query
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->paginate($perPage);

        return $this->successResponse(
            $findings->getCollection()
                ->map(fn (AvailableUpdate $finding): array => $this->transformFinding($finding))
                ->values()
                ->all(),
            $this->paginationMeta($findings),
        );
    }

    private function siteIsVisibleTo(User $actor, Site $site): bool
    {
        return Site::query()
            ->whereKey($site->id)
            ->where('status', 'active')
            ->whereHas('organization', function ($organizationQuery) use ($actor): void {
                $organizationQuery->where('status', 'active')
                    ->whereHas('members', function ($memberQuery) use ($actor): void {
                        $memberQuery->where('user_id', $actor->id)
                            ->where('status', 'active');
                    });
            })
            ->exists();
    }

    private function transformFinding(AvailableUpdate $finding): array
    {
        return [
            'id' => $finding->id,
            'site_id' => $finding->site_id,
            'type' => $finding->type,
            'item_identifier' => $finding->item_identifier,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'first_seen_at' => $finding->first_seen_at?->toIso8601String(),
            'last_seen_at' => $finding->last_seen_at?->toIso8601String(),
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
        ];
    }
}
