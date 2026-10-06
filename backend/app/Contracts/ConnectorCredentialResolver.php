<?php

namespace App\Contracts;

use App\Data\ConnectorCredential;

interface ConnectorCredentialResolver
{
    public function resolve(string $credentialId): ?ConnectorCredential;
}
