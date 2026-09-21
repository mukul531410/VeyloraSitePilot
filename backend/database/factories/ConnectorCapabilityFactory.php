<?php

namespace Database\Factories;

use App\Models\ConnectorCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorCapability>
 */
class ConnectorCapabilityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_connection_id' => \App\Models\SiteConnection::factory(),
            'capability_key' => fake()->randomElement([
                'read.site', 'read.wordpress', 'read.plugins', 'read.themes',
                'read.health', 'action.plugin_update', 'action.theme_update',
                'action.core_update', 'action.cache_clear', 'action.backup',
                'action.maintenance_mode',
            ]),
            'enabled' => true,
            'discovered_at' => now(),
        ];
    }
}
