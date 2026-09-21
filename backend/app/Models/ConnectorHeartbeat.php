<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorHeartbeat extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_connection_id',
        'connector_version',
        'wordpress_version',
        'php_version',
        'reported_at',
        'status',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
    ];

    public function siteConnection(): BelongsTo
    {
        return $this->belongsTo(SiteConnection::class);
    }
}
