<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

abstract class BaseController extends Controller
{
    protected function successResponse($data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
            'request_id' => $this->requestId(),
        ], $status);
    }

    protected function errorResponse(string $message, string $code = 'error', int $status = 400, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'request_id' => $this->requestId(),
        ], $status);
    }

    protected function requestId(): ?string
    {
        return request()->header('X-Request-ID', Str::uuid()->toString());
    }

    protected function transformUser($user): array
    {
        $organizations = $user->relationLoaded('organizations')
            ? $user->organizations
            : $user->organizations()->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'organizations' => $organizations->map(fn ($org) => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'status' => $org->status,
            ])->values()->all(),
        ];
    }

    protected function transformOrganization($organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'created_at' => $organization->created_at?->toIso8601String(),
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }

    protected function transformSite($site): array
    {
        $connectionStatus = null;
        if ($site->relationLoaded('latestConnection')) {
            $connectionStatus = $site->latestConnection ? $site->latestConnection->status : null;
        }

        return [
            'id' => $site->id,
            'organization_id' => $site->organization_id,
            'name' => $site->name,
            'url' => $site->url,
            'environment' => $site->environment,
            'status' => $site->status,
            'business_criticality' => $site->business_criticality,
            'timezone' => $site->timezone,
            'notes' => $site->notes,
            'connection_status' => $connectionStatus,
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }

    protected function transformSiteConnection($connection): array
    {
        return [
            'id' => $connection->id,
            'site_id' => $connection->site_id,
            'status' => $connection->status,
            'connector_version' => $connection->connector_version,
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'last_seen_at' => $connection->last_seen_at?->toIso8601String(),
            'revoked_at' => $connection->revoked_at?->toIso8601String(),
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }

    protected function transformConnectorHeartbeat($heartbeat): array
    {
        return [
            'id' => $heartbeat->id,
            'connector_version' => $heartbeat->connector_version,
            'wordpress_version' => $heartbeat->wordpress_version,
            'php_version' => $heartbeat->php_version,
            'status' => $heartbeat->status,
            'reported_at' => $heartbeat->reported_at?->toIso8601String(),
            'created_at' => $heartbeat->created_at?->toIso8601String(),
        ];
    }
}
