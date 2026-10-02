<?php

namespace App\Exceptions;

use RuntimeException;

class AutomationOperationLinkException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $operationId = null,
    ) {
        parent::__construct("Automation operation cannot be linked: {$reason}");
    }
}
