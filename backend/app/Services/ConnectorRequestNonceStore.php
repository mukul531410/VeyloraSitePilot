<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ConnectorRequestNonceStore
{
    public function reserve(string $scopeId, string $nonce, int $ttlSeconds = 600): bool
    {
        $nonceHash = hash('sha256', $nonce);
        $now = now();

        return DB::transaction(function () use ($scopeId, $nonceHash, $ttlSeconds, $now): bool {
            DB::table('connector_request_nonces')
                ->where('site_connection_id', $scopeId)
                ->where('nonce_hash', $nonceHash)
                ->where('expires_at', '<=', $now)
                ->delete();

            return DB::table('connector_request_nonces')->insertOrIgnore([
                'site_connection_id' => $scopeId,
                'nonce_hash' => $nonceHash,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
                'created_at' => $now,
            ]) === 1;
        });
    }
}
