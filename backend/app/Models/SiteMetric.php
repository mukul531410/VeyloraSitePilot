<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMetric extends Model
{
    use HasFactory, HasUlids;

    public const METRIC_TYPE_HEALTH_STATE = 'health_state';
    public const METRIC_TYPE_UPTIME_PERCENT = 'uptime_percent';
    public const METRIC_TYPE_RESPONSE_MS = 'response_ms';
    public const METRIC_TYPE_HEARTBEAT_AGE_MINUTES = 'heartbeat_age_minutes';

    protected $fillable = [
        'site_id',
        'metric_type',
        'value',
        'unit',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
