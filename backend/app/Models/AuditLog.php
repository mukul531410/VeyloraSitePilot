<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'site_id',
        'action',
        'target_type',
        'target_id',
        'correlation_id',
        'policy_result',
        'before_json',
        'after_json',
        'metadata_json',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'metadata_json' => 'array',
    ];

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