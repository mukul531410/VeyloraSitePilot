<?php

namespace App\Services;

use App\Exceptions\OperationRecoveryException;
use App\Models\Operation;
use App\Models\OrganizationMember;
use App\Models\Site;
use App\Models\User;

class OperationRecoveryAuthorization
{
    public const PERMISSION = 'site.retry';

    public function canRetryOperation(?User $actor, Operation $operation, ?Site $expectedSite = null): bool
    {
        return $this->failureReason($actor, $operation, $expectedSite) === null;
    }

    public function authorize(?User $actor, Operation $operation, ?Site $expectedSite = null): void
    {
        $reason = $this->failureReason($actor, $operation, $expectedSite);
        if ($reason !== null) {
            throw new OperationRecoveryException($reason);
        }
    }

    public function failureReason(?User $actor, Operation $operation, ?Site $expectedSite = null): ?string
    {
        if ($actor === null || ! $actor->exists || $actor->getKey() === null) {
            return OperationRecoveryException::UNAUTHORIZED_ACTOR;
        }

        $activeActor = User::query()->find($actor->getKey());
        if ($activeActor === null) {
            return OperationRecoveryException::UNAUTHORIZED_ACTOR;
        }
        if ($activeActor->status !== 'active') {
            return OperationRecoveryException::INACTIVE_USER;
        }

        if (! $operation->exists || $operation->getKey() === null) {
            return OperationRecoveryException::SOURCE_MISSING;
        }
        $source = Operation::query()->with('site.organization')->find($operation->getKey());
        if ($source === null || $source->site === null || $source->site->organization === null) {
            return OperationRecoveryException::WRONG_TENANT;
        }

        $site = $source->site;
        $organization = $site->organization;
        if ((string) $site->organization_id !== (string) $organization->id) {
            return OperationRecoveryException::WRONG_TENANT;
        }
        if ($expectedSite !== null && (string) $expectedSite->getKey() !== (string) $site->getKey()) {
            return OperationRecoveryException::WRONG_TENANT;
        }
        if ($organization->status !== 'active') {
            return OperationRecoveryException::INACTIVE_ORGANIZATION;
        }
        if ($site->status !== 'active') {
            return OperationRecoveryException::INACTIVE_SITE;
        }

        $membership = OrganizationMember::query()
            ->with('role')
            ->where('organization_id', $organization->id)
            ->where('user_id', $activeActor->id)
            ->first();

        if ($membership === null) {
            return OperationRecoveryException::UNAUTHORIZED_ACTOR;
        }
        if ($membership->status !== 'active') {
            return OperationRecoveryException::INACTIVE_MEMBERSHIP;
        }

        $role = $membership->role;
        if ($role === null
            || (string) $role->organization_id !== (string) $organization->id
            || ! in_array($role->key, ['owner', 'admin'], true)) {
            return OperationRecoveryException::UNAUTHORIZED_ACTOR;
        }

        return null;
    }
}
