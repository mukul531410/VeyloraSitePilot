<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class AutomationRun extends Model
{
    use HasFactory, HasUlids;

    private bool $statusTransitionAuthorized = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EVALUATING = 'evaluating';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Terminal status for a stranded run whose automation intent was never
     * submitted. Abandonment is an operator disposition on the run only: it
     * never implies that a remote WordPress operation failed, and it is
     * distinct from cancellation.
     */
    public const STATUS_ABANDONED = 'abandoned';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_EVALUATING,
        self::STATUS_AWAITING_APPROVAL,
        self::STATUS_SUBMITTED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_UNKNOWN,
        self::STATUS_CANCELLED,
        self::STATUS_ABANDONED,
    ];

    protected $fillable = [
        'automation_rule_id',
        'organization_id',
        'site_id',
        'operation_id',
        'occurrence_key',
        'status',
        'evaluation_metadata_json',
        'failure_code',
        'failure_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'evaluation_metadata_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $run): void {
            if (! in_array($run->status, self::STATUSES, true)) {
                throw new InvalidArgumentException('Invalid automation run status.');
            }

            if (! $run->exists && $run->status !== self::STATUS_PENDING) {
                throw new InvalidArgumentException('New automation runs must start pending.');
            }

            if ($run->exists && $run->isDirty('status')
                && (! $run->statusTransitionAuthorized
                    || ! in_array($run->status, self::ALLOWED_TRANSITIONS[$run->getOriginal('status')] ?? [], true))) {
                throw new InvalidArgumentException('Invalid automation run status transition.');
            }

            if ((! $run->exists || $run->isDirty(['automation_rule_id', 'organization_id', 'site_id']))
                && $run->automation_rule_id && $run->organization_id && $run->site_id) {
                $rule = AutomationRule::query()->find($run->automation_rule_id);
                if ($rule && ($rule->organization_id !== $run->organization_id || $rule->site_id !== $run->site_id)) {
                    throw new InvalidArgumentException('Automation run scope must match its rule.');
                }
            }

            if ($run->operation_id && $run->site_id
                && (! $run->exists || $run->isDirty(['operation_id', 'site_id']))) {
                $operationSiteId = Operation::query()->whereKey($run->operation_id)->value('site_id');
                if ($operationSiteId !== null && $operationSiteId !== $run->site_id) {
                    throw new InvalidArgumentException('Automation run operation must belong to its site.');
                }
            }
        });

        static::saved(function (self $run): void {
            $run->statusTransitionAuthorized = false;
        });
    }

    private const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_EVALUATING],
        self::STATUS_EVALUATING => [
            self::STATUS_AWAITING_APPROVAL,
            self::STATUS_SUBMITTED,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_UNKNOWN,
            self::STATUS_CANCELLED,
            self::STATUS_ABANDONED,
        ],
        self::STATUS_AWAITING_APPROVAL => [
            self::STATUS_SUBMITTED,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_UNKNOWN,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_SUBMITTED => [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_UNKNOWN,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_UNKNOWN => [self::STATUS_FAILED, self::STATUS_CANCELLED],
    ];

    public function transitionToEvaluating(): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending automation runs can be claimed.');
        }

        $this->status = self::STATUS_EVALUATING;
        $this->started_at = now('UTC');
        $this->statusTransitionAuthorized = true;
    }

    /**
     * Future reconciliation may use these guarded transitions. No evaluator or
     * reconciliation worker invokes them in this checkpoint.
     */
    public function transitionTo(string $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new InvalidArgumentException("Invalid automation run status transition: {$this->status} -> {$status}.");
        }

        $this->status = $status;
        $this->statusTransitionAuthorized = true;
        if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_UNKNOWN, self::STATUS_CANCELLED, self::STATUS_ABANDONED], true)) {
            $this->finished_at = now('UTC');
        }
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function intent(): HasOne
    {
        return $this->hasOne(AutomationRunIntent::class);
    }

    public function operationOrigin(): HasOne
    {
        return $this->hasOne(AutomationOperationOrigin::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(AutomationRunRecovery::class);
    }

    public function latestRecovery(): HasOne
    {
        return $this->hasOne(AutomationRunRecovery::class)->latestOfMany();
    }

    /**
     * A stranded run is one that is still evaluating with no linked Operation.
     * Recovery eligibility is defined on exactly this condition.
     */
    public function isStranded(): bool
    {
        return $this->status === self::STATUS_EVALUATING && $this->operation_id === null;
    }
}
