<?php

namespace App\Services;

use App\Contracts\ConnectorCredentialResolver;
use App\Data\ConnectorCredential as ResolvedCredential;
use App\Models\ConnectorCredential;
use Illuminate\Support\Facades\Crypt;

final class DatabaseConnectorCredentialResolver implements ConnectorCredentialResolver
{
    public function resolve(string $credentialId): ?ResolvedCredential
    {
        $credential = ConnectorCredential::query()->with('connection')->find($credentialId);
        if ($credential === null || ! $this->isValid($credential)) {
            return null;
        }

        try {
            $secret = Crypt::decryptString($credential->secret_ciphertext);
        } catch (\Throwable) {
            return null;
        }

        return new ResolvedCredential(
            credentialId: $credential->id,
            nonceScopeId: $credential->id,
            secret: $secret,
            siteConnectionId: $credential->site_connection_id,
            status: $credential->status,
            version: $credential->version,
            overlapExpiresAt: $credential->overlap_expires_at,
        );
    }

    private function isValid(ConnectorCredential $credential): bool
    {
        $connection = $credential->connection;
        if ($connection === null || ! $connection->isActive()) {
            return false;
        }

        if ($credential->status === ConnectorCredential::STATUS_REVOKED || $credential->revoked_at !== null) {
            return false;
        }

        if (($credential->status === ConnectorCredential::STATUS_PRIMARY
                && $credential->version !== $connection->credential_version)
            || ($credential->status === ConnectorCredential::STATUS_OVERLAP
                && $credential->version >= $connection->credential_version)) {
            return false;
        }

        if ($credential->status === ConnectorCredential::STATUS_OVERLAP
            && ($credential->overlap_expires_at === null || $credential->overlap_expires_at->lessThanOrEqualTo(now()))) {
            return false;
        }

        return $credential->status === ConnectorCredential::STATUS_PRIMARY
            || ($credential->status === ConnectorCredential::STATUS_OVERLAP
                && $credential->overlap_expires_at->greaterThan(now()))
            ? true
            : false;
    }
}
