<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailableUpdate extends Model
{
    use HasFactory, HasUlids;

    public const TYPE_CORE = 'core';

    public const TYPE_PLUGIN = 'plugin';

    public const TYPE_THEME = 'theme';

    public const SEVERITY_INFO = 'info';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'site_id',
        'type',
        'item_identifier',
        'severity',
        'status',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
