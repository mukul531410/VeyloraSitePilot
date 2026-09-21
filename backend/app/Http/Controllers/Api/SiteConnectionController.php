<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreSiteConnectionRequest;
use App\Models\Site;
use App\Models\SiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteConnectionController extends BaseController
{
    public function index(Request $request, Site $site)
    {
        if (! $request->user()->organizations()->whereHas('sites', fn ($q) => $q->whereKey($site->id))->exists()) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $connections = $site->connections()
            ->latest()
            ->get();

        return $this->successResponse(
            $connections->map(fn ($conn) => $this->transformSiteConnection($conn))->values()->all(),
        );
    }

    public function store(StoreSiteConnectionRequest $request, Site $site)
    {
        if (! $request->user()->organizations()->whereHas('sites', fn ($q) => $q->whereKey($site->id))->exists()) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $existingActive = $site->connections()->where('status', 'active')->whereNull('revoked_at')->exists();
        if ($existingActive) {
            return $this->errorResponse('Site already has an active connection.', 'conflict', 409);
        }

        return DB::transaction(function () use ($site, $request) {
            $connection = SiteConnection::create([
                'site_id' => $site->id,
                'status' => 'pending',
                'connector_version' => $request->input('connector_version'),
            ]);

            $intent = $connection->generateIntent();

            return $this->successResponse(
                [
                    ...$this->transformSiteConnection($connection),
                    'connection_intent' => $intent,
                    'intent_expires_at' => $connection->fresh()->intent_expires_at?->toIso8601String(),
                ],
                [],
                201
            );
        });
    }

    public function show(Request $request, Site $site, SiteConnection $connection)
    {
        if (! $request->user()->organizations()->whereHas('sites', fn ($q) => $q->whereKey($site->id))->exists()) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if ($connection->site_id !== $site->id) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $capabilities = $connection->capabilities()->orderBy('capability_key')->get();

        $latestHeartbeat = $connection->heartbeats()->latest('reported_at')->first();

        return $this->successResponse(
            $this->transformSiteConnection($connection),
            [
                'capabilities' => $capabilities->map(fn ($cap) => [
                    'capability_key' => $cap->capability_key,
                    'enabled' => $cap->enabled,
                    'discovered_at' => $cap->discovered_at?->toIso8601String(),
                ])->values()->all(),
                'latest_heartbeat' => $latestHeartbeat
                    ? $this->transformConnectorHeartbeat($latestHeartbeat)
                    : null,
            ]
        );
    }

    public function destroy(Request $request, Site $site, SiteConnection $connection)
    {
        if (! $request->user()->organizations()->whereHas('sites', fn ($q) => $q->whereKey($site->id))->exists()) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if ($connection->site_id !== $site->id) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if ($connection->status === 'revoked') {
            return $this->errorResponse('Connection is already revoked.', 'conflict', 409);
        }

        $connection->revoke();

        return $this->successResponse(null);
    }
}
