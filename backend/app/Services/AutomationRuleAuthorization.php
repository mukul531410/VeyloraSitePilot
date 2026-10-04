<?php

namespace App\Services;

use App\Exceptions\AutomationRuleException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Site;
use App\Models\User;

/**
 * Rule management is an owner/admin capability. Like the approval and recovery
 * contracts, the executable MVP role fallback is the owner/admin relationship
 * rather than a seeded permission row, because the documented permission
 * relationship is not part of the executable permission schema.
 */
class AutomationRuleAuthorization
{
    public const PERMISSION = 'site.manage_automation';

    public function canManage(?User $actor, Organization $organization, Site $site): bool
    {
        return $this->manageFailureReason($actor, $organization, $site) === null;
    }

    public function authorizeManage(?User $actor, Organization $organization, Site $site): void
    {
        $reason = $this->manageFailureReason($actor, $organization, $site);
        if ($reason !== null) {
            throw new AutomationRuleException($reason);
        }
    }

    public function manageFailureReason(?User $actor, Organization $organization, Site $site): ?string
    {
        if ($actor === null || ! $actor->exists || $actor->getKey() === null) {
            return AutomationRuleException::UNAUTHORIZED_ACTOR;
        }

        $activeActor = User::query()->find($actor->getKey());
        if ($activeActor === null) {
            return AutomationRuleException::UNAUTHORIZED_ACTOR;
        }
        if ($activeActor->status !== 'active') {
            return AutomationRuleException::INACTIVE_USER;
        }

        if ((string) $site->organization_id !== (string) $organization->id) {
            return AutomationRuleException::WRONG_TENANT;
        }
        if ($organization->status !== 'active') {
            return AutomationRuleException::INACTIVE_ORGANIZATION;
        }
        if ($site->status !== 'active') {
            return AutomationRuleException::INACTIVE_SITE;
        }

        $membership = OrganizationMember::query()
            ->with('role')
            ->where('organization_id', $organization->id)
            ->where('user_id', $activeActor->id)
            ->first();

        if ($membership === null) {
            return AutomationRuleException::UNAUTHORIZED_ACTOR;
        }
        if ($membership->status !== 'active') {
            return AutomationRuleException::INACTIVE_MEMBERSHIP;
        }

        $role = $membership->role;

        return $role !== null
            && (string) $role->organization_id === (string) $organization->id
            && in_array($role->key, ['owner', 'admin'], true)
                ? null
                : AutomationRuleException::UNAUTHORIZED_ACTOR;
    }
}
