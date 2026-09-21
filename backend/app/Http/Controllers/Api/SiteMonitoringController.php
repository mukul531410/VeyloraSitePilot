<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Models\Site;
use App\Models\SiteMetric;
use Illuminate\Http\Request;

class SiteMonitoringController extends BaseController
{
    public function health(Request $request, Site $site)
    {
        if (! $this->authorizeSiteAccess($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $latestHealth = $site->latestMetric(SiteMetric::METRIC_TYPE_HEALTH_STATE);

        $state = $latestHealth ? $latestHealth->unit : 'unknown';

        $openIncidents = $site->incidents()
            ->whereIn('status', ['detected', 'acknowledged', 'investigating'])
            ->orderBy('first_detected_at', 'desc')
            ->get();

        $checks = $site->healthChecks()
            ->where('checked_at', '>=', now()->subDay())
            ->orderBy('checked_at', 'desc')
            ->limit(50)
            ->get();

        return $this->successResponse(
            [
                'site_id' => $site->id,
                'health_state' => $state,
                'last_checked_at' => $latestHealth?->observed_at?->toIso8601String(),
                'open_incidents' => $openIncidents->map(fn ($inc) => $this->transformIncident($inc))->values()->all(),
                'checks' => $checks->map(fn ($check) => $this->transformHealthCheck($check))->values()->all(),
            ]
        );
    }

    public function metrics(Request $request, Site $site)
    {
        if (! $this->authorizeSiteAccess($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $metrics = $site->siteMetrics()
            ->where('observed_at', '>=', now()->subDays(7))
            ->orderBy('observed_at', 'desc')
            ->limit(200)
            ->get();

        $grouped = $metrics->groupBy('metric_type');

        return $this->successResponse(
            $grouped->map(fn ($group) => $group->map(fn ($m) => [
                'metric_type' => $m->metric_type,
                'value' => $m->value,
                'unit' => $m->unit,
                'observed_at' => $m->observed_at?->toIso8601String(),
            ])->values()->all())->values()->all()
        );
    }

    public function incidents(Request $request, Site $site)
    {
        if (! $this->authorizeSiteAccess($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $status = $request->query('status');

        $query = $site->incidents()->orderBy('first_detected_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $perPage = min($request->query('per_page', 25), 100);
        $incidents = $query->paginate($perPage);

        return $this->successResponse(
            $incidents->items() ? array_map(fn ($inc) => $this->transformIncident($inc), $incidents->items()) : [],
            [
                'current_page' => $incidents->currentPage(),
                'per_page' => $incidents->perPage(),
                'total' => $incidents->total(),
                'last_page' => $incidents->lastPage(),
            ]
        );
    }

    private function authorizeSiteAccess(Request $request, Site $site): bool
    {
        return $request->user()
            ->organizations()
            ->whereHas('sites', fn ($q) => $q->whereKey($site->id))
            ->exists();
    }

    protected function transformIncident($incident): array
    {
        return [
            'id' => $incident->id,
            'site_id' => $incident->site_id,
            'type' => $incident->type,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'title' => $incident->title,
            'description' => $incident->description,
            'first_detected_at' => $incident->first_detected_at?->toIso8601String(),
            'last_detected_at' => $incident->last_detected_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
        ];
    }

    protected function transformHealthCheck($check): array
    {
        return [
            'id' => $check->id,
            'site_id' => $check->site_id,
            'check_type' => $check->check_type,
            'status' => $check->status,
            'value_json' => $check->value_json,
            'checked_at' => $check->checked_at?->toIso8601String(),
            'created_at' => $check->created_at?->toIso8601String(),
        ];
    }
}
