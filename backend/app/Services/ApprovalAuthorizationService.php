<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\OrganizationMember;
use App\Models\User;

class ApprovalAuthorizationService
{
    public const NOT_FOUND = 'not_found';

    public const UNAUTHORIZED = 'unauthorized';

    public function failureReason(?User $reviewer, ApprovalRequest $approval): ?string
    {
        if ($reviewer === null || ! $reviewer->exists || $reviewer->getKey() === null) {
            return self::UNAUTHORIZED;
        }

        $activeReviewer = User::query()->find($reviewer->getKey());
        if ($activeReviewer === null || $activeReviewer->status !== 'active') {
            return self::UNAUTHORIZED;
        }

        $approval->loadMissing(['operation.site.organization']);
        $operation = $approval->operation;
        $site = $operation?->site;
        $organization = $site?->organization;

        if ($operation === null || $site === null || $organization === null
            || (string) $approval->organization_id !== (string) $organization->id
            || (string) $approval->site_id !== (string) $site->id
            || (string) $site->organization_id !== (string) $organization->id) {
            return self::NOT_FOUND;
        }

        if ($organization->status !== 'active' || $site->status !== 'active') {
            return self::UNAUTHORIZED;
        }

        $membership = OrganizationMember::query()
            ->with('role')
            ->where('organization_id', $organization->id)
            ->where('user_id', $activeReviewer->id)
            ->first();

        if ($membership === null || $membership->status !== 'active') {
            return self::UNAUTHORIZED;
        }

        $role = $membership->role;
        if ($role === null
            || (string) $role->organization_id !== (string) $organization->id
            || ! in_array($role->key, ['owner', 'admin'], true)) {
            return self::UNAUTHORIZED;
        }

        if ((string) $approval->requested_by === (string) $activeReviewer->id) {
            return self::UNAUTHORIZED;
        }

        return null;
    }

    public function authorize(?User $reviewer, ApprovalRequest $approval): bool
    {
        return $this->failureReason($reviewer, $approval) === null;
    }
}
