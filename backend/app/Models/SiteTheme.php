<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteTheme extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'inventory_snapshot_id',
        'theme_key',
        'name',
        'version',
        'update_available',
        'active',
        'status',
        'metadata_json',
    ];

    protected $casts = [
        'update_available' => 'boolean',
        'active' => 'boolean',
        'metadata_json' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(InventorySnapshot::class, 'inventory_snapshot_id');
    }
}
