<?php

namespace App\Services;

use App\Exceptions\AutomationRunRecoveryException;
use App\Models\AutomationRun;
use App\Models\OrganizationMember;
use App\Models\Site;
use App\Models\User;

class AutomationRunRecoveryAuthorization
{
    /**
     * Recovery is a dedicated operator capability and deliberately does not reuse
     * the `site.retry` permission. Like the approval contract, the executable MVP
     * role fallback is the owner/admin relationship rather than a seeded
     * permission row.
     */
    public const PERMISSION = 'site.recover_automation';

    public function canRecover(?User $actor, AutomationRun $run, ?Site $expectedSite = null): bool
    {
        return $this->failureReason($actor, $run, $expectedSite) === null;
    }

    public function authorize(?User $actor, AutomationRun $run, ?Site $expectedSite = null): void
    {
        $reason = $this->failureReason($actor, $run, $expectedSite);
        if ($reason !== null) {
            throw new AutomationRunRecoveryException($reason);
        }
    }

    public function failureReason(?User $actor, AutomationRun $run, ?Site $expectedSite = null): ?string
    {
        if ($actor === null || ! $actor->exists || $actor->getKey() === null) {
            return AutomationRunRecoveryException::UNAUTHORIZED_ACTOR;
        }

        $activeActor = User::query()->find($actor->getKey());
        if ($activeActor === null) {
            return AutomationRunRecoveryException::UNAUTHORIZED_ACTOR;
        }
        if ($activeActor->status !== 'active') {
            return AutomationRunRecoveryException::INACTIVE_USER;
        }

        if (! $run->exists || $run->getKey() === null) {
            return AutomationRunRecoveryException::RUN_MISSING;
        }

        $source = AutomationRun::query()->with('site.organization')->find($run->getKey());
        if ($source === null || $source->site === null || $source->site->organization === null) {
            return AutomationRunRecoveryException::WRONG_TENANT;
        }

        $site = $source->site;
        $organization = $site->organization;
        if ((string) $site->organization_id !== (string) $organization->id
            || (string) $source->organization_id !== (string) $organization->id) {
            return AutomationRunRecoveryException::WRONG_TENANT;
        }
        if ($expectedSite !== null && (string) $expectedSite->getKey() !== (string) $site->getKey()) {
            return AutomationRunRecoveryException::WRONG_TENANT;
        }
        if ($organization->status !== 'active') {
            return AutomationRunRecoveryException::INACTIVE_ORGANIZATION;
        }
        if ($site->status !== 'active') {
            return AutomationRunRecoveryException::INACTIVE_SITE;
        }

        $membership = OrganizationMember::query()
            ->with('role')
            ->where('organization_id', $organization->id)
            ->where('user_id', $activeActor->id)
            ->first();

        if ($membership === null) {
            return AutomationRunRecoveryException::UNAUTHORIZED_ACTOR;
        }
        if ($membership->status !== 'active') {
            return AutomationRunRecoveryException::INACTIVE_MEMBERSHIP;
        }

        $role = $membership->role;
        if ($role === null
            || (string) $role->organization_id !== (string) $organization->id
            || ! in_array($role->key, ['owner', 'admin'], true)) {
            return AutomationRunRecoveryException::UNAUTHORIZED_ACTOR;
        }

        return null;
    }
}
