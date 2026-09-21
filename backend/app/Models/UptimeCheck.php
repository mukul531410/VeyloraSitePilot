<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UptimeCheck extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_UP = 'up';
    public const STATUS_DOWN = 'down';

    protected $fillable = [
        'site_id',
        'status',
        'http_status',
        'response_ms',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
