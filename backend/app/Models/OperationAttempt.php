<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationAttempt extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_RESULT_RECEIVED = 'result_received';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_REJECTED = 'rejected';

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_TIMEOUT,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'operation_id',
        'attempt_number',
        'status',
        'connector_job_id',
        'started_at',
        'finished_at',
        'error_code',
        'error_details_json',
    ];

    protected $casts = [
        'error_details_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}