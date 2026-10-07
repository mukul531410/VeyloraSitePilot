<?php

namespace App\Services;

use App\Exceptions\AutomationRunRecoveryException;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\AutomationOperationOrigin;
use App\Models\AutomationRun;
use App\Models\AutomationRunIntent;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\OrganizationMember;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;

/**
 * Preconditions for Checkpoint K recovery of a STRANDED automation run: a run
 * that is still `evaluating` with no linked Operation.
 *
 * Every check here fails closed. If anything suggests a remote action may have
 * been submitted, re-evaluation and abandonment are refused and immutable
 * provenance must decide between `link` and a blocked outcome.
 */
class AutomationRunRecoveryPreconditions
{
    /**
     * Audit actions that record a submitted or executed Operation. Any of these
     * for a candidate Operation means remote work may have happened.
     */
    private const OPERATION_EVIDENCE_AUDITS = [
        'operation_requested',
        'policy_evaluated',
        'operation_state_changed',
        'attempt_dispatched',
        'job_claimed',
        'result_received',
        'attempt_retry_scheduled',
        'attempt_timeout',
        'operation_unknown',
        'operation_dead_lettered',
        'operation_retry_requested',
        'operation_unknown_resolved',
        'approval_granted',
        'approval_rejected',
    ];

    public function __construct(
        private PolicyEngine $policyEngine,
    ) {}

    /**
     * Read-only inspection for the run detail endpoint. Never mutates and never
     * authorizes a mutation; it only reports what recovery could see.
     *
     * @return array<string, mixed>
     */
    public function inspect(AutomationRun $run): array
    {
        $source = AutomationRun::query()
            ->with(['site.organization', 'intent', 'operationOrigin.operation', 'operation.site.organization'])
            ->find($run->getKey());

        if ($source === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::RUN_MISSING);
        }

        $intent = $source->intent;
        $origin = $source->operationOrigin;
        $operation = $origin?->operation;

        $candidate = $intent === null
            ? null
            : Operation::query()
                ->where('idempotency_key', $intent->original_idempotency_key)
                ->with('site.organization')
                ->first();

