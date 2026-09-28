<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationResult extends Model
{
    use HasFactory, HasUlids;

    public const VERIFICATION_PASSED = 'verified';
    public const VERIFICATION_FAILED = 'failed';
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_PENDING_HEARTBEAT = 'pending_heartbeat';
    public const VERIFICATION_SKIPPED = 'skipped';

    public const CACHE_TYPE_WORDPRESS = 'wordpress';

    public const VERIFICATION_ERROR_UNSUPPORTED_CACHE_TYPE = 'unsupported_cache_type';
    public const VERIFICATION_ERROR_MISSING_READ_AT = 'missing_read_at';
    public const VERIFICATION_ERROR_STALE_READ = 'stale_read';
    public const VERIFICATION_ERROR_FUTURE_READ = 'future_read';
    public const VERIFICATION_ERROR_MISSING_CLEARED_TYPES = 'missing_cleared_types';
    public const VERIFICATION_ERROR_MISSING_REQUIRED_CACHE_TYPE = 'missing_required_cache_type';
    public const VERIFICATION_ERROR_UNEXPECTED_CACHE_TYPE = 'unexpected_cache_type';
    public const VERIFICATION_ERROR_CACHE_GENERATION_MISMATCH = 'cache_generation_mismatch';
    public const VERIFICATION_ERROR_CACHE_ERROR = 'cache_error';
    public const VERIFICATION_ERROR_MALFORMED_POST_STATE = 'malformed_post_state';
    public const VERIFICATION_ERROR_MISSING_CACHE_TYPE = 'missing_cache_type';
    public const VERIFICATION_ERROR_MISSING_CACHE_GENERATION = 'missing_cache_generation';
    public const VERIFICATION_ERROR_MISSING_CACHE_STATE = 'missing_cache_state';
    public const VERIFICATION_ERROR_OPERATION_STATE_CHANGED = 'operation_state_changed';

    protected $fillable = [
        'operation_id',
        'operation_attempt_id',
        'connector_job_id',
        'result_status',
        'cache_cleared_at',
        'cleared_types',
        'cache_generation',
        'error_code',
        'error_message',
        'expected_state_json',
        'actual_state_json',
        'verification_status',
        'verification_error',
        'result_summary',
        'verified_at',
    ];

    protected $casts = [
        'cache_cleared_at' => 'datetime',
        'cleared_types' => 'array',
        'expected_state_json' => 'array',
        'actual_state_json' => 'array',
        'verified_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(OperationAttempt::class, 'operation_attempt_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_PASSED;
    }
}
