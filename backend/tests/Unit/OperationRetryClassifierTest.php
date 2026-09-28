<?php

namespace Tests\Unit;

use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Services\OperationRetryClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationRetryClassifierTest extends TestCase
{
    public function test_dispatch_and_precondition_failures_are_classified_without_retrying_unknown_execution(): void
    {
        $classifier = app(OperationRetryClassifier::class);

        $this->assertSame('safe_automatic_retry', $classifier->classifyDispatchFailure(false));
        $this->assertSame('unknown', $classifier->classifyDispatchFailure(true));
        $this->assertSame('non_retryable', $classifier->classifyPreconditionFailure());
    }

    public static function verificationErrors(): array
    {
        return [
            'cache error is deterministic' => ['cache_error', 'non_retryable'],
            'stale read is uncertain' => ['stale_read', 'unknown'],
            'future read is uncertain' => ['future_read', 'unknown'],
            'generation mismatch is uncertain' => ['cache_generation_mismatch', 'unknown'],
            'malformed state is uncertain' => ['malformed_post_state', 'unknown'],
            'missing read time is uncertain' => ['missing_read_at', 'unknown'],
            'missing cache generation is uncertain' => ['missing_cache_generation', 'unknown'],
            'missing cache state is uncertain' => ['missing_cache_state', 'unknown'],
            'missing cleared types is uncertain' => ['missing_cleared_types', 'unknown'],
            'missing required type is uncertain' => ['missing_required_cache_type', 'unknown'],
            'unexpected type is uncertain' => ['unexpected_cache_type', 'unknown'],
            'unsupported cache type is non-retryable' => ['unsupported_cache_type', 'non_retryable'],
            'operation state change is lifecycle conflict' => ['operation_state_changed', 'lifecycle_conflict'],
        ];
    }

    #[DataProvider('verificationErrors')]
    public function test_classifies_verification_errors_using_contract_literals(string $error, string $expected): void
    {
        $classification = app(OperationRetryClassifier::class)->classifyVerificationError($error);

        $this->assertSame($expected, $classification);
    }

    public function test_unclaimed_attempt_is_safe_but_claimed_attempt_is_unknown(): void
    {
        $classifier = app(OperationRetryClassifier::class);

        $this->assertSame('safe_automatic_retry', $classifier->classifyAttemptFailure(new OperationAttempt(['status' => 'dispatched'])));
        $this->assertSame('unknown', $classifier->classifyAttemptFailure(new OperationAttempt(['status' => 'accepted'])));
        $this->assertSame('unknown', $classifier->classifyAttemptFailure(new OperationAttempt(['status' => 'executing'])));
        $this->assertSame('unknown', $classifier->classifyAttemptFailure(new OperationAttempt(['status' => 'result_received'])));
        $this->assertSame('non_retryable', $classifier->classifyAttemptFailure(new OperationAttempt(['status' => 'failed', 'retryable' => false])));
    }

    public function test_retryable_timeout_requires_explicit_retryable_flag(): void
    {
        $classifier = app(OperationRetryClassifier::class);

        $this->assertSame('safe_automatic_retry', $classifier->classifyAttemptFailure(new OperationAttempt(['status' => 'timeout', 'retryable' => true])));
        $this->assertSame('non_retryable', $classifier->classifyAttemptFailure(new OperationAttempt(['status' => 'timeout', 'retryable' => false])));
    }

    public function test_pending_existing_evidence_allows_only_verification_retry(): void
    {
        $result = new OperationResult([
            'verification_status' => 'pending',
            'actual_state_json' => ['read_at' => '2026-01-01T00:00:00Z'],
        ]);

        $this->assertSame('verification_only_retry', app(OperationRetryClassifier::class)->classifyVerificationProcessingFailure($result));
        $this->assertSame('unknown', app(OperationRetryClassifier::class)->classifyVerificationProcessingFailure(new OperationResult([
            'verification_status' => 'pending',
            'actual_state_json' => null,
        ])));
    }
}
