<?php

namespace Tests\Feature;

use App\Models\ConnectorCapability;
use App\Models\InventorySnapshot;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\SiteCoreState;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventorySyncTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | Connector write path
     | ------------------------------------------------------------------ */

    public function test_connector_stores_a_full_inventory_snapshot(): void
    {
        [$site, , $token] = $this->makeTenant();

        $response = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.status', InventorySnapshot::STATUS_COMPLETED);

        $snapshot = InventorySnapshot::query()->findOrFail($response->json('data.inventory_snapshot_id'));

        $this->assertSame($site->id, $snapshot->site_id);
        $this->assertSame(InventorySnapshot::SNAPSHOT_TYPE_FULL, $snapshot->snapshot_type);
        $this->assertSame(64, strlen($snapshot->checksum));
        $this->assertNotNull($snapshot->completed_at);

        $core = SiteCoreState::query()->where('inventory_snapshot_id', $snapshot->id)->firstOrFail();
        $this->assertSame('6.5.2', $core->wordpress_version);
        $this->assertSame('8.2', $core->php_version);
        $this->assertTrue($core->update_available);

        $plugins = SitePlugin::query()->where('inventory_snapshot_id', $snapshot->id)->orderBy('plugin_key')->get();
        $this->assertCount(2, $plugins);
        $this->assertSame('akismet/akismet', $plugins->first()->plugin_key);
        $this->assertTrue($plugins->first()->active);
        $this->assertFalse($plugins->first()->update_available);
        $this->assertSame(['author' => 'Automattic'], $plugins->first()->metadata_json);
        $this->assertTrue($plugins->last()->update_available);

        $themes = SiteTheme::query()->where('inventory_snapshot_id', $snapshot->id)->get();
        $this->assertCount(1, $themes);
        $this->assertSame('twentytwentyfour', $themes->first()->theme_key);
        $this->assertTrue($themes->first()->active);
    }

    public function test_explicit_category_completeness_is_persisted(): void
    {
        [, , $token] = $this->makeTenant();
        $payload = $this->payload();
        $payload['category_completeness'] = [
            'wordpress' => true,
            'plugins' => true,
            'themes' => true,
        ];

        $response = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $payload)
            ->assertCreated();

        $snapshot = InventorySnapshot::query()->findOrFail($response->json('data.inventory_snapshot_id'));
        $this->assertTrue($snapshot->wordpress_complete);
        $this->assertTrue($snapshot->plugins_complete);
        $this->assertTrue($snapshot->themes_complete);
    }

    public function test_incomplete_and_omitted_categories_are_not_marked_complete(): void
    {
        [, , $token] = $this->makeTenant();
        $payload = $this->payload();
        $payload['category_completeness'] = ['wordpress' => false, 'plugins' => false];
        unset($payload['themes']);

        $response = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $payload)
            ->assertCreated();

        $snapshot = InventorySnapshot::query()->findOrFail($response->json('data.inventory_snapshot_id'));
        $this->assertFalse($snapshot->wordpress_complete);
        $this->assertFalse($snapshot->plugins_complete);
        $this->assertNull($snapshot->themes_complete);
    }

    public function test_complete_empty_category_requires_capability_and_is_persisted(): void
    {
        [, , $token] = $this->makeTenant(['read.wordpress']);
        $payload = [
            'started_at' => '2026-10-01T10:00:00Z',
            'wordpress' => ['version' => '6.5.2'],
            'plugins' => [],
            'category_completeness' => ['plugins' => true],
        ];

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'capability_denied');

        $this->assertSame(0, InventorySnapshot::query()->count());

        ConnectorCapability::query()->create([
            'site_connection_id' => SiteConnection::query()->firstOrFail()->id,
            'capability_key' => 'read.plugins',
            'enabled' => true,
            'reported_supported' => true,
            'reported_at' => now(),
            'discovered_at' => now(),
        ]);
        $response = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $payload)
            ->assertCreated();

        $snapshot = InventorySnapshot::query()->findOrFail($response->json('data.inventory_snapshot_id'));
        $this->assertTrue($snapshot->plugins_complete);
    }

    public function test_legacy_inventory_payload_keeps_completeness_unknown_and_replay_unchanged(): void
    {
        [, , $token] = $this->makeTenant();
        $payload = $this->payload();

        $first = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $payload)
            ->assertCreated()
            ->json('data.inventory_snapshot_id');

        $snapshot = InventorySnapshot::query()->findOrFail($first);
        $this->assertNull($snapshot->wordpress_complete);
        $this->assertNull($snapshot->plugins_complete);
        $this->assertNull($snapshot->themes_complete);

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.inventory_snapshot_id', $first);

        $this->assertSame(1, InventorySnapshot::query()->count());
    }

    public function test_complete_category_must_be_present_in_payload(): void
    {
        [, , $token] = $this->makeTenant();

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', [
                'started_at' => '2026-10-01T10:00:00Z',
                'wordpress' => ['version' => '6.5.2'],
                'category_completeness' => ['plugins' => true],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_inventory');

        $this->assertSame(0, InventorySnapshot::query()->count());
    }

    public function test_identical_resubmission_replays_the_same_snapshot(): void
    {
        [, , $token] = $this->makeTenant();

        $first = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertCreated()
            ->json('data.inventory_snapshot_id');

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.inventory_snapshot_id', $first);

        $this->assertSame(1, InventorySnapshot::query()->count());
        $this->assertSame(2, SitePlugin::query()->count());
    }

    public function test_changed_inventory_creates_a_new_snapshot_and_preserves_history(): void
    {
        [$site, , $token] = $this->makeTenant();

        $first = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertCreated()
            ->json('data.inventory_snapshot_id');

        $changed = $this->payload();
        $changed['plugins'][0]['version'] = '6.5.3';

        $second = $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $changed)
            ->assertCreated()
            ->json('data.inventory_snapshot_id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, InventorySnapshot::query()->count());
        $this->assertSame(
            '5.8',
            SitePlugin::query()->where('inventory_snapshot_id', $first)->firstOrFail()->version,
        );
        $this->assertSame(
            '6.5.3',
            SitePlugin::query()->where('inventory_snapshot_id', $second)->firstOrFail()->version,
        );
        // Both snapshots are retained: history is never overwritten in place.
        $this->assertSame(4, SitePlugin::query()->where('site_id', $site->id)->count());
    }

    public function test_core_only_sync_is_allowed_without_plugin_and_theme_capabilities(): void
    {
        [, , $token] = $this->makeTenant(['read.wordpress']);

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', [
                'started_at' => '2026-10-01T10:00:00Z',
                'wordpress' => ['version' => '6.5.2', 'php_version' => '8.2'],
            ])
            ->assertCreated();

        $this->assertSame(0, SitePlugin::query()->count());
        $this->assertSame(0, SiteTheme::query()->count());
        $this->assertSame(1, SiteCoreState::query()->count());
    }

    public function test_snapshot_is_attributed_to_the_authenticated_connectors_site(): void
    {
        [$site, , $token] = $this->makeTenant();
        [, $foreignSite] = $this->makeTenant();

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.site_id', $site->id);

        $this->assertSame($site->id, InventorySnapshot::query()->firstOrFail()->site_id);
        $this->assertSame(0, InventorySnapshot::query()->where('site_id', $foreignSite->id)->count());
    }

    /* ---------------------------------------------------------------------
     | Connector authorization
     | ------------------------------------------------------------------ */

    public function test_connector_inventory_requires_a_valid_token(): void
    {
        [, $connection, $token] = $this->makeTenant();

        $this->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');

        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertUnauthorized();

        $connection->update(['revoked_at' => now(), 'status' => 'revoked']);
        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertUnauthorized();

        $this->assertSame(0, InventorySnapshot::query()->count());
    }

    public function test_connector_inventory_requires_the_matching_read_capabilities(): void
    {
        [, , $token] = $this->makeTenant(['read.wordpress']);

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'capability_denied');

        $this->assertSame(0, InventorySnapshot::query()->count());

        // Core plus plugins is still accepted; only the theme section is denied.
        $withoutThemes = $this->payload();
        unset($withoutThemes['themes']);

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $withoutThemes)
            ->assertForbidden();

        $this->assertSame(0, InventorySnapshot::query()->count());
        $this->assertSame(0, SitePlugin::query()->count());
    }

    public function test_disabled_capability_is_not_granted(): void
    {
        [, , $token] = $this->makeTenant();
        ConnectorCapability::query()->where('capability_key', 'read.plugins')->update(['enabled' => false]);

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, InventorySnapshot::query()->count());
    }

    /* ---------------------------------------------------------------------
     | Payload validation
     | ------------------------------------------------------------------ */

    public function test_invalid_payloads_are_rejected(): void
    {
        [, , $token] = $this->makeTenant();

        $missingCore = $this->payload();
        unset($missingCore['wordpress']);
        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $missingCore)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('wordpress');

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('started_at');

        $unnamedPlugin = $this->payload();
        unset($unnamedPlugin['plugins'][0]['name']);
        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $unnamedPlugin)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('plugins.0.name');

        $this->assertSame(0, InventorySnapshot::query()->count());
    }

    public function test_duplicate_component_keys_and_inverted_timestamps_are_rejected(): void
    {
        [, , $token] = $this->makeTenant();

        $duplicate = $this->payload();
        $duplicate['plugins'][1]['key'] = $duplicate['plugins'][0]['key'];
        $duplicate['plugins'][1]['name'] = 'Duplicate';
        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $duplicate)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_inventory');

        $inverted = $this->payload();
        $inverted['completed_at'] = '2026-10-01T09:00:00Z';
        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $inverted)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_inventory');

        $this->assertSame(0, InventorySnapshot::query()->count());
        $this->assertSame(0, SitePlugin::query()->count());
    }

    /* ---------------------------------------------------------------------
     | Dashboard read path
     | ------------------------------------------------------------------ */

    public function test_dashboard_reads_the_latest_snapshot(): void
    {
        [$site, , $token, $viewer] = $this->makeTenant();

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertCreated();

        Sanctum::actingAs($viewer);
        $response = $this->getJson('/api/v1/sites/'.$site->id.'/inventory')
            ->assertOk()
            ->assertJsonPath('data.core.wordpress_version', '6.5.2')
            ->assertJsonPath('data.core.update_available', true)
            ->assertJsonPath('data.summary.plugins', 2)
            ->assertJsonPath('data.summary.active_plugins', 1)
            ->assertJsonPath('data.summary.plugins_with_updates', 1)
            ->assertJsonPath('data.summary.themes', 1)
            ->assertJsonPath('data.summary.core_update_available', true)
            ->assertJsonPath('data.summary.updates_available', 2);

        $this->assertCount(2, $response->json('data.plugins'));
        $this->assertSame(['author' => 'Automattic'], $response->json('data.plugins.0.metadata'));
        $this->assertSame($site->id, $response->json('data.site_id'));
        $this->assertNotNull($response->json('data.snapshot.completed_at'));
    }

    public function test_dashboard_reports_the_newest_completed_snapshot(): void
    {
        [$site, , $token, $viewer] = $this->makeTenant();

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertCreated();

        $newer = $this->payload();
        $newer['wordpress']['version'] = '6.6.0';
        $newer['plugins'][0]['version'] = '6.5.9';
        $newer['started_at'] = '2026-10-02T10:00:00Z';
        $newer['completed_at'] = '2026-10-02T10:00:05Z';

        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $newer)
            ->assertCreated();

        Sanctum::actingAs($viewer);
        $response = $this->getJson('/api/v1/sites/'.$site->id.'/inventory')->assertOk();

        $this->assertSame('6.6.0', $response->json('data.core.wordpress_version'));
        $this->assertSame('6.5.9', $response->json('data.plugins.0.version'));
        $this->assertSame(2, InventorySnapshot::query()->count());
    }

    public function test_dashboard_returns_an_empty_inventory_before_the_first_sync(): void
    {
        [$site, , , $viewer] = $this->makeTenant();

        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/sites/'.$site->id.'/inventory')
            ->assertOk()
            ->assertJsonPath('data.snapshot', null)
            ->assertJsonPath('data.core', null)
            ->assertJsonPath('data.plugins', [])
            ->assertJsonPath('data.themes', [])
            ->assertJsonPath('data.summary.updates_available', 0);
    }

    public function test_dashboard_inventory_is_tenant_isolated(): void
    {
        [$site, , $token] = $this->makeTenant();
        $this->asConnector($token)
            ->postJson('/api/v1/connector/inventory', $this->payload())
            ->assertCreated();

        $outsider = $this->makeOutsider();

        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/sites/'.$site->id.'/inventory')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_dashboard_inventory_requires_authentication(): void
    {
        [$site] = $this->makeTenant();

        $this->getJson('/api/v1/sites/'.$site->id.'/inventory')->assertUnauthorized();
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    private function asConnector(string $token)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    /** @return array{0: Site, 1: SiteConnection, 2: string, 3: User} */
    private function makeTenant(array $capabilities = ['read.wordpress', 'read.plugins', 'read.themes']): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $connection = SiteConnection::factory()->create(['site_id' => $site->id]);
        $token = $connection->activate('1.4.0');

        foreach ($capabilities as $capability) {
            ConnectorCapability::create([
                'site_connection_id' => $connection->id,
                'capability_key' => $capability,
                'enabled' => true,
                'reported_supported' => true,
                'reported_at' => now(),
                'discovered_at' => now(),
            ]);
        }

        return [$site, $connection, $token, $user];
    }

    private function makeOutsider(): User
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => 'owner']);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        Site::factory()->create(['organization_id' => $organization->id]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'started_at' => '2026-10-01T10:00:00Z',
            'completed_at' => '2026-10-01T10:00:04Z',
            'wordpress' => [
                'version' => '6.5.2',
                'php_version' => '8.2',
                'update_available' => true,
                'status' => 'active',
            ],
            'plugins' => [
                [
                    'key' => 'akismet/akismet',
                    'name' => 'Akismet',
                    'version' => '5.8',
                    'active' => true,
                    'update_available' => false,
                    'metadata' => ['author' => 'Automattic'],
                ],
                [
                    'key' => 'wp-super-cache/wp-super-cache',
                    'name' => 'WP Super Cache',
                    'version' => '1.2',
                    'active' => false,
                    'update_available' => true,
                ],
            ],
            'themes' => [
                [
                    'key' => 'twentytwentyfour',
                    'name' => 'Twenty Twenty-Four',
                    'version' => '1.1',
                    'active' => true,
                    'update_available' => false,
                ],
            ],
        ], $overrides);
    }
}
