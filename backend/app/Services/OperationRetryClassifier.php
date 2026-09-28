<?php

namespace App\Services;

use App\Models\OperationAttempt;
use App\Models\OperationResult;

class OperationRetryClassifier
{
    public const SAFE_AUTOMATIC_RETRY = 'safe_automatic_retry';
    public const VERIFICATION_ONLY_RETRY = 'verification_only_retry';
    public const NON_RETRYABLE = 'non_retryable';
    public const UNKNOWN = 'unknown';
    public const LIFECYCLE_CONFLICT = 'lifecycle_conflict';

    /** A dispatch failure before a connector job is exposed cannot have executed the mutation. */
    public function classifyDispatchFailure(bool $connectorJobAvailable): string
    {
        return $connectorJobAvailable ? self::UNKNOWN : self::SAFE_AUTOMATIC_RETRY;
    }

    /** Authorization, policy, capability, validation, and support failures are not retryable. */
    public function classifyPreconditionFailure(): string
    {
        return self::NON_RETRYABLE;
    }

    /** Call only after timeout/failure is detected, while the attempt state is locked. */
    public function classifyAttemptFailure(OperationAttempt $attempt): string
    {
        if ($attempt->status === OperationAttempt::STATUS_DISPATCHED) {
            // The connector protocol requires a successful claim before execution.
            return self::SAFE_AUTOMATIC_RETRY;
        }

        if (in_array($attempt->status, [OperationAttempt::STATUS_FAILED, OperationAttempt::STATUS_TIMEOUT], true)
            && $attempt->retryable === true) {
            return self::SAFE_AUTOMATIC_RETRY;
        }

        if (in_array($attempt->status, [
            OperationAttempt::STATUS_ACCEPTED,
            OperationAttempt::STATUS_EXECUTING,
            OperationAttempt::STATUS_RESULT_RECEIVED,
        ], true)) {
            return self::UNKNOWN;
        }

        return self::NON_RETRYABLE;
    }

    /** Existing pending evidence may be reprocessed without repeating the mutation. */
    public function classifyVerificationProcessingFailure(OperationResult $result): string
    {
        if ($result->verification_status === OperationResult::VERIFICATION_PENDING
            && is_array($result->actual_state_json)
            && $result->actual_state_json !== []) {
            return self::VERIFICATION_ONLY_RETRY;
        }

        return self::UNKNOWN;
    }

    /** Classify existing verification error codes; unknown outcomes never replay a mutation. */
    public function classifyVerificationError(?string $error): string
    {
        return match ($error) {
            OperationResult::VERIFICATION_ERROR_CACHE_ERROR => self::NON_RETRYABLE,
            OperationResult::VERIFICATION_ERROR_UNSUPPORTED_CACHE_TYPE => self::NON_RETRYABLE,
            OperationResult::VERIFICATION_ERROR_OPERATION_STATE_CHANGED => self::LIFECYCLE_CONFLICT,
            OperationResult::VERIFICATION_ERROR_MISSING_READ_AT,
            OperationResult::VERIFICATION_ERROR_STALE_READ,
            OperationResult::VERIFICATION_ERROR_FUTURE_READ,
            OperationResult::VERIFICATION_ERROR_MISSING_CLEARED_TYPES,
            OperationResult::VERIFICATION_ERROR_MISSING_REQUIRED_CACHE_TYPE,
            OperationResult::VERIFICATION_ERROR_UNEXPECTED_CACHE_TYPE,
            OperationResult::VERIFICATION_ERROR_CACHE_GENERATION_MISMATCH,
            OperationResult::VERIFICATION_ERROR_MALFORMED_POST_STATE,
            OperationResult::VERIFICATION_ERROR_MISSING_CACHE_TYPE,
            OperationResult::VERIFICATION_ERROR_MISSING_CACHE_GENERATION,
            OperationResult::VERIFICATION_ERROR_MISSING_CACHE_STATE => self::UNKNOWN,
            default => self::UNKNOWN,
        };
    }
}
