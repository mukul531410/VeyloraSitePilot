<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => fake()->company() . ' Website',
            'url' => fake()->url(),
            'environment' => fake()->randomElement(['development', 'staging', 'production']),
            'status' => 'active',
            'business_criticality' => fake()->randomElement(['low', 'medium', 'high', null]),
            'timezone' => fake()->timezone(),
            'notes' => fake()->sentence(),
        ];
    }
}
