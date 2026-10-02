<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class AutomationRunRecovery extends Model
{
    use HasUlids;

    public const ACTION_LINK = 'link';

    public const ACTION_RE_EVALUATE = 're_evaluate';

    public const ACTION_ABANDON = 'abandon';

    public const ACTIONS = [
        self::ACTION_LINK,
        self::ACTION_RE_EVALUATE,
        self::ACTION_ABANDON,
    ];

    public const STATE_NONE = 'none';

    public const STATE_REQUESTED = 'requested';

    public const STATE_AUTHORIZED = 'authorized';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_LINKED = 'linked';

    public const STATE_SUBMITTED = 'submitted';

    public const STATE_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATE_BLOCKED = 'blocked';

    public const STATE_CONFLICT = 'conflict';

    public const STATE_ABANDONED = 'abandoned';

    public const STATES = [
        self::STATE_REQUESTED,
        self::STATE_AUTHORIZED,
        self::STATE_IN_PROGRESS,
        self::STATE_LINKED,
        self::STATE_SUBMITTED,
        self::STATE_AWAITING_APPROVAL,
        self::STATE_BLOCKED,
        self::STATE_CONFLICT,
        self::STATE_ABANDONED,
    ];

    /**
     * States that claim the run. A blocked or conflicting attempt released the
     * claim so a later legitimate attempt remains possible.
     */
    public const OCCUPYING_STATES = [
        self::STATE_REQUESTED,
        self::STATE_AUTHORIZED,
        self::STATE_IN_PROGRESS,
        self::STATE_LINKED,
        self::STATE_SUBMITTED,
        self::STATE_AWAITING_APPROVAL,
        self::STATE_ABANDONED,
    ];

    protected $fillable = [
        'automation_run_id',
        'organization_id',
        'site_id',
        'actor_id',
        'action',
        'request_idempotency_key',
        'reason',
        'state',
        'active_automation_run_id',
        'failure_reason',
        'result_metadata_json',
        'requested_at',
        'authorized_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'result_metadata_json' => 'array',
        'requested_at' => 'datetime',
        'authorized_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $recovery): void {
            if (! in_array($recovery->action, self::ACTIONS, true)) {
                throw new InvalidArgumentException('Invalid automation run recovery action.');
            }

            if (! in_array($recovery->state, self::STATES, true)) {
                throw new InvalidArgumentException('Invalid automation run recovery state.');
            }

            $occupying = in_array($recovery->state, self::OCCUPYING_STATES, true);
            $expected = $occupying ? $recovery->automation_run_id : null;

            if ($occupying && $recovery->active_automation_run_id === null) {
                $recovery->active_automation_run_id = $recovery->automation_run_id;
            }

            if ((string) $recovery->active_automation_run_id !== (string) $expected) {
                throw new InvalidArgumentException('Recovery claim must match the run for occupying states only.');
            }
        });
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [
            self::STATE_LINKED,
            self::STATE_SUBMITTED,
            self::STATE_AWAITING_APPROVAL,
            self::STATE_ABANDONED,
        ], true);
    }

    public function isOccupying(): bool
    {
        return in_array($this->state, self::OCCUPYING_STATES, true);
    }
}
