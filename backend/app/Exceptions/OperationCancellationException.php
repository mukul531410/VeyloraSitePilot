<?php

namespace App\Exceptions;

use RuntimeException;

class OperationCancellationException extends RuntimeException
{
    public const UNAUTHORIZED_ACTOR = 'unauthorized_actor';

    public const INACTIVE_USER = 'inactive_user';

    public const INACTIVE_MEMBERSHIP = 'inactive_membership';

    public const INACTIVE_ORGANIZATION = 'inactive_organization';

    public const INACTIVE_SITE = 'inactive_site';

    public const WRONG_TENANT = 'wrong_tenant';

    public const OPERATION_MISSING = 'operation_missing';

    public const OPERATION_TERMINAL = 'operation_terminal';

    public const OPERATION_IN_FLIGHT = 'operation_in_flight';

    public const OPERATION_APPROVAL_PENDING = 'operation_approval_pending';

    public const ALREADY_CANCELLED = 'already_cancelled';

    public const MISSING_REASON = 'missing_reason';

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
        parent::__construct("Operation cancellation rejected: {$reason}");
    }
}
