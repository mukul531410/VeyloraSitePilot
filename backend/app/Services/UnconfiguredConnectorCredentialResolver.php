<?php

namespace App\Services;

use App\Contracts\ConnectorCredentialResolver;
use App\Data\ConnectorCredential;

/**
 * Commit 1 deliberately has no production HMAC credential store. Commit 2 will
 * replace this fail-closed resolver with the encrypted connector_credentials
 * implementation. Existing bearer authentication remains independent.
 */
final class UnconfiguredConnectorCredentialResolver implements ConnectorCredentialResolver
{
    public function resolve(string $credentialId): ?ConnectorCredential
    {
        return null;
    }
}
