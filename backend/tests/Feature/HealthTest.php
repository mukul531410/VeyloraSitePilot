<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['status', 'service'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'status' => 'ok',
                    'service' => 'veylora-sitepilot-api',
                ],
            ]);
    }
}
