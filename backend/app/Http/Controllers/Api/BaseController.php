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
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }
}
