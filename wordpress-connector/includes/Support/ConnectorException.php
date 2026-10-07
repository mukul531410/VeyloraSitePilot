<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Support;

use RuntimeException;

final class ConnectorException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(match ($errorCode) {
            'invalid_configuration' => 'SitePilot connection configuration is invalid.',
            'missing_credentials' => 'SitePilot credentials are not configured.',
            'invalid_credentials' => 'SitePilot credentials are invalid.',
            'capability_unavailable' => 'The required SitePilot read capability is not enabled.',
            default => 'The SitePilot request could not be completed.',
        });
    }
}
