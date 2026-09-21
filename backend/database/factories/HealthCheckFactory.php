<?php

namespace Database\Factories;

use App\Models\HealthCheck;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheck>
 */
class HealthCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'check_type' => $this->faker->randomElement([
                HealthCheck::CHECK_TYPE_REACHABILITY,
                HealthCheck::CHECK_TYPE_HTTP_RESPONSE,
                HealthCheck::CHECK_TYPE_SSL,
                HealthCheck::CHECK_TYPE_HEARTBEAT,
                HealthCheck::CHECK_TYPE_UPTIME,
                HealthCheck::CHECK_TYPE_WORDPRESS_STATE,
                HealthCheck::CHECK_TYPE_CRITICAL_FINDINGS,
            ]),
            'status' => $this->faker->randomElement([
                HealthCheck::STATUS_PASS,
                HealthCheck::STATUS_WARN,
                HealthCheck::STATUS_FAIL,
            ]),
            'value_json' => [],
            'checked_at' => now(),
            'duration_ms' => $this->faker->numberBetween(10, 5000),
        ];
    }
}
