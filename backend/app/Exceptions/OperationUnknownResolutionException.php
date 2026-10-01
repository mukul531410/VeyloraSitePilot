<?php

namespace App\Exceptions;

use RuntimeException;

class OperationUnknownResolutionException extends RuntimeException
{
    public const UNAUTHORIZED_ACTOR = 'unauthorized_actor';

    public const INACTIVE_USER = 'inactive_user';

    public const INACTIVE_MEMBERSHIP = 'inactive_membership';

    public const INACTIVE_ORGANIZATION = 'inactive_organization';

    public const INACTIVE_SITE = 'inactive_site';

    public const WRONG_TENANT = 'wrong_tenant';

    public const SOURCE_MISSING = 'source_missing';

    public const SOURCE_NOT_UNKNOWN = 'source_not_unknown';

    public const ALREADY_RESOLVED = 'already_resolved';

    public const MISSING_REASON = 'missing_reason';

    public const INVALID_RESOLUTION = 'invalid_resolution';

    public const EVIDENCE_MISSING = 'evidence_missing';

    public const CONCURRENT_RESOLUTION = 'concurrent_resolution';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Operation unknown resolution rejected: {$reason}");
    }
}
