<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class MaintenanceLock
{
    public const TTL_SECONDS = 300;
    public const KEY_PREFIX = 'lock:maintenance:';

    public function acquire(string $siteId, string $operationId, int $attemptNumber): bool
    {
        $key = self::KEY_PREFIX . $siteId;
        $token = $operationId . ':' . $attemptNumber;

        $acquired = Redis::set($key, $token, 'NX', 'EX', self::TTL_SECONDS);

        return $acquired;
    }

    public function release(string $siteId, string $operationId, int $attemptNumber): bool
    {
        $key = self::KEY_PREFIX . $siteId;
        $token = $operationId . ':' . $attemptNumber;

        $script = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;

        $result = Redis::eval($script, 1, $key, $token);

        return $result === 1;
    }

    public function isLocked(string $siteId): bool
    {
        $key = self::KEY_PREFIX . $siteId;

        return Redis::exists($key);
    }

    public function getLockOwner(string $siteId): ?string
    {
        $key = self::KEY_PREFIX . $siteId;

        $token = Redis::get($key);

        return $token;
    }

    public function extend(string $siteId, string $operationId, int $attemptNumber, int $additionalSeconds = 60): bool
    {
        $key = self::KEY_PREFIX . $siteId;
        $token = $operationId . ':' . $attemptNumber;

        $script = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("EXPIRE", KEYS[1], ARGV[2])
else
    return 0
end
LUA;

        $result = Redis::eval($script, 1, $key, $token, self::TTL_SECONDS + $additionalSeconds);

        return $result === 1;
    }

    public function forceRelease(string $siteId): bool
    {
        $key = self::KEY_PREFIX . $siteId;

        return Redis::del($key) > 0;
    }
}