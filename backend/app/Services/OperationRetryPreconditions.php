<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Operation;
use App\Models\OrganizationMember;
use App\Models\SiteConnection;

class OperationRetryPreconditions
{
    public function failureReason(Operation $operation, PolicyEngine $policyEngine): ?string
    {
        $operation->loadMissing(['site.organization', 'requestedBy', 'approvalRequest']);

        if ($operation->operation_type !== 'action.cache_clear') {
            return 'unsupported_operation_type';
        }

        $user = $operation->requestedBy;
        if (! $user) {
            return 'requesting_user_unavailable';
        }

        if ($user->status !== 'active') {
            return 'requesting_user_inactive';
        }

        $site = $operation->site;
        if (! $site || $site->organization_id === null) {
            return 'site_organization_unavailable';
        }

        $organization = $site->organization;
        if (! $organization || (string) $organization->id !== (string) $site->organization_id) {
            return 'site_organization_mismatch';
        }

        if ($organization->status !== 'active') {
            return 'organization_inactive';
        }

        if ($site->status !== 'active') {
            return 'site_inactive';
        }

        $activeMembership = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $activeMembership) {
            return 'organization_membership_inactive';
        }

        $policy = $policyEngine->evaluateOperationRequest(
            $user,
            $site,
            $operation->operation_type,
            $operation->target_json ?? [],
        );

        if (! $policy['allowed']) {
            return 'current_policy_denied';
        }

        if ($policy['approval_required'] && ! $operation->approval_required) {
            return 'approval_not_authorized';
        }

        if ($operation->approval_required
            && $operation->approvalRequest?->status !== ApprovalRequest::STATUS_APPROVED) {
            return 'approval_not_authorized';
        }

        $connection = $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();

        if (! $connection instanceof SiteConnection || ! $connection->isActive()) {
            return 'active_connection_unavailable';
        }

        $capability = $connection->capabilities()
            ->where('capability_key', $operation->operation_type)
            ->where('enabled', true)
            ->first();

        if (! $capability) {
            return 'required_capability_unavailable';
        }

        return null;
    }
}
