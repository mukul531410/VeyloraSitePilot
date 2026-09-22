<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthCheck extends Model
{
    use HasFactory, HasUlids;

    public const CHECK_TYPE_REACHABILITY = 'reachability';
    public const CHECK_TYPE_HTTP_RESPONSE = 'http_response';
    public const CHECK_TYPE_SSL = 'ssl';
    public const CHECK_TYPE_HEARTBEAT = 'heartbeat';
    public const CHECK_TYPE_UPTIME = 'uptime';
    public const CHECK_TYPE_WORDPRESS_STATE = 'wordpress_state';
    public const CHECK_TYPE_CRITICAL_FINDINGS = 'critical_findings';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';
    public const STATUS_UNKNOWN = 'unknown';

    public const STATE_HEALTHY = 'healthy';
    public const STATE_ATTENTION = 'attention';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_CRITICAL = 'critical';
    public const STATE_UNKNOWN = 'unknown';

    public const THRESHOLD_WARNING_MINUTES = 5;
    public const THRESHOLD_CRITICAL_MINUTES = 10;

    public const SSL_WARNING_DAYS = 30;
    public const SSL_CRITICAL_DAYS = 7;

    public const WP_WARNING_DAYS = 60;

    protected $fillable = [
        'site_id',
        'check_type',
        'status',
        'value_json',
        'checked_at',
        'duration_ms',
    ];

    protected $casts = [
        'value_json' => 'array',
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
