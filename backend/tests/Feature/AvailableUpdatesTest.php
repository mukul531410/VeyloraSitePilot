<?php

namespace Tests\Feature;

use App\Models\AvailableUpdate;
use App\Models\ConnectorCapability;
use App\Models\InventorySnapshot;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\AvailableUpdateFindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AvailableUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_plugin_and_theme_updates_create_distinct_info_findings(): void
    {
        $tenant = $this->makeTenant();
        $payload = $this->payload();
        $payload['wordpress']['update_available'] = true;
        $payload['plugins'] = [$this->plugin('wordpress', true)];
        $payload['themes'] = [$this->theme('wordpress', true)];

        $this->submit($tenant, $payload);

        $findings = AvailableUpdate::query()->where('site_id', $tenant['site']->id)->orderBy('type')->get();
        $this->assertCount(3, $findings);
        $this->assertSame(['core', 'plugin', 'theme'], $findings->pluck('type')->all());
        $this->assertSame(['wordpress', 'wordpress', 'wordpress'], $findings->pluck('item_identifier')->all());
        $this->assertSame(['info'], $findings->pluck('severity')->unique()->values()->all());
        $this->assertSame(['open'], $findings->pluck('status')->unique()->values()->all());
    }

    public function test_open_finding_refreshes_preserving_first_seen_and_explicit_false_resolves(): void
    {
        $tenant = $this->makeTenant();
        $this->submit($tenant, $this->payload('2026-10-05T10:00:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', true)],
        ]));

        $finding = $this->pluginFinding($tenant['site'], 'example/plugin.php');
        $firstSeen = $finding->first_seen_at->toIso8601String();

        $this->submit($tenant, $this->payload('2026-10-05T10:05:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', true)],
        ]));
        $finding->refresh();
        $this->assertSame($firstSeen, $finding->first_seen_at->toIso8601String());
        $this->assertSame('2026-10-05T10:05:00+00:00', $finding->last_seen_at->toIso8601String());
        $this->assertSame(AvailableUpdate::STATUS_OPEN, $finding->status);

        $this->submit($tenant, $this->payload('2026-10-05T10:10:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', false)],
        ]));
        $finding->refresh();
        $this->assertSame(AvailableUpdate::STATUS_RESOLVED, $finding->status);
        $this->assertSame('2026-10-05T10:10:00+00:00', $finding->resolved_at->toIso8601String());
        $this->assertSame('2026-10-05T10:05:00+00:00', $finding->last_seen_at->toIso8601String());
    }

    public function test_resolved_finding_reopens_when_update_returns(): void
    {
        $tenant = $this->makeTenant();
        $this->submit($tenant, $this->payload('2026-10-05T10:00:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', true)],
        ]));
        $this->submit($tenant, $this->payload('2026-10-05T10:05:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', false)],
        ]));
        $finding = $this->pluginFinding($tenant['site'], 'example/plugin.php');
        $firstSeen = $finding->first_seen_at->toIso8601String();

        $this->submit($tenant, $this->payload('2026-10-05T10:10:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', true)],
        ]));

        $finding->refresh();
        $this->assertSame(AvailableUpdate::STATUS_OPEN, $finding->status);
        $this->assertNull($finding->resolved_at);
        $this->assertSame($firstSeen, $finding->first_seen_at->toIso8601String());
        $this->assertSame('2026-10-05T10:10:00+00:00', $finding->last_seen_at->toIso8601String());
    }

    public function test_complete_plugin_category_resolves_absent_plugin_findings(): void
    {
        $tenant = $this->makeTenant();
        $this->submit($tenant, $this->payload('2026-10-05T10:00:00Z', [
            'plugins' => [$this->plugin('missing/plugin.php', true)],
        ]));
        $this->submit($tenant, $this->payload('2026-10-05T10:05:00Z', [
            'plugins' => [],
            'category_completeness' => ['plugins' => true],
        ]));

        $this->assertSame(AvailableUpdate::STATUS_RESOLVED, $this->pluginFinding($tenant['site'], 'missing/plugin.php')->status);
    }

    public function test_item_without_update_flag_does_not_resolve_an_existing_finding(): void
    {
        $tenant = $this->makeTenant();
        $this->submit($tenant, $this->payload('2026-10-05T10:00:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', true)],
        ]));

        $unreported = $this->plugin('example/plugin.php', false);
        unset($unreported['update_available']);
        $this->submit($tenant, $this->payload('2026-10-05T10:05:00Z', [
            'plugins' => [$unreported],
        ]));

        $finding = $this->pluginFinding($tenant['site'], 'example/plugin.php');
        $this->assertSame(AvailableUpdate::STATUS_OPEN, $finding->status);
        $this->assertNull($finding->resolved_at);
    }

    public function test_incomplete_and_unknown_plugin_categories_do_not_resolve_absent_findings(): void
    {
        foreach ([false, null] as $completeness) {
            $tenant = $this->makeTenant();
            $this->submit($tenant, $this->payload('2026-10-05T10:00:00Z', [
                'plugins' => [$this->plugin('missing/plugin.php', true)],
            ]));

            $next = $this->payload('2026-10-05T10:05:00Z', ['plugins' => []]);
            if ($completeness === null) {
                unset($next['category_completeness']);
            } else {
                $next['category_completeness']['plugins'] = false;
            }
            $this->submit($tenant, $next);

            $this->assertSame(AvailableUpdate::STATUS_OPEN, $this->pluginFinding($tenant['site'], 'missing/plugin.php')->status);
        }
    }

    public function test_replayed_inventory_does_not_change_findings_or_timestamps(): void
    {
        $tenant = $this->makeTenant();
        $payload = $this->payload('2026-10-05T10:00:00Z', [
            'plugins' => [$this->plugin('example/plugin.php', true)],
        ]);
        $this->submit($tenant, $payload)->assertCreated();
        $finding = $this->pluginFinding($tenant['site'], 'example/plugin.php');
        $createdAt = $finding->created_at->toIso8601String();
        $updatedAt = $finding->updated_at->toIso8601String();
        $lastSeen = $finding->last_seen_at->toIso8601String();

        $this->travel(5)->minutes();
        $this->submit($tenant, $payload)->assertOk()->assertJsonPath('data.created', false);

        $finding->refresh();
        $this->assertSame($createdAt, $finding->created_at->toIso8601String());
        $this->assertSame($updatedAt, $finding->updated_at->toIso8601String());
        $this->assertSame($lastSeen, $finding->last_seen_at->toIso8601String());
        $this->assertSame(1, AvailableUpdate::query()->where('site_id', $tenant['site']->id)->count());
    }

    public function test_finding_identity_is_scoped_by_site_and_component_type(): void
    {
        $firstTenant = $this->makeTenant();
        $secondTenant = $this->makeTenant();
        foreach ([$firstTenant, $secondTenant] as $tenant) {
            $this->submit($tenant, $this->payload('2026-10-05T10:00:00Z', [
                'plugins' => [$this->plugin('same/key', true)],
                'themes' => [$this->theme('same/key', true)],
            ]));
        }

        $this->assertSame(2, AvailableUpdate::query()->where('type', AvailableUpdate::TYPE_PLUGIN)->where('item_identifier', 'same/key')->count());
        $this->assertSame(2, AvailableUpdate::query()->where('type', AvailableUpdate::TYPE_THEME)->where('item_identifier', 'same/key')->count());
        $this->assertSame(4, AvailableUpdate::query()->count());
    }

    public function test_available_updates_endpoint_requires_authentication_and_active_tenant_access(): void
    {
        $tenant = $this->makeTenant();
        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates')->assertUnauthorized();

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'unauthorized');

        $tenant['member']->update(['status' => 'inactive']);
        Sanctum::actingAs($tenant['user']);
        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates')->assertForbidden();

        $tenant['member']->update(['status' => 'active']);
        $tenant['site']->update(['status' => 'inactive']);
        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates')->assertForbidden();

        $tenant['site']->update(['status' => 'active']);
        $tenant['organization']->update(['status' => 'inactive']);
        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates')->assertForbidden();
    }

    public function test_available_updates_paginates_filters_and_orders_deterministically(): void
    {
        $tenant = $this->makeTenant();
        $baseTime = now()->startOfMinute();
        for ($index = 0; $index < 30; $index++) {
            AvailableUpdate::query()->create([
                'site_id' => $tenant['site']->id,
                'type' => $index % 2 === 0 ? AvailableUpdate::TYPE_PLUGIN : AvailableUpdate::TYPE_THEME,
                'item_identifier' => 'component-'.$index,
                'severity' => AvailableUpdate::SEVERITY_INFO,
                'status' => $index === 0 ? AvailableUpdate::STATUS_RESOLVED : AvailableUpdate::STATUS_OPEN,
                'first_seen_at' => $baseTime->copy()->subMinutes(30 - $index),
                'last_seen_at' => $baseTime->copy()->subMinutes($index % 3),
                'resolved_at' => $index === 0 ? $baseTime : null,
            ]);
        }

        Sanctum::actingAs($tenant['user']);
        $expectedOrder = AvailableUpdate::query()
            ->where('site_id', $tenant['site']->id)
            ->where('status', AvailableUpdate::STATUS_OPEN)
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->limit(2)
            ->pluck('item_identifier');
        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 29)
            ->assertJsonPath('data.0.item_identifier', $expectedOrder[0])
            ->assertJsonPath('data.1.item_identifier', $expectedOrder[1]);

        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates?status=resolved')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', AvailableUpdate::STATUS_RESOLVED);

        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates?type=theme&severity=info&per_page=200')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('data.0.type', AvailableUpdate::TYPE_THEME);

        $this->getJson('/api/v1/sites/'.$tenant['site']->id.'/available-updates?type=invalid')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_finding_generation_rolls_back_with_snapshot_when_processing_fails(): void
    {
        $tenant = $this->makeTenant();
        $processor = Mockery::mock(AvailableUpdateFindingService::class);
        $processor->shouldReceive('process')->once()->andReturnUsing(function (InventorySnapshot $snapshot): void {
            AvailableUpdate::query()->create([
                'site_id' => $snapshot->site_id,
                'type' => AvailableUpdate::TYPE_CORE,
                'item_identifier' => 'wordpress',
                'severity' => AvailableUpdate::SEVERITY_INFO,
                'status' => AvailableUpdate::STATUS_OPEN,
                'first_seen_at' => $snapshot->completed_at,
                'last_seen_at' => $snapshot->completed_at,
            ]);
            throw new RuntimeException('Simulated derived-finding failure.');
        });
        $this->app->instance(AvailableUpdateFindingService::class, $processor);

        $this->asConnector($tenant['token'])
            ->postJson('/api/v1/connector/inventory', $this->payload('2026-10-05T10:00:00Z', [
                'wordpress' => ['version' => '6.5.2', 'update_available' => true],
            ]))
            ->assertServerError();

        $this->assertSame(0, InventorySnapshot::query()->count());
        $this->assertSame(0, AvailableUpdate::query()->count());
    }

    private function makeTenant(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => 'owner']);
        $member = OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id]);
        $token = $connection->activate('1.4.0');

        foreach (['read.wordpress', 'read.plugins', 'read.themes'] as $capability) {
            ConnectorCapability::query()->create([
                'site_connection_id' => $connection->id,
                'capability_key' => $capability,
                'enabled' => true,
                'discovered_at' => now(),
            ]);
        }

        return compact('user', 'organization', 'member', 'site', 'connection', 'token');
    }

    private function asConnector(string $token)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    private function submit(array $tenant, array $payload)
    {
        return $this->asConnector($tenant['token'])->postJson('/api/v1/connector/inventory', $payload);
    }

    private function payload(string $completedAt = '2026-10-05T10:00:00Z', array $overrides = []): array
    {
        $completed = new \DateTimeImmutable($completedAt);

        return array_replace_recursive([
            'started_at' => $completed->modify('-4 seconds')->format(DATE_ATOM),
            'completed_at' => $completed->format(DATE_ATOM),
            'wordpress' => ['version' => '6.5.2', 'update_available' => false],
            'plugins' => [],
            'themes' => [],
            'category_completeness' => ['wordpress' => false, 'plugins' => false, 'themes' => false],
        ], $overrides);
    }

    private function plugin(string $key, bool $updateAvailable): array
    {
        return ['key' => $key, 'name' => $key, 'version' => '1.0.0', 'update_available' => $updateAvailable];
    }

    private function theme(string $key, bool $updateAvailable): array
    {
        return ['key' => $key, 'name' => $key, 'version' => '1.0.0', 'update_available' => $updateAvailable];
    }

    private function pluginFinding(Site $site, string $identifier): AvailableUpdate
    {
        return AvailableUpdate::query()
            ->where('site_id', $site->id)
            ->where('type', AvailableUpdate::TYPE_PLUGIN)
            ->where('item_identifier', $identifier)
            ->firstOrFail();
    }
}
