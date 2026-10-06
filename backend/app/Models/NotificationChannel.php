<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationChannel extends Model
{
    use HasUlids;

    public const TYPE_IN_APP = 'in_app';

    protected $fillable = ['organization_id', 'type', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class, 'channel_id');
    }
}
