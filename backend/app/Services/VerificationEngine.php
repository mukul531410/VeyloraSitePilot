<?php

namespace App\Services;

use App\Models\OperationResult;
use Carbon\CarbonImmutable;
use Throwable;

class VerificationEngine
{
    public const WORDPRESS_CLEARED_TYPES = [
        'object_cache', 'page_cache', 'transient_cache', 'rewrite_cache', 'file_cache', 'opcache',
    ];

    /** @return array{verified: bool, error: ?string} */
    public function verify(array $state, string $expectedCacheType, ?string $expectedGeneration, CarbonImmutable $startedAt): array
    {
        if ($expectedCacheType !== OperationResult::CACHE_TYPE_WORDPRESS) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_UNSUPPORTED_CACHE_TYPE);
        }
        if (! isset($state['read_at']) || ! is_string($state['read_at'])) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MISSING_READ_AT);
        }
        try {
            $readAt = CarbonImmutable::parse($state['read_at'])->utc();
        } catch (Throwable) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MALFORMED_POST_STATE);
        }
        $startedAt = $startedAt->utc();
        if ($readAt->lt($startedAt)) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_STALE_READ);
        }
        if ($readAt->gt($startedAt->addSeconds(60))) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_FUTURE_READ);
        }
        if (! isset($state['cache_generation']) || ! is_string($state['cache_generation']) || $state['cache_generation'] === '') {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MISSING_CACHE_GENERATION);
        }
        if (! isset($state['cleared_types']) || ! is_array($state['cleared_types'])) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MISSING_CLEARED_TYPES);
        }
        $types = $state['cleared_types'];
        foreach (self::WORDPRESS_CLEARED_TYPES as $type) {
            if (! in_array($type, $types, true)) {
                return $this->failed(OperationResult::VERIFICATION_ERROR_MISSING_REQUIRED_CACHE_TYPE);
            }
        }
        foreach ($types as $type) {
            if (! is_string($type) || ! in_array($type, self::WORDPRESS_CLEARED_TYPES, true)) {
                return $this->failed(OperationResult::VERIFICATION_ERROR_UNEXPECTED_CACHE_TYPE);
            }
        }
        if (count($types) !== count(self::WORDPRESS_CLEARED_TYPES) || count(array_unique($types)) !== count($types)) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_UNEXPECTED_CACHE_TYPE);
        }
        if ($expectedGeneration !== null && $state['cache_generation'] !== $expectedGeneration) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_CACHE_GENERATION_MISMATCH);
        }
        if (! array_key_exists('cache_state', $state)) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MISSING_CACHE_STATE);
        }
        if (! is_array($state['cache_state']) || array_is_list($state['cache_state'])) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MALFORMED_POST_STATE);
        }
        $evidence = $state['cache_state'];
        $expectedLayers = self::WORDPRESS_CLEARED_TYPES;
        $actualLayers = array_keys($evidence);
        $missingLayers = array_diff($expectedLayers, $actualLayers);
        if ($missingLayers !== []) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MISSING_CACHE_STATE);
        }
        $unexpectedLayers = array_diff($actualLayers, $expectedLayers);
        if ($unexpectedLayers !== []) {
            return $this->failed(OperationResult::VERIFICATION_ERROR_MALFORMED_POST_STATE);
        }
        foreach ($expectedLayers as $layer) {
            if (! is_string($evidence[$layer])) {
                return $this->failed(OperationResult::VERIFICATION_ERROR_MALFORMED_POST_STATE);
            }
            if ($evidence[$layer] !== 'cleared') {
                return $this->failed(OperationResult::VERIFICATION_ERROR_CACHE_ERROR);
            }
        }
        return ['verified' => true, 'error' => null];
    }

    private function failed(string $error): array
    {
        return ['verified' => false, 'error' => $error];
    }

}
