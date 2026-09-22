<?php

namespace App\Exceptions;

use RuntimeException;

class OperationPolicyDeniedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $checks,
        public readonly string $policyResult,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}