<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ConnectorHeartbeatRequest;
use App\Http\Requests\ConnectorRegisterRequest;
use App\Http\Requests\ConnectorTelemetryRequest;
use App\Jobs\ProcessHealthCheck;
use App\Models\HealthCheck;
use App\Models\Site;
use App\Models\SiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectorController extends BaseController
{
    public function register(ConnectorRegisterRequest $request)
    {
        $connection = SiteConnection::where('connection_intent', $request->input('intent'))
            ->where('status', 'pending')
            ->first();

        if (! $connection || ! $connection->consumeIntent($request->input('intent'))) {
            return $this->errorResponse('Invalid or expired connection intent.', 'unauthorized', 401);
        }

        return DB::transaction(function () use ($connection, $request) {
            $token = $connection->activate($request->input('connector_version'));

            return $this->successResponse([
                'connection_id' => $connection->id,
                'status' => $connection->status,
                'token' => $token,
            ], [], 201);
        });
    }

    public function heartbeat(ConnectorHeartbeatRequest $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $connection->heartbeats()->create([
            'connector_version' => $request->input('connector_version'),
            'wordpress_version' => $request->input('wordpress_version'),
            'php_version' => $request->input('php_version'),
            'status' => $request->input('status'),
            'reported_at' => now(),
        ]);

        $connection->update([
            'last_seen_at' => now(),
            'connector_version' => $request->input('connector_version'),
        ]);

        return $this->successResponse([
            'connection_id' => $connection->id,
            'status' => $connection->status,
        ]);
    }

    public function capabilities(Request $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $capabilities = $connection->capabilities()->orderBy('capability_key')->get();

        return $this->successResponse(
            $capabilities->map(fn ($cap) => [
                'capability_key' => $cap->capability_key,
                'enabled' => $cap->enabled,
                'discovered_at' => $cap->discovered_at?->toIso8601String(),
            ])->values()->all()
        );
    }

    public function telemetry(ConnectorTelemetryRequest $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $siteId = $connection->site_id;
        $now = now();

        foreach ($request->input('observations') as $observation) {
            HealthCheck::create([
                'site_id' => $siteId,
                'check_type' => $observation['check_type'],
                'status' => $observation['status'],
                'value_json' => $observation['value'] ?? [],
                'checked_at' => $observation['checked_at'] ?? $now,
            ]);
        }

        ProcessHealthCheck::dispatch($siteId)
            ->delay(now()->addSeconds(5));

        return $this->successResponse([
            'connection_id' => $connection->id,
            'observations_stored' => count($request->input('observations')),
        ]);
    }
}
