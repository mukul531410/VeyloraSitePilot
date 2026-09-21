<?php

namespace Tests\Feature;

use App\Jobs\ProcessHealthCheck;
use App\Jobs\ProcessUptimeCheck;
use App\Models\ConnectorHeartbeat;
use App\Models\HealthCheck;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\SiteMetric;
use App\Models\UptimeCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HealthCheckJobTest extends TestCase
{
    use RefreshDatabase;

    private function createSiteWithConnection(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $org->id, 'url' => 'https://example.com']);

        $connection = SiteConnection::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        ConnectorHeartbeat::create([
            'site_connection_id' => $connection->id,
            'connector_version' => '1.0.0',
            'wordpress_version' => '6.5',
            'php_version' => '8.2',
            'status' => 'online',
            'reported_at' => now(),
        ]);

        $connection->update(['last_seen_at' => now()]);

        return [$site, $connection];
    }

    private function addUptimeCheck(Site $site, string $status, ?int $httpStatus = null, ?int $responseMs = null): void
    {
        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => $status,
            'http_status' => $httpStatus,
            'response_ms' => $responseMs ?? 100,
            'checked_at' => now(),
        ]);
    }

    public function test_job_produces_healthy_state_when_all_signals_fresh(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => UptimeCheck::STATUS_UP,
            'http_status' => 200,
            'response_ms' => 120,
            'checked_at' => now(),
        ]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('site_metrics', [
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'unit' => 'healthy',
        ]);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_job_produces_unknown_when_no_active_connection(): void
    {
        $site = Site::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('site_metrics', [
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'unit' => 'unknown',
        ]);
    }

    public function test_job_produces_critical_when_heartbeat_is_stale(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        $connection->update(['last_seen_at' => now()->subMinutes(15)]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('site_metrics', [
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'unit' => 'critical',
        ]);

        $this->assertDatabaseHas('health_checks', [
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_HEARTBEAT,
            'status' => HealthCheck::STATUS_FAIL,
        ]);

        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEARTBEAT_LOSS,
            'status' => Incident::STATUS_DETECTED,
        ]);
    }

    public function test_job_produces_critical_when_uptime_is_down(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => UptimeCheck::STATUS_DOWN,
            'http_status' => 503,
            'checked_at' => now(),
        ]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('site_metrics', [
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'unit' => 'critical',
        ]);

        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_SITE_UNREACHABLE,
            'status' => Incident::STATUS_DETECTED,
            'severity' => Incident::SEVERITY_CRITICAL,
        ]);
    }

    public function test_job_produces_degraded_when_http_response_is_5xx(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => UptimeCheck::STATUS_UP,
            'http_status' => 500,
            'checked_at' => now(),
        ]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('site_metrics', [
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'unit' => 'degraded',
        ]);

        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEALTH_DEGRADED,
            'status' => Incident::STATUS_DETECTED,
        ]);
    }

    public function test_job_produces_attention_when_heartbeat_is_warn(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        $connection->update(['last_seen_at' => now()->subMinutes(6)]);

        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => UptimeCheck::STATUS_UP,
            'http_status' => 200,
            'checked_at' => now(),
        ]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('site_metrics', [
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'unit' => 'attention',
        ]);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_no_duplicate_incidents_for_repeated_failures(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        $connection->update(['last_seen_at' => now()->subMinutes(15)]);

        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => UptimeCheck::STATUS_UP,
            'http_status' => 200,
            'checked_at' => now(),
        ]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseCount('incidents', 1);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_incident_updates_last_detected_on_repeat(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        $connection->update(['last_seen_at' => now()->subMinutes(15)]);

        $this->addUptimeCheck($site, UptimeCheck::STATUS_UP, 200);

        Carbon::setTestNow(now());

        ProcessHealthCheck::dispatch($site->id);

        $incident = Incident::where('site_id', $site->id)->first();
        $this->assertNotNull($incident);

        $firstDetected = $incident->last_detected_at;

        Carbon::setTestNow(now()->addMinute());

        ProcessHealthCheck::dispatch($site->id);

        $incident->refresh();
        $this->assertNotEquals($firstDetected, $incident->last_detected_at);
    }

    public function test_incident_resolves_when_condition_recovers(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        $connection->update(['last_seen_at' => now()->subMinutes(15)]);

        $this->addUptimeCheck($site, UptimeCheck::STATUS_UP, 200);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEARTBEAT_LOSS,
            'status' => Incident::STATUS_DETECTED,
        ]);

        $connection->update(['last_seen_at' => now()]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEARTBEAT_LOSS,
            'status' => Incident::STATUS_RESOLVED,
        ]);
    }

    public function test_incident_reopens_when_condition_returns_after_resolution(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        $connection->update(['last_seen_at' => now()->subMinutes(15)]);
        $this->addUptimeCheck($site, UptimeCheck::STATUS_UP, 200);

        ProcessHealthCheck::dispatch($site->id);
        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEARTBEAT_LOSS,
            'status' => Incident::STATUS_DETECTED,
        ]);

        $connection->update(['last_seen_at' => now()]);
        ProcessHealthCheck::dispatch($site->id);
        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEARTBEAT_LOSS,
            'status' => Incident::STATUS_RESOLVED,
        ]);

        $connection->update(['last_seen_at' => now()->subMinutes(20)]);
        ProcessHealthCheck::dispatch($site->id);
        $this->assertDatabaseHas('incidents', [
            'site_id' => $site->id,
            'type' => Incident::TYPE_HEARTBEAT_LOSS,
            'status' => Incident::STATUS_DETECTED,
        ]);
    }

    public function test_job_records_health_checks_for_each_signal(): void
    {
        [$site, $connection] = $this->createSiteWithConnection();

        UptimeCheck::create([
            'site_id' => $site->id,
            'status' => UptimeCheck::STATUS_UP,
            'http_status' => 200,
            'checked_at' => now(),
        ]);

        ProcessHealthCheck::dispatch($site->id);

        $this->assertDatabaseHas('health_checks', [
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_HEARTBEAT,
        ]);

        $this->assertDatabaseHas('health_checks', [
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_UPTIME,
        ]);

        $this->assertDatabaseHas('health_checks', [
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_HTTP_RESPONSE,
        ]);

        $this->assertDatabaseHas('health_checks', [
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_WORDPRESS_STATE,
        ]);

        $this->assertDatabaseHas('health_checks', [
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_REACHABILITY,
        ]);

        $this->assertDatabaseHas('health_checks', [
            'site_id' => $site->id,
            'check_type' => HealthCheck::CHECK_TYPE_CRITICAL_FINDINGS,
        ]);
    }
}
