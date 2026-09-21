<?php

namespace Database\Factories;

use App\Models\SiteConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteConnection>
 */
class SiteConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => \App\Models\Site::factory(),
            'status' => 'pending',
            'connector_version' => null,
            'credential_ciphertext' => null,
            'credential_version' => 1,
            'connector_token_hash' => null,
            'connection_intent' => null,
            'intent_expires_at' => null,
            'connected_at' => null,
            'last_seen_at' => null,
            'revoked_at' => null,
        ];
    }
}