        return [
            'run' => $source,
            'site' => $source->site,
            'organization' => $source->site?->organization,
            'intent' => $intent,
            'origin' => $origin,
            'linked_operation' => $source->operation,
            'candidate_operation' => $candidate,
            'execution_evidence' => $this->executionEvidence($source, $intent),
        ];
    }

    /**
     * A run is recoverable only while it is evaluating with no linked Operation.
     */
    public function assertStranded(AutomationRun $run): void
    {
        if (! $run->exists || $run->getKey() === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::RUN_MISSING);
        }

        if (! $run->isStranded()) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::RUN_NOT_STRANDED);
        }
    }

    /**
     * The immutable intent snapshot is the only source of the original request:
     * action, target, requester, idempotency key, rule identity, tenant and
     * occurrence. Current rule state is never substituted for it.
     */
    public function assertIntent(AutomationRun $run): AutomationRunIntent
    {
        $intent = AutomationRunIntent::query()
            ->where('automation_run_id', $run->id)
            ->first();

        if ($intent === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::INTENT_MISSING);
        }

        if ((string) $intent->automation_run_id !== (string) $run->id
            || (string) $intent->automation_rule_id !== (string) $run->automation_rule_id
            || (string) $intent->organization_id !== (string) $run->organization_id
            || (string) $intent->site_id !== (string) $run->site_id
            || $intent->occurrence_key !== $run->occurrence_key
            || trim((string) $intent->original_operation_type) === ''
            || trim((string) $intent->original_idempotency_key) === ''
            || $intent->original_requester_id === null
            || ! is_array($intent->original_target_json)) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::INTENT_PROVENANCE_INVALID);
        }

        return $intent;
    }

    /**
     * Re-evaluation requires positive proof that nothing was ever submitted.
     * This is the core fail-closed guard: any Operation, provenance, execution
     * evidence, or Operation lifecycle audit blocks the re-submission.
     */
    public function assertNoExecutionEvidence(AutomationRun $run, AutomationRunIntent $intent): void
    {
        $origin = AutomationOperationOrigin::query()
            ->where('automation_run_id', $run->id)
            ->first();

        $candidate = Operation::query()
            ->where('idempotency_key', $intent->original_idempotency_key)
            ->first();

        if ($origin !== null) {
            if ($candidate !== null && (string) $origin->operation_id === (string) $candidate->id) {
                // Provenance proves the Operation belongs to this run. That is a
                // link case, never a re-evaluation.
                throw new AutomationRunRecoveryException(AutomationRunRecoveryException::LINK_REQUIRED);
            }

            // Provenance exists for the run but does not describe the original
            // request. The intent may already have produced an Operation.
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::EXECUTION_EVIDENCE_PRESENT);
        }

        if ($candidate !== null) {
            // A key-only candidate is never adopted, and it means the original
            // request may already have produced an Operation.
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::CANDIDATE_OPERATION_EXISTS);
        }
    }

    /**
     * Abandonment is permitted only when the intent was never submitted.
     */
    public function assertAbandonable(AutomationRun $run, AutomationRunIntent $intent): void
    {
        if (AutomationOperationOrigin::query()
            ->where('automation_run_id', $run->id)
            ->exists()) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::EXECUTION_EVIDENCE_PRESENT);
        }

        if (Operation::query()
            ->where('idempotency_key', $intent->original_idempotency_key)
            ->exists()) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::EXECUTION_EVIDENCE_PRESENT);
        }
    }

    /**
     * Linking is allowed only through immutable automation_operation_origins.
     * Every provenance field is validated against the immutable intent snapshot
     * and the authoritative tenant scope. Provenance is never created or
     * repaired here.
     *
     * @return array{origin: AutomationOperationOrigin, operation: Operation}
     */
    public function assertProvenanceProvesOwnership(
        AutomationRun $run,
        AutomationRunIntent $intent,
    ): array {
        $origin = AutomationOperationOrigin::query()
            ->where('automation_run_id', $run->id)
            ->first();

        if ($origin === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISSING);
        }

        if ((string) $origin->site_id !== (string) $run->site_id
            || (string) $origin->organization_id !== (string) $run->organization_id
            || (string) $origin->site_id !== (string) $intent->site_id
            || (string) $origin->organization_id !== (string) $intent->organization_id) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        $operation = Operation::query()
            ->with('site.organization')
            ->whereKey($origin->operation_id)
            ->first();

        if ($operation === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        if ((string) $origin->operation_id !== (string) $operation->id
            || (string) $operation->site_id !== (string) $run->site_id
            || (string) $operation->site?->organization_id !== (string) $run->organization_id) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        if ($operation->operation_type !== $intent->original_operation_type
            || $operation->target_json !== $intent->original_target_json) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        if ((string) $operation->requested_by !== (string) $intent->original_requester_id) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        if ($operation->idempotency_key !== $intent->original_idempotency_key) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        if (AutomationRun::query()
            ->where('operation_id', $operation->id)
            ->whereKeyNot($run->id)
            ->exists()) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::PROVENANCE_MISMATCH);
        }

        return ['origin' => $origin, 'operation' => $operation];
    }

    /**
     * Live re-checks performed immediately before OperationService is called.
     * The original requester from the intent snapshot is always the actor of the
     * recovered request; the recovery operator is never the requester.
     *
     * @return array<string, mixed>
     */
    public function assertReevaluationAllowed(
        AutomationRun $run,
        AutomationRunIntent $intent,
    ): array {
        $site = Site::query()->with('organization')->find($run->site_id);
        if ($site === null || $site->organization === null
            || (string) $site->organization_id !== (string) $run->organization_id) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::WRONG_TENANT);
        }

        if ($site->status !== 'active') {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::INACTIVE_SITE);
        }

        if ($site->organization->status !== 'active') {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::INACTIVE_ORGANIZATION);
        }

        $requester = User::query()->find($intent->original_requester_id);
        if ($requester === null) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::REQUESTER_MISSING);
        }

        if ($requester->status !== 'active') {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::REQUESTER_INACTIVE);
        }

        if (! $this->requesterStillAuthorized($requester, $site)) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::REQUESTER_UNAUTHORIZED);
        }

        $connection = $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();

        if (! $connection instanceof SiteConnection || ! $connection->isActive()) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::CONNECTION_UNAVAILABLE);
        }

        $capability = $connection->capabilities()
            ->where('capability_key', $intent->original_operation_type)
            ->first();
        if (! $capability?->isEffective()) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::CAPABILITY_NOT_GRANTED);
        }

        try {
            $policy = $this->policyEngine->evaluateOperationRequest(
                $requester,
                $site,
                $intent->original_operation_type,
                $intent->original_target_json,
            );
        } catch (\Throwable) {
            throw new AutomationRunRecoveryException(
                AutomationRunRecoveryException::CURRENT_POLICY_UNAVAILABLE
            );
        }

        if (! array_key_exists('allowed', $policy) || ! array_key_exists('approval_required', $policy)) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::CURRENT_POLICY_UNAVAILABLE);
        }

        if (! $policy['allowed']) {
            throw new AutomationRunRecoveryException(AutomationRunRecoveryException::CURRENT_POLICY_DENIED);
        }

        return [
            'site' => $site,
            'requester' => $requester,
            'approval_required' => (bool) $policy['approval_required'],
            'policy_result' => $policy['policy_result'] ?? null,
            'policy_checks' => $policy['checks'] ?? [],
        ];
    }

    private function requesterStillAuthorized(User $requester, Site $site): bool
    {
        $membership = OrganizationMember::query()
            ->with('role')
            ->where('organization_id', $site->organization_id)
            ->where('user_id', $requester->id)
            ->first();

        if ($membership === null || $membership->status !== 'active') {
            return false;
        }

        $role = $membership->role;

        return $role !== null
            && (string) $role->organization_id === (string) $site->organization_id
            && in_array($role->key, ['owner', 'admin'], true);
    }

    /**
     * Summarises every sign that a remote action may already have happened.
     *
     * @return array<string, mixed>
     */
    private function executionEvidence(AutomationRun $run, ?AutomationRunIntent $intent): array
    {
        $originExists = AutomationOperationOrigin::query()
            ->where('automation_run_id', $run->id)
            ->exists();

        $candidate = $intent === null
            ? null
            : Operation::query()->where('idempotency_key', $intent->original_idempotency_key)->first();

        $attempts = 0;
        $results = 0;
        $approvals = 0;
        $audits = 0;

        if ($candidate !== null) {
            $attempts = OperationAttempt::query()->where('operation_id', $candidate->id)->count();
            $results = OperationResult::query()->where('operation_id', $candidate->id)->count();
            $approvals = ApprovalRequest::query()->where('operation_id', $candidate->id)->count();
            $audits = AuditLog::query()
                ->where('target_type', 'operation')
                ->where('target_id', $candidate->id)
                ->whereIn('action', self::OPERATION_EVIDENCE_AUDITS)
                ->count();
        }

        return [
            'origin_exists' => $originExists,
            'candidate_operation_id' => $candidate?->id,
            'candidate_status' => $candidate?->status,
            'attempts' => $attempts,
            'results' => $results,
            'approval_requests' => $approvals,
            'audit_events' => $audits,
            'submitted' => $originExists
                || $candidate !== null
                || $attempts > 0
                || $results > 0
                || $approvals > 0
                || $audits > 0,
        ];
    }
}
