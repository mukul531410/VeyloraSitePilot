<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCoreState extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'inventory_snapshot_id',
        'wordpress_version',
        'php_version',
        'update_available',
        'update_available_reported',
        'status',
    ];

    protected $casts = [
        'update_available' => 'boolean',
        'update_available_reported' => 'boolean',
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
