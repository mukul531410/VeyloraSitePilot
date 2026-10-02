<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class AutomationRule extends Model
{
    use HasFactory, HasUlids;

    public const TRIGGER_SCHEDULE = 'schedule';

    public const ACTION_CACHE_CLEAR = 'action.cache_clear';

    protected $fillable = [
        'organization_id',
        'site_id',
        'name',
        'enabled',
        'trigger_type',
        'schedule_json',
        'conditions_json',
        'action_type',
        'target_json',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'schedule_json' => 'array',
        'conditions_json' => 'array',
        'target_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            if ($rule->trigger_type !== self::TRIGGER_SCHEDULE) {
                throw new InvalidArgumentException('Only scheduled automation rules are supported.');
            }

            if ($rule->action_type !== self::ACTION_CACHE_CLEAR) {
                throw new InvalidArgumentException('Only action.cache_clear is supported.');
            }

            if ($rule->conditions_json !== null) {
                throw new InvalidArgumentException('Automation conditions are not supported yet.');
            }

            if (! is_array($rule->schedule_json) || ! is_array($rule->target_json)) {
                throw new InvalidArgumentException('Automation schedule and target must be JSON objects.');
            }

            if ($rule->site_id && $rule->organization_id) {
                $siteOrganizationId = Site::query()->whereKey($rule->site_id)->value('organization_id');
                if ($siteOrganizationId !== null && $siteOrganizationId !== $rule->organization_id) {
                    throw new InvalidArgumentException('Automation rule site must belong to its organization.');
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
