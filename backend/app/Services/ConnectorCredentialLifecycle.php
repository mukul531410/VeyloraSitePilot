<?php

namespace App\Services;

use App\Exceptions\ConnectorCredentialException;
use App\Models\AuditLog;
use App\Models\ConnectorCredential;
use App\Models\SiteConnection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConnectorCredentialLifecycle
{
    public const OVERLAP_SECONDS = 86400;

    /** @return array{credential: ConnectorCredential, secret: string} */
    public function issueInitial(SiteConnection $connection): array
    {
        return DB::transaction(function () use ($connection): array {
            $connection = SiteConnection::query()->lockForUpdate()->findOrFail($connection->getKey());
            if (! $connection->isActive()) {
                throw new ConnectorCredentialException(ConnectorCredentialException::UNAUTHORIZED);
            }
            if ($connection->connectorCredentials()->exists()) {
                throw new ConnectorCredentialException(ConnectorCredentialException::UNAUTHORIZED);
            }

            $secret = random_bytes(32);
            $credential = $connection->connectorCredentials()->create([
                'secret_ciphertext' => Crypt::encryptString($secret),
                'version' => $connection->credential_version,
                'status' => ConnectorCredential::STATUS_PRIMARY,
                'issued_at' => now()->startOfSecond(),
            ]);
            $this->audit($connection, $credential, 'connector_credential_issued', null, null);

            return ['credential' => $credential, 'secret' => base64_encode($secret)];
        });
    }

    /** @return array{credential: ConnectorCredential, secret: string} */
    public function rotate(string $credentialId, int $expectedVersion, ?User $actor = null, ?string $correlationId = null): array
    {
        return DB::transaction(function () use ($credentialId, $expectedVersion, $actor, $correlationId): array {
            $old = ConnectorCredential::query()->find($credentialId);
            if ($old === null) {
                throw new ConnectorCredentialException(ConnectorCredentialException::UNAUTHORIZED);
            }

            $connection = SiteConnection::query()->lockForUpdate()->find($old->site_connection_id);
            if ($connection === null || ! $connection->isActive()) {
                throw new ConnectorCredentialException(ConnectorCredentialException::UNAUTHORIZED);
            }
            $old = ConnectorCredential::query()->lockForUpdate()->find($credentialId);
            if ($old === null || $old->revoked_at !== null || $old->status === ConnectorCredential::STATUS_REVOKED
                || ($old->status === ConnectorCredential::STATUS_OVERLAP
                    && ($old->overlap_expires_at === null || $old->overlap_expires_at->lessThanOrEqualTo(now())))) {
                throw new ConnectorCredentialException(ConnectorCredentialException::UNAUTHORIZED);
            }
            if ($connection->credential_version !== $expectedVersion) {
                throw new ConnectorCredentialException(ConnectorCredentialException::VERSION_CONFLICT);
            }
            if ($old->site_connection_id !== $connection->id
                || $old->status !== ConnectorCredential::STATUS_PRIMARY
                || $old->version !== $expectedVersion) {
                throw new ConnectorCredentialException(ConnectorCredentialException::UNAUTHORIZED);
            }

            $now = now()->startOfSecond();
            $overlapEnd = $now->copy()->addSeconds(self::OVERLAP_SECONDS);
            $old->update([
                'status' => ConnectorCredential::STATUS_OVERLAP,
                'overlap_expires_at' => $overlapEnd,
            ]);

            $secret = random_bytes(32);
            $version = $connection->credential_version + 1;
            $new = $connection->connectorCredentials()->create([
                'secret_ciphertext' => Crypt::encryptString($secret),
                'version' => $version,
                'status' => ConnectorCredential::STATUS_PRIMARY,
                'issued_at' => $now,
            ]);
            $connection->update(['credential_version' => $version]);
            $this->audit($connection, $new, 'connector_credential_rotated', $actor, $correlationId, $old, $overlapEnd);

            return ['credential' => $new, 'secret' => base64_encode($secret)];
        });
    }

    public function revoke(string $credentialId, ?User $actor = null, ?string $correlationId = null): ConnectorCredential
    {
        return DB::transaction(function () use ($credentialId, $actor, $correlationId): ConnectorCredential {
            $candidate = ConnectorCredential::query()->find($credentialId);
            if ($candidate === null) {
                throw new ConnectorCredentialException(ConnectorCredentialException::UNAUTHORIZED);
            }
            $connection = SiteConnection::query()->lockForUpdate()->findOrFail($candidate->site_connection_id);
            $credential = ConnectorCredential::query()->lockForUpdate()->findOrFail($credentialId);
            if ($credential->revoked_at !== null || $credential->status === ConnectorCredential::STATUS_REVOKED) {
                return $credential;
            }

            $now = now();
            $credential->update(['status' => ConnectorCredential::STATUS_REVOKED, 'revoked_at' => $now]);
            $this->audit($connection, $credential, 'connector_credential_revoked', $actor, $correlationId);

            return $credential->refresh();
        });
    }

    public function revokeConnection(SiteConnection $source): void
    {
        DB::transaction(function () use ($source): void {
            $connection = SiteConnection::query()->lockForUpdate()->findOrFail($source->getKey());
            $now = now();
            $connection->update([
                'status' => 'revoked',
                'revoked_at' => $now,
                'credential_ciphertext' => null,
                'credential_version' => $connection->credential_version + 1,
            ]);

            $credentials = $connection->connectorCredentials()->whereNull('revoked_at')->lockForUpdate()->get();
            foreach ($credentials as $credential) {
                $credential->update(['status' => ConnectorCredential::STATUS_REVOKED, 'revoked_at' => $now]);
                $this->audit($connection, $credential, 'connector_credential_revoked', null, null);
            }
        });
    }

    private function audit(
        SiteConnection $connection,
        ConnectorCredential $credential,
        string $action,
        ?User $actor,
        ?string $correlationId,
        ?ConnectorCredential $previous = null,
        ?Carbon $overlapEnd = null,
    ): void {
        $site = $connection->site;
        AuditLog::create([
            'organization_id' => $site->organization_id,
            'user_id' => $actor?->id,
            'site_id' => $site->id,
            'action' => $action,
            'target_type' => 'connector_credential',
            'target_id' => $credential->id,
            'correlation_id' => $this->correlationId($correlationId),
            'metadata_json' => array_filter([
                'connection_id' => $connection->id,
                'credential_id' => $credential->id,
                'previous_credential_id' => $previous?->id,
                'credential_version' => $credential->version,
                'overlap_expires_at' => $overlapEnd?->toIso8601String(),
                'actor_id' => $actor?->id,
                'actor_type' => $actor === null ? 'system' : 'user',
                'timestamp' => now('UTC')->toIso8601String(),
            ], fn ($value): bool => $value !== null),
        ]);
    }

    private function correlationId(?string $correlationId): string
    {
        return is_string($correlationId) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $correlationId)
            ? strtoupper($correlationId)
            : (string) Str::ulid();
    }
}
