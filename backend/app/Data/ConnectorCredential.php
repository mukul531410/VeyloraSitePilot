<?php

namespace App\Data;

use Illuminate\Support\Carbon;

final readonly class ConnectorCredential
{
    public function __construct(
        public string $credentialId,
        public string $nonceScopeId,
        public string $secret,
        public ?string $siteConnectionId = null,
        public string $status = 'primary',
        public int $version = 1,
        public ?Carbon $overlapExpiresAt = null,
    ) {}
}
