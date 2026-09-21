<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteController extends BaseController
{
    public function index(Request $request)
    {
        $organizationIds = $request->user()->organizations()->pluck('organizations.id');

        $sites = Site::whereIn('organization_id', $organizationIds)
            ->latest()
            ->with('latestConnection')
            ->paginate(25);

        return $this->successResponse(
            $sites->getCollection()->map(fn ($site) => $this->transformSite($site))->values()->all(),
            $this->paginationMeta($sites)
        );
    }

    public function store(StoreSiteRequest $request)
    {
        $validated = $request->validated();

        if (! $request->user()->organizations()->whereKey($validated['organization_id'])->exists()) {
            return $this->errorResponse('The specified organization does not belong to your account.', 'unauthorized', 403);
        }

        $site = DB::transaction(function () use ($validated) {
            return Site::create([
                'organization_id' => $validated['organization_id'],
                'name' => $validated['name'],
                'url' => $validated['url'],
                'environment' => $validated['environment'] ?? 'production',
                'status' => $validated['status'] ?? 'active',
                'business_criticality' => $validated['business_criticality'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return $this->successResponse(
            $this->transformSite($site),
            [],
            201
        );
    }

    public function show(Request $request, Site $site)
    {
        if (! $this->userCanAccessSite($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $site->load('latestConnection');

        return $this->successResponse(
            $this->transformSite($site)
        );
    }

    public function update(UpdateSiteRequest $request, Site $site)
    {
        if (! $this->userCanAccessSite($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $site->update($request->validated());

        return $this->successResponse(
            $this->transformSite($site)
        );
    }

    public function destroy(Request $request, Site $site)
    {
        if (! $this->userCanAccessSite($request, $site)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $site->delete();

        return $this->successResponse(null);
    }

    protected function userCanAccessSite(Request $request, Site $site): bool
    {
        return $request->user()
            ->organizations()
            ->whereKey($site->organization_id)
            ->exists();
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
