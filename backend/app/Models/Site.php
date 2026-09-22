<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'name',
        'url',
        'environment',
        'status',
        'business_criticality',
        'timezone',
        'notes',
    ];

    protected $casts = [
        'environment' => 'string',
        'status' => 'string',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(SiteConnection::class);
    }

    public function latestConnection(): HasOne
    {
        return $this->hasOne(SiteConnection::class)->ofMany('created_at', 'max');
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function siteMetrics(): HasMany
    {
        return $this->hasMany(SiteMetric::class);
    }

    public function uptimeChecks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    public function latestMetric(string $metricType): ?SiteMetric
    {
        return $this->siteMetrics()
            ->where('metric_type', $metricType)
            ->latest('observed_at')
            ->first();
    }
}
