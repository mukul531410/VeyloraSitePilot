<?php

namespace Tests\Feature;

use App\Models\HealthCheck;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\SiteMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $org->id]);

        return [$user, $org, $site];
    }

    public function test_unauthenticated_user_receives_401_on_health(): void
    {
        $org = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $org->id]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/health');

        $response->assertStatus(401);
    }

    public function test_unauthorized_user_receives_403_on_health(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();

        $otherUser = User::factory()->create();
        $otherOrg = Organization::factory()->create();
        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role_id' => Role::factory()->create(['organization_id' => $otherOrg->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);
        $otherSite = Site::factory()->create(['organization_id' => $otherOrg->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites/' . $otherSite->id . '/health');

        $response->assertStatus(403);
    }

    public function test_health_endpoint_returns_unknown_when_no_data(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['site_id', 'health_state', 'last_checked_at', 'open_incidents', 'checks'],
                'meta',
                'request_id',
            ])
            ->assertJsonPath('data.health_state', 'unknown');
    }

    public function test_health_endpoint_returns_state_and_open_incidents(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        SiteMetric::create([
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'value' => null,
            'unit' => 'critical',
            'observed_at' => now(),
        ]);

        HealthCheck::create([
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_HEARTBEAT,
            'status' => HealthCheck::STATUS_FAIL,
            'value_json' => ['heartbeat_age_minutes' => 15],
            'checked_at' => now(),
        ]);

        Incident::create([
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEARTBEAT_LOSS,
            'severity' => Incident::SEVERITY_CRITICAL,
            'status' => Incident::STATUS_DETECTED,
            'title' => 'Connector heartbeat lost',
            'description' => 'Heartbeat is stale.',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'resolved_at' => null,
        ]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/health');

        $response->assertStatus(200)
            ->assertJsonPath('data.health_state', 'critical')
            ->assertJsonCount(1, 'data.open_incidents')
            ->assertJsonPath('data.open_incidents.0.type', Incident::TYPE_HEARTBEAT_LOSS);
    }

    public function test_metrics_endpoint_returns_metrics_grouped_by_type(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        SiteMetric::create([
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'value' => null,
            'unit' => 'healthy',
            'observed_at' => now()->subHour(),
        ]);

        SiteMetric::create([
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_UPTIME_PERCENT,
            'value' => 99.95,
            'unit' => '%',
            'observed_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta',
                'request_id',
            ]);
    }

    public function test_incidents_endpoint_returns_paginated_incidents(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        Incident::factory()->count(3)->create(['site_id' => $site->id]);

        $otherSite = Site::factory()->create(['organization_id' => $org->id]);
        Incident::factory()->create(['site_id' => $otherSite->id]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/incidents');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_incidents_endpoint_filters_by_status(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        Incident::factory()->create([
            'site_id' => $site->id,
            'status' => Incident::STATUS_DETECTED,
        ]);

        Incident::factory()->create([
            'site_id' => $site->id,
            'status' => Incident::STATUS_RESOLVED,
        ]);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/incidents?status=detected');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'detected');
    }

    public function test_incidents_endpoint_max_per_page_is_100(): void
    {
        [$user, $org, $site] = $this->createUserWithSite();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites/' . $site->id . '/incidents?per_page=200');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }
}
