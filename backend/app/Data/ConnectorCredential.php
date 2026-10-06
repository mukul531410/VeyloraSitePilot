<?php

namespace App\Data;

final readonly class ConnectorCredential
{
    public function __construct(
        public string $credentialId,
        public string $nonceScopeId,
        public string $secret,
    ) {}
}
