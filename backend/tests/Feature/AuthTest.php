<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user' => ['id', 'name', 'email', 'status', 'last_login_at', 'organizations'], 'token'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'user' => [
                        'email' => 'test@example.com',
                        'status' => 'active',
                    ],
                ],
            ]);

        $this->assertNotNull($response->json('data.token'));
    }

    public function test_login_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_invalid_credentials_returns_api_error_format(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details'],
                'request_id',
            ])
            ->assertJson([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Invalid credentials.',
                ],
            ]);
    }

    public function test_login_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_endpoint_returns_user_when_authenticated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user' => ['id', 'name', 'email', 'status', 'last_login_at', 'organizations']],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'user' => [
                        'email' => $user->email,
                        'status' => 'active',
                    ],
                ],
            ]);
    }

    public function test_me_endpoint_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_logout_invalidates_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'request_id']);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $response = $this->getJson('/api/v1/organizations');

        $response->assertStatus(401);
    }
}
