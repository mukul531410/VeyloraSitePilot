<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => $this->faker->randomElement([
                Incident::TYPE_HEARTBEAT_LOSS,
                Incident::TYPE_SITE_UNREACHABLE,
                Incident::TYPE_SSL_EXPIRING,
                Incident::TYPE_CRITICAL_FINDINGS,
                Incident::TYPE_HEALTH_DEGRADED,
            ]),
            'severity' => $this->faker->randomElement([
                Incident::SEVERITY_CRITICAL,
                Incident::SEVERITY_HIGH,
                Incident::SEVERITY_MEDIUM,
                Incident::SEVERITY_LOW,
            ]),
            'status' => $this->faker->randomElement([
                Incident::STATUS_DETECTED,
                Incident::STATUS_ACKNOWLEDGED,
                Incident::STATUS_INVESTIGATING,
                Incident::STATUS_RESOLVED,
            ]),
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'first_detected_at' => now()->subHours($this->faker->numberBetween(1, 24)),
            'last_detected_at' => now()->subHours($this->faker->numberBetween(1, 12)),
            'resolved_at' => $this->faker->boolean(20) ? now()->subHour() : null,
        ];
    }
}
