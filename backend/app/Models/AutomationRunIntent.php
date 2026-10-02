<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AutomationRunIntent extends Model
{
    use HasUlids;

    protected $fillable = [
        'automation_run_id', 'intent_version', 'captured_at', 'organization_id', 'site_id',
        'automation_rule_id', 'occurrence_key', 'original_operation_type', 'original_target_json',
        'original_requester_id', 'original_idempotency_key', 'policy_context_snapshot',
    ];

    protected $casts = [
        'intent_version' => 'integer',
        'captured_at' => 'datetime',
        'original_target_json' => 'array',
        'policy_context_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Automation run intents are immutable.'));
        static::deleting(fn () => throw new LogicException('Automation run intents cannot be deleted.'));
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }
}
