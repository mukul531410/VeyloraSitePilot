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

    protected $fillable = [
        'operation_id',
        'result_status',
        'expected_state_json',
        'actual_state_json',
        'verification_status',
        'result_summary',
    ];

    protected $casts = [
        'expected_state_json' => 'array',
        'actual_state_json' => 'array',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_PASSED;
    }
}