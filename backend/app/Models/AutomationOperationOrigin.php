<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AutomationOperationOrigin extends Model
{
    use HasUlids;

    protected $fillable = [
        'automation_run_id', 'operation_id', 'site_id', 'organization_id', 'linked_at', 'version',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $origin): void {
            $run = AutomationRun::query()->with('site.organization')->findOrFail($origin->automation_run_id);
            $operation = Operation::query()->with('site.organization')->findOrFail($origin->operation_id);
            if ((string) $run->site_id !== (string) $origin->site_id
                || (string) $operation->site_id !== (string) $origin->site_id
                || (string) $run->organization_id !== (string) $origin->organization_id
                || (string) $run->site->organization_id !== (string) $origin->organization_id
                || (string) $operation->site->organization_id !== (string) $origin->organization_id) {
                throw new LogicException('Automation operation origin scope does not match its run and operation.');
            }
        });
        static::updating(fn () => throw new LogicException('Automation operation origins are immutable.'));
        static::deleting(fn () => throw new LogicException('Automation operation origins cannot be deleted.'));
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
