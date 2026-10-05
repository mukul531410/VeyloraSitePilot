<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventorySnapshot extends Model
{
    use HasFactory, HasUlids;

    public const SNAPSHOT_TYPE_FULL = 'full';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'site_id',
        'snapshot_type',
        'started_at',
        'completed_at',
        'status',
        'checksum',
        'wordpress_complete',
        'plugins_complete',
        'themes_complete',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'wordpress_complete' => 'boolean',
        'plugins_complete' => 'boolean',
        'themes_complete' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function plugins(): HasMany
    {
        return $this->hasMany(SitePlugin::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(SiteTheme::class);
    }

    public function coreState(): HasOne
    {
        return $this->hasOne(SiteCoreState::class);
    }
}
