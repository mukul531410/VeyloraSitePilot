<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\OrganizationMember;
use App\Models\User;

class AutomationRequesterAuthorization
{
    /**
     * Authorization is re-evaluated for the rule creator at run evaluation time.
     * Owner/admin is the executable MVP fallback currently used by PolicyEngine
     * when the documented permission relationship is unavailable.
     */
    public function canRequest(?AutomationRule $rule): bool
    {
        if ($rule === null || ! $rule->exists || $rule->getKey() === null) {
            return false;
        }

        $currentRule = AutomationRule::query()->find($rule->getKey());
        if ($currentRule === null || $currentRule->created_by === null) {
            return false;
        }

        $site = $currentRule->site()->first();
        $organization = $currentRule->organization()->first();
        $creator = User::query()->find($currentRule->created_by);

        if ($site === null || $organization === null || $creator === null
            || (string) $site->organization_id !== (string) $organization->id
            || $creator->status !== 'active'
            || $organization->status !== 'active'
            || $site->status !== 'active') {
            return false;
        }

        $membership = OrganizationMember::query()
            ->with('role')
            ->where('organization_id', $organization->id)
            ->where('user_id', $creator->id)
            ->first();

        if ($membership === null || $membership->status !== 'active') {
            return false;
        }

        $role = $membership->role;

        return $role !== null
            && (string) $role->organization_id === (string) $organization->id
            && in_array($role->key, ['owner', 'admin'], true);
    }
}
