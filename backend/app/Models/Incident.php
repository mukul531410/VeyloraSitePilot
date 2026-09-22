<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    use HasFactory, HasUlids;

    public const TYPE_HEARTBEAT_LOSS = 'heartbeat_loss';
    public const TYPE_SITE_UNREACHABLE = 'site_unreachable';
    public const TYPE_SSL_EXPIRING = 'ssl_expiring';
    public const TYPE_CRITICAL_FINDINGS = 'critical_findings';
    public const TYPE_HEALTH_DEGRADED = 'health_degraded';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    public const STATUS_DETECTED = 'detected';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED = 'resolved';

    public const OPEN_STATUSES = [
        self::STATUS_DETECTED,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_INVESTIGATING,
    ];

    protected $fillable = [
        'site_id',
        'incident_key',
        'type',
        'severity',
        'status',
        'title',
        'description',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
    ];

    protected $casts = [
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES);
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }
}
