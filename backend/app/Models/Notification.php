<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasUlids;

    public const TYPE_INCIDENT_DETECTED = 'incident_detected';

    public const TYPE_INCIDENT_RESOLVED = 'incident_resolved';

    public const TYPE_AVAILABLE_UPDATE_DETECTED = 'available_update_detected';

    public const TYPE_OPERATION_FAILED = 'operation_failed';

    public const TYPE_OPERATION_VERIFICATION_FAILED = 'operation_verification_failed';

    public const TYPE_OPERATION_UNKNOWN = 'operation_unknown';

    public const TYPE_APPROVAL_REQUESTED = 'approval_requested';

    public const TYPE_AUTOMATION_FAILED = 'automation_failed';

    public const TYPES = [
        self::TYPE_INCIDENT_DETECTED,
        self::TYPE_INCIDENT_RESOLVED,
        self::TYPE_AVAILABLE_UPDATE_DETECTED,
        self::TYPE_OPERATION_FAILED,
        self::TYPE_OPERATION_VERIFICATION_FAILED,
        self::TYPE_OPERATION_UNKNOWN,
        self::TYPE_APPROVAL_REQUESTED,
        self::TYPE_AUTOMATION_FAILED,
    ];

    protected $fillable = [
        'organization_id', 'user_id', 'site_id', 'source_type', 'source_id',
        'type', 'severity', 'title', 'body', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
