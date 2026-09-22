<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Operation extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_VERIFICATION_PENDING = 'verification_pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const OPEN_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_VERIFICATION_PENDING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_UNKNOWN,
        self::STATUS_CANCELLED,
        self::STATUS_DEAD_LETTER,
    ];

    public const SAFETY_LEVEL_READ_ONLY = 0;
    public const SAFETY_LEVEL_LOW_IMPACT = 1;
    public const SAFETY_LEVEL_MAINTENANCE = 2;
    public const SAFETY_LEVEL_HIGH_IMPACT = 3;
    public const SAFETY_LEVEL_DESTRUCTIVE = 4;

    protected $fillable = [
        'task_id',
        'site_id',
        'operation_type',
        'target_json',
        'status',
        'policy_result',
        'approval_required',
        'idempotency_key',
        'requested_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'target_json' => 'array',
        'approval_required' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(OperationAttempt::class);
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(OperationAttempt::class)->ofMany('attempt_number', 'max');
    }

    public function result(): HasOne
    {
        return $this->hasOne(OperationResult::class);
    }

    public function approvalRequest(): HasOne
    {
        return $this->hasOne(ApprovalRequest::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function getSafetyLevel(): int
    {
        $levels = [
            'action.cache_clear' => self::SAFETY_LEVEL_LOW_IMPACT,
        ];

        return $levels[$this->operation_type] ?? self::SAFETY_LEVEL_MAINTENANCE;
    }

    public function requiresLock(): bool
    {
        return $this->getSafetyLevel() >= self::SAFETY_LEVEL_LOW_IMPACT;
    }
}