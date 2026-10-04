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

    public function inventory(Request $request, Site $site)
    {
        if (! $this->authorizeSiteAccess($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $snapshot = $site->latestInventorySnapshot();

        if ($snapshot === null) {
            // A site that has never synced is not an error; the dashboard needs to
            // tell "not synced yet" apart from a failed request.
            return $this->successResponse([
                'site_id' => $site->id,
                'snapshot' => null,
                'core' => null,
                'plugins' => [],
                'themes' => [],
                'summary' => [
                    'plugins' => 0,
                    'active_plugins' => 0,
                    'plugins_with_updates' => 0,
                    'themes' => 0,
                    'active_themes' => 0,
                    'themes_with_updates' => 0,
                    'core_update_available' => false,
                    'updates_available' => 0,
                ],
            ]);
        }

        $snapshot->load(['coreState', 'plugins', 'themes']);
        $core = $snapshot->coreState;
        $pluginsWithUpdates = $snapshot->plugins->where('update_available', true)->count();
        $themesWithUpdates = $snapshot->themes->where('update_available', true)->count();
        $coreUpdate = (bool) ($core?->update_available);

        return $this->successResponse([
            'site_id' => $site->id,
            'snapshot' => [
                'id' => $snapshot->id,
                'snapshot_type' => $snapshot->snapshot_type,
                'status' => $snapshot->status,
                'checksum' => $snapshot->checksum,
                'started_at' => $snapshot->started_at?->toIso8601String(),
                'completed_at' => $snapshot->completed_at?->toIso8601String(),
            ],
            'core' => $core === null ? null : [
                'id' => $core->id,
                'wordpress_version' => $core->wordpress_version,
                'php_version' => $core->php_version,
                'update_available' => (bool) $core->update_available,
                'status' => $core->status,
            ],
            'plugins' => $snapshot->plugins
                ->sortBy('plugin_key')
                ->map(fn ($plugin) => [
                    'id' => $plugin->id,
                    'key' => $plugin->plugin_key,
                    'name' => $plugin->name,
                    'version' => $plugin->version,
                    'update_available' => (bool) $plugin->update_available,
                    'active' => (bool) $plugin->active,
                    'status' => $plugin->status,
                    'metadata' => $plugin->metadata_json,
                ])->values()->all(),
            'themes' => $snapshot->themes
                ->sortBy('theme_key')
                ->map(fn ($theme) => [
                    'id' => $theme->id,
                    'key' => $theme->theme_key,
                    'name' => $theme->name,
                    'version' => $theme->version,
                    'update_available' => (bool) $theme->update_available,
                    'active' => (bool) $theme->active,
                    'status' => $theme->status,
                    'metadata' => $theme->metadata_json,
                ])->values()->all(),
            'summary' => [
                'plugins' => $snapshot->plugins->count(),
                'active_plugins' => $snapshot->plugins->where('active', true)->count(),
                'plugins_with_updates' => $pluginsWithUpdates,
                'themes' => $snapshot->themes->count(),
                'active_themes' => $snapshot->themes->where('active', true)->count(),
                'themes_with_updates' => $themesWithUpdates,
                'core_update_available' => $coreUpdate,
                'updates_available' => $pluginsWithUpdates + $themesWithUpdates + (int) $coreUpdate,
            ],
        ]);
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
