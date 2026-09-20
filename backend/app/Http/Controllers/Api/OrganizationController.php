<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends BaseController
{
    public function index(Request $request)
    {
        $organizations = $request->user()
            ->organizations()
            ->latest()
            ->paginate(25);

        return $this->successResponse(
            $organizations->getCollection()->map(fn ($org) => $this->transformOrganization($org))->values()->all(),
            $this->paginationMeta($organizations)
        );
    }

    public function store(StoreOrganizationRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $validated = $request->validated();

            $organization = Organization::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'status' => $validated['status'] ?? 'active',
            ]);

            $role = Role::create([
                'organization_id' => $organization->id,
                'name' => 'Owner',
                'key' => 'owner',
            ]);

            \App\Models\OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $request->user()->id,
                'role_id' => $role->id,
                'status' => 'active',
            ]);

            return $this->successResponse(
                $this->transformOrganization($organization),
                [],
                201
            );
        });
    }

    public function show(Request $request, Organization $organization)
    {
        if (! $request->user()->organizations()->whereKey($organization->id)->exists()) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        return $this->successResponse(
            $this->transformOrganization($organization)
        );
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization)
    {
        if (! $request->user()->organizations()->whereKey($organization->id)->exists()) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $organization->update($request->validated());

        return $this->successResponse(
            $this->transformOrganization($organization)
        );
    }

    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
