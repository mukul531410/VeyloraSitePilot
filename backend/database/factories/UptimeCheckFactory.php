<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\UptimeCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UptimeCheck>
 */
class UptimeCheckFactory extends Factory
{
    public function definition(): array
    {
        $status = $this->faker->randomElement([UptimeCheck::STATUS_UP, UptimeCheck::STATUS_DOWN]);

        return [
            'site_id' => Site::factory(),
            'status' => $status,
            'http_status' => $status === UptimeCheck::STATUS_UP
                ? $this->faker->randomElement([200, 301, 302])
                : $this->faker->randomElement([0, 502, 503, 504]),
            'response_ms' => $this->faker->numberBetween(50, 5000),
            'checked_at' => now(),
        ];
    }
}
