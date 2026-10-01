<?php

namespace App\Exceptions;

use RuntimeException;

class OperationRecoveryException extends RuntimeException
{
    public const UNAUTHORIZED_ACTOR = 'unauthorized_actor';
    public const INACTIVE_USER = 'inactive_user';
    public const INACTIVE_MEMBERSHIP = 'inactive_membership';
    public const INACTIVE_ORGANIZATION = 'inactive_organization';
    public const INACTIVE_SITE = 'inactive_site';
    public const WRONG_TENANT = 'wrong_tenant';
    public const SOURCE_MISSING = 'source_missing';
    public const SOURCE_NOT_DEAD_LETTER = 'source_not_dead_letter';
    public const EXECUTION_EVIDENCE_UNCERTAIN = 'execution_evidence_uncertain';
    public const ORIGINAL_APPROVAL_UNAVAILABLE = 'original_approval_unavailable';
    public const CURRENT_POLICY_UNAVAILABLE = 'current_policy_unavailable';
    public const CURRENT_POLICY_DENIED = 'current_policy_denied';
    public const CONNECTION_UNAVAILABLE = 'connection_unavailable';
    public const CAPABILITY_NOT_GRANTED = 'capability_not_granted';
    public const RECOVERY_CHAIN_UNAVAILABLE = 'recovery_chain_unavailable';
    public const RECOVERY_CHAIN_LIMIT_REACHED = 'recovery_chain_limit_reached';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Operation recovery rejected: {$reason}");
    }
}
