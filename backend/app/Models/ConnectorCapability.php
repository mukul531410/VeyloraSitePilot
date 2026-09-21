<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorCapability extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'site_connection_id',
        'capability_key',
        'enabled',
        'discovered_at',
        'updated_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'discovered_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function siteConnection(): BelongsTo
    {
        return $this->belongsTo(SiteConnection::class);
    }
}
