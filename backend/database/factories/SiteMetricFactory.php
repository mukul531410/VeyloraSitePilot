<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteMetric>
 */
class SiteMetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'metric_type' => $this->faker->randomElement([
                SiteMetric::METRIC_TYPE_HEALTH_STATE,
                SiteMetric::METRIC_TYPE_UPTIME_PERCENT,
                SiteMetric::METRIC_TYPE_RESPONSE_MS,
                SiteMetric::METRIC_TYPE_HEARTBEAT_AGE_MINUTES,
            ]),
            'value' => $this->faker->randomFloat(2, 0, 100),
            'unit' => $this->faker->randomElement(['%', 'ms', '', 'minutes']),
            'observed_at' => now(),
        ];
    }
}
