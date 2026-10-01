<?php

namespace App\Services;

use App\Exceptions\OperationUnknownResolutionException;
use App\Models\Operation;
use App\Models\OrganizationMember;
use App\Models\Site;
use App\Models\User;

class OperationUnknownResolutionAuthorization
{
    public const PERMISSION = 'site.resolve_unknown';

    public function canResolveUnknown(?User $actor, Operation $operation, ?Site $expectedSite = null): bool
    {
        return $this->failureReason($actor, $operation, $expectedSite) === null;
    }

    public function authorize(?User $actor, Operation $operation, ?Site $expectedSite = null): void
    {
        $reason = $this->failureReason($actor, $operation, $expectedSite);
        if ($reason !== null) {
            throw new OperationUnknownResolutionException($reason);
        }
    }

    public function failureReason(?User $actor, Operation $operation, ?Site $expectedSite = null): ?string
    {
        if ($actor === null || ! $actor->exists || $actor->getKey() === null) {
            return OperationUnknownResolutionException::UNAUTHORIZED_ACTOR;
        }

        $activeActor = User::query()->find($actor->getKey());
        if ($activeActor === null) {
            return OperationUnknownResolutionException::UNAUTHORIZED_ACTOR;
        }
        if ($activeActor->status !== 'active') {
            return OperationUnknownResolutionException::INACTIVE_USER;
        }

        if (! $operation->exists || $operation->getKey() === null) {
            return OperationUnknownResolutionException::SOURCE_MISSING;
        }
        $source = Operation::query()->with('site.organization')->find($operation->getKey());
        if ($source === null || $source->site === null || $source->site->organization === null) {
            return OperationUnknownResolutionException::WRONG_TENANT;
        }

        $site = $source->site;
        $organization = $site->organization;
        if ((string) $site->organization_id !== (string) $organization->id) {
            return OperationUnknownResolutionException::WRONG_TENANT;
        }
        if ($expectedSite !== null && (string) $expectedSite->getKey() !== (string) $site->getKey()) {
            return OperationUnknownResolutionException::WRONG_TENANT;
        }
        if ($organization->status !== 'active') {
            return OperationUnknownResolutionException::INACTIVE_ORGANIZATION;
        }
        if ($site->status !== 'active') {
            return OperationUnknownResolutionException::INACTIVE_SITE;
        }

        $membership = OrganizationMember::query()
            ->with('role')
            ->where('organization_id', $organization->id)
            ->where('user_id', $activeActor->id)
            ->first();

        if ($membership === null) {
            return OperationUnknownResolutionException::UNAUTHORIZED_ACTOR;
        }
        if ($membership->status !== 'active') {
            return OperationUnknownResolutionException::INACTIVE_MEMBERSHIP;
        }

        $role = $membership->role;
        if ($role === null
            || (string) $role->organization_id !== (string) $organization->id
            || ! in_array($role->key, ['owner', 'admin'], true)) {
            return OperationUnknownResolutionException::UNAUTHORIZED_ACTOR;
        }

        return null;
    }
}
