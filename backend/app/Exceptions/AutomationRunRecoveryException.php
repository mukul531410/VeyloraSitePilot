<?php

namespace App\Exceptions;

use RuntimeException;

class AutomationRunRecoveryException extends RuntimeException
{
    public const UNAUTHORIZED_ACTOR = 'unauthorized_actor';

    public const INACTIVE_USER = 'inactive_user';

    public const INACTIVE_MEMBERSHIP = 'inactive_membership';

    public const INACTIVE_ORGANIZATION = 'inactive_organization';

    public const INACTIVE_SITE = 'inactive_site';

    public const WRONG_TENANT = 'wrong_tenant';

    public const RUN_MISSING = 'run_missing';

    public const UNSUPPORTED_ACTION = 'unsupported_action';

    public const MISSING_IDEMPOTENCY_KEY = 'missing_idempotency_key';

    public const MISSING_REASON = 'missing_reason';

    public const RUN_NOT_STRANDED = 'run_not_stranded';

    public const RUN_ALREADY_RECOVERED = 'run_already_recovered';

    public const RECOVERY_CONFLICT = 'recovery_conflict';

    public const INTENT_MISSING = 'intent_missing';

    public const INTENT_PROVENANCE_INVALID = 'intent_provenance_invalid';

    public const PROVENANCE_MISSING = 'provenance_missing';

    public const PROVENANCE_MISMATCH = 'provenance_mismatch';

    public const LINK_REQUIRED = 'link_required';

    public const CANDIDATE_OPERATION_EXISTS = 'candidate_operation_exists';

    public const EXECUTION_EVIDENCE_PRESENT = 'execution_evidence_present';

    public const REQUESTER_MISSING = 'requester_missing';

    public const REQUESTER_INACTIVE = 'requester_inactive';

    public const REQUESTER_UNAUTHORIZED = 'requester_unauthorized';

    public const CONNECTION_UNAVAILABLE = 'connection_unavailable';

    public const CAPABILITY_NOT_GRANTED = 'capability_not_granted';

    public const CURRENT_POLICY_UNAVAILABLE = 'current_policy_unavailable';

    public const CURRENT_POLICY_DENIED = 'current_policy_denied';

    public const OPERATION_NOT_SUBMITTED = 'operation_not_submitted';

    /** @var array<int, string> */
    public const UNAUTHORIZED_REASONS = [
        self::UNAUTHORIZED_ACTOR,
        self::INACTIVE_USER,
        self::INACTIVE_MEMBERSHIP,
        self::INACTIVE_ORGANIZATION,
        self::INACTIVE_SITE,
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Automation run recovery rejected: {$reason}");
    }
}
