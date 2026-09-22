<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\ConnectorCapability;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PolicyEngine
{
    public function evaluateOperationRequest(
        User $user,
        Site $site,
        string $operationType,
        array $targetJson
    ): array {
        $correlationId = $this->generateCorrelationId();
        
        $checks = [
            'site_access' => $this->checkSiteAccess($user, $site),
            'operate_permission' => $this->checkOperatePermission($user, $site),
            'active_connection' => $this->checkActiveConnection($site),
            'capability_granted' => $this->checkCapabilityGranted($site, $operationType),
            'approval_policy' => $this->checkApprovalPolicy($site, $operationType),
        ];

        $allPassed = ! in_array(false, $checks, true);
        $policyResult = $allPassed ? 'allowed' : 'denied';

        $approvalRequired = false;
        if ($allPassed) {
            $approvalRequired = $this->requiresApproval($site, $operationType);
            if ($approvalRequired) {
                $policyResult = 'pending_approval';
            }
        }

        $this->logPolicyEvaluation($correlationId, $site, $operationType, $checks, $policyResult);

        return [
            'allowed' => $allPassed,
            'policy_result' => $policyResult,
            'approval_required' => $approvalRequired,
            'checks' => $checks,
            'correlation_id' => $correlationId,
        ];
    }

    private function checkSiteAccess(User $user, Site $site): bool
    {
        return $user->organizations()
            ->whereHas('sites', fn ($q) => $q->whereKey($site->id))
            ->exists();
    }

    private function checkOperatePermission(User $user, Site $site): bool
    {
        try {
            return $user->organizations()
                ->whereHas('sites', fn ($q) => $q->whereKey($site->id))
                ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'site.operate'))
                ->exists();
        } catch (\Illuminate\Database\QueryException|\BadMethodCallException $e) {
            if ($e instanceof \Illuminate\Database\QueryException && $e->getCode() !== '42S02') {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::warning('Permissions table/relationship not found, checking role-based fallback', [
                'user_id' => $user->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return $user->organizations()
                ->whereHas('sites', fn ($q) => $q->whereKey($site->id))
                ->whereHas('roles', fn ($q) => $q->whereIn('key', ['owner', 'admin']))
                ->exists();
        }
    }

    private function checkActiveConnection(Site $site): bool
    {
        $connection = $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();

        return $connection !== null && $connection->isActive();
    }

    private function checkCapabilityGranted(Site $site, string $operationType): bool
    {
        $connection = $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();

        if (! $connection) {
            return false;
        }

        $capability = $connection->capabilities()
            ->where('capability_key', $operationType)
            ->where('enabled', true)
            ->first();

        return $capability !== null;
    }

    private function checkApprovalPolicy(Site $site, string $operationType): bool
    {
        $orgPolicy = $site->organization->approval_policy ?? [];
        
        // Fail closed if policy is not a valid array
        if (! is_array($orgPolicy)) {
            return false;
        }

        // Fail closed if required keys are missing
        if (! isset($orgPolicy['require_approval']) || ! isset($orgPolicy['high_criticality_requires_approval'])) {
            return false;
        }

        // Fail closed if values are not booleans
        if (! is_bool($orgPolicy['require_approval']) || ! is_bool($orgPolicy['high_criticality_requires_approval'])) {
            return false;
        }

        $requiresApproval = $this->requiresApproval($site, $operationType);
        
        return ! $requiresApproval || $operationType === 'action.cache_clear';
    }

    private function requiresApproval(Site $site, string $operationType): bool
    {
        $orgPolicy = $site->organization->approval_policy ?? [];
        
        // Fail closed if policy is not a valid array
        if (! is_array($orgPolicy)) {
            \Illuminate\Support\Facades\Log::warning('Approval policy is not a valid array, failing closed', [
                'site_id' => $site->id,
                'policy' => $orgPolicy,
            ]);
            return true;
        }

        // Fail closed if required keys are missing
        if (! isset($orgPolicy['require_approval']) || ! isset($orgPolicy['high_criticality_requires_approval'])) {
            \Illuminate\Support\Facades\Log::warning('Approval policy missing required keys, failing closed', [
                'site_id' => $site->id,
                'policy' => $orgPolicy,
            ]);
            return true;
        }

        // Fail closed if values are not booleans
        if (! is_bool($orgPolicy['require_approval']) || ! is_bool($orgPolicy['high_criticality_requires_approval'])) {
            \Illuminate\Support\Facades\Log::warning('Approval policy values are not booleans, failing closed', [
                'site_id' => $site->id,
                'policy' => $orgPolicy,
            ]);
            return true;
        }

        if ($orgPolicy['require_approval'] === true) {
            return true;
        }

        if (
            $orgPolicy['high_criticality_requires_approval'] === true
            && $site->business_criticality === 'high'
        ) {
            return true;
        }

        $operation = new Operation();
        $operation->operation_type = $operationType;
        
        if ($operation->getSafetyLevel() >= Operation::SAFETY_LEVEL_HIGH_IMPACT) {
            return true;
        }

        return false;
    }

    private function logPolicyEvaluation(
        string $correlationId,
        Site $site,
        string $operationType,
        array $checks,
        string $policyResult
    ): void {
        Log::info('Policy evaluation', [
            'correlation_id' => $correlationId,
            'site_id' => $site->id,
            'operation_type' => $operationType,
            'checks' => $checks,
            'policy_result' => $policyResult,
        ]);
    }

    private function generateCorrelationId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }
}