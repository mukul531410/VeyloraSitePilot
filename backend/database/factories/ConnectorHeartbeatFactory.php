<?php

namespace Database\Factories;

use App\Models\ConnectorHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorHeartbeat>
 */
class ConnectorHeartbeatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_connection_id' => \App\Models\SiteConnection::factory(),
            'connector_version' => '1.0.0',
            'wordpress_version' => '6.5',
            'php_version' => '8.2',
            'status' => 'online',
            'reported_at' => now(),
        ];
    }
}
