<?php

namespace App\Exceptions;

use RuntimeException;

class AutomationRuleException extends RuntimeException
{
    public const UNAUTHORIZED_ACTOR = 'unauthorized_actor';

    public const INACTIVE_USER = 'inactive_user';

    public const INACTIVE_MEMBERSHIP = 'inactive_membership';

    public const INACTIVE_ORGANIZATION = 'inactive_organization';

    public const INACTIVE_SITE = 'inactive_site';

    public const WRONG_TENANT = 'wrong_tenant';

    public const RULE_MISSING = 'rule_missing';

    public const ORGANIZATION_MISSING = 'organization_missing';

    public const SITE_MISSING = 'site_missing';

    public const SITE_NOT_IN_ORGANIZATION = 'site_not_in_organization';

    public const INVALID_SCHEDULE = 'invalid_schedule';

    public const UNSUPPORTED_CONDITIONS = 'unsupported_conditions';

    public const UNSUPPORTED_ACTION = 'unsupported_action';

    public const UNSUPPORTED_TRIGGER = 'unsupported_trigger';

    public const RULE_HAS_RUNS = 'rule_has_runs';

    /** @var array<int, string> */
    public const UNAUTHORIZED_REASONS = [
        self::UNAUTHORIZED_ACTOR,
        self::INACTIVE_USER,
        self::INACTIVE_MEMBERSHIP,
        self::INACTIVE_ORGANIZATION,
        self::INACTIVE_SITE,
    ];

    /** @var array<int, string> */
    public const VALIDATION_REASONS = [
        self::SITE_NOT_IN_ORGANIZATION,
        self::INVALID_SCHEDULE,
        self::UNSUPPORTED_CONDITIONS,
        self::UNSUPPORTED_ACTION,
        self::UNSUPPORTED_TRIGGER,
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Automation rule request rejected: {$reason}");
    }
}
