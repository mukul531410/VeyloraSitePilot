<?php

namespace Tests\Unit;

use App\Models\OperationResult;
use App\Services\VerificationEngine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerificationEngineTest extends TestCase
{
    public static function invalidStates(): array
    {
        return [
            'missing read time' => [function (&$s) { unset($s['read_at']); }, OperationResult::VERIFICATION_ERROR_MISSING_READ_AT],
            'stale read' => [function (&$s) { $s['read_at'] = '2025-12-31T23:59:59Z'; }, OperationResult::VERIFICATION_ERROR_STALE_READ],
            'future read' => [function (&$s) { $s['read_at'] = '2026-01-01T00:02:00Z'; }, OperationResult::VERIFICATION_ERROR_FUTURE_READ],
            'missing cleared types' => [function (&$s) { unset($s['cleared_types']); }, OperationResult::VERIFICATION_ERROR_MISSING_CLEARED_TYPES],
            'missing a required type' => [function (&$s) { array_pop($s['cleared_types']); }, OperationResult::VERIFICATION_ERROR_MISSING_REQUIRED_CACHE_TYPE],
            'unexpected type' => [fn (&$s) => $s['cleared_types'][] = 'cdn_cache', OperationResult::VERIFICATION_ERROR_UNEXPECTED_CACHE_TYPE],
            'generation mismatch' => [function (&$s) { $s['cache_generation'] = 'different'; }, OperationResult::VERIFICATION_ERROR_CACHE_GENERATION_MISMATCH],
            'missing cache state' => [function (&$s) { unset($s['cache_state']); }, OperationResult::VERIFICATION_ERROR_MISSING_CACHE_STATE],
            'missing cache layer evidence' => [function (&$s) { unset($s['cache_state']['opcache']); }, OperationResult::VERIFICATION_ERROR_MISSING_CACHE_STATE],
            'unexpected cache layer evidence' => [function (&$s) { $s['cache_state']['cdn_cache'] = 'cleared'; }, OperationResult::VERIFICATION_ERROR_MALFORMED_POST_STATE],
            'generic cache marker' => [function (&$s) { $s['cache_state'] = ['cache' => 'clear']; }, OperationResult::VERIFICATION_ERROR_MISSING_CACHE_STATE],
            'malformed cache state' => [function (&$s) { $s['cache_state']['page_cache'] = ['state' => 'cleared']; }, OperationResult::VERIFICATION_ERROR_MALFORMED_POST_STATE],
            'cache error' => [function (&$s) { $s['cache_state']['page_cache'] = 'error'; }, OperationResult::VERIFICATION_ERROR_CACHE_ERROR],
        ];
    }

    #[DataProvider('invalidStates')]
    public function test_rejects_invalid_authoritative_state(callable $change, string $error): void
    {
        $state = $this->validState();
        $change($state);
        $result = app(VerificationEngine::class)->verify($state, 'wordpress', 'g1', CarbonImmutable::parse('2026-01-01T00:00:00Z'));
        $this->assertFalse($result['verified']);
        $this->assertSame($error, $result['error']);
    }

    public function test_exact_approved_taxonomy_verifies(): void
    {
        $expected = [
            'object_cache',
            'page_cache',
            'transient_cache',
            'rewrite_cache',
            'file_cache',
            'opcache',
        ];
        $this->assertSame($expected, VerificationEngine::WORDPRESS_CLEARED_TYPES);
        $result = app(VerificationEngine::class)->verify($this->validState(), 'wordpress', 'g1', CarbonImmutable::parse('2026-01-01T00:00:00Z'));
        $this->assertSame(['verified' => true, 'error' => null], $result);
    }

    private function validState(): array
    {
        return [
            'read_at' => '2026-01-01T00:00:30Z',
            'cache_generation' => 'g1',
            'cleared_types' => [
                'object_cache',
                'page_cache',
                'transient_cache',
                'rewrite_cache',
                'file_cache',
                'opcache',
            ],
            'cache_state' => [
                'object_cache' => 'cleared',
                'page_cache' => 'cleared',
                'transient_cache' => 'cleared',
                'rewrite_cache' => 'cleared',
                'file_cache' => 'cleared',
                'opcache' => 'cleared',
            ],
        ];
    }
}
