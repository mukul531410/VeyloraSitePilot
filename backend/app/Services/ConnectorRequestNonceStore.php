<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ConnectorRequestNonceStore
{
    public function reserve(string $credentialId, string $nonce, int $ttlSeconds = 600): bool
    {
        $nonceHash = hash('sha256', $nonce);
        $now = now();

        return DB::transaction(function () use ($credentialId, $nonceHash, $ttlSeconds, $now): bool {
            DB::table('connector_request_nonces')
                ->where('credential_id', $credentialId)
                ->where('nonce_hash', $nonceHash)
                ->where('expires_at', '<=', $now)
                ->delete();

            return DB::table('connector_request_nonces')->insertOrIgnore([
                'credential_id' => $credentialId,
                'nonce_hash' => $nonceHash,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
                'created_at' => $now,
            ]) === 1;
        });
    }
}
