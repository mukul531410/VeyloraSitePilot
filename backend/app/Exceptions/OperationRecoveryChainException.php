<?php

namespace App\Exceptions;

use RuntimeException;

class OperationRecoveryChainException extends RuntimeException
{
    public const SOURCE_MISSING = 'source_missing';
    public const SOURCE_INELIGIBLE = 'source_ineligible';
    public const INVALID_CONFIGURATION = 'invalid_configuration';
    public const INVALID_LINEAGE = 'invalid_lineage';
    public const LIMIT_EXCEEDED = 'limit_exceeded';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Operation recovery chain rejected: {$reason}");
    }
}
