<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\ConnectorCapability;
use App\Models\Operation;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\AutomationRunService;
use App\Services\AutomationSchedulerService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomationRuleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /* ---------------------------------------------------------------------
     | Creation
     | ------------------------------------------------------------------ */

    public function test_owner_and_admin_can_create_a_rule(): void
    {
        foreach (['owner', 'admin'] as $roleKey) {
            [$actor, $organization, $site] = $this->makeTenant($roleKey);

            Sanctum::actingAs($actor);
            $response = $this->store([
                'organization_id' => $organization->id,
                'site_id' => $site->id,
                'name' => 'Nightly cache clear',
                'schedule_json' => ['every_minutes' => 30, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
            ])->assertCreated()->assertJsonPath('data.name', 'Nightly cache clear');

            $ruleId = $response->json('data.id');

            $this->assertSame($organization->id, $response->json('data.organization_id'));
            $this->assertSame($site->id, $response->json('data.site_id'));
            $this->assertSame($actor->id, $response->json('data.created_by'));
            $this->assertFalse($response->json('data.enabled'));
            $this->assertSame(AutomationRule::TRIGGER_SCHEDULE, $response->json('data.trigger_type'));
            $this->assertSame(AutomationRule::ACTION_CACHE_CLEAR, $response->json('data.action_type'));
            $this->assertNull($response->json('data.conditions_json'));
            $this->assertSame(['cache_type' => 'wordpress'], $response->json('data.target_json'));
            $this->assertSame(0, $response->json('data.runs_count'));
            $this->assertTrue($response->json('data.deletable'));

            $rule = AutomationRule::query()->findOrFail($ruleId);
            $this->assertTrue($rule->enabled === false);
            $this->assertTrue($rule->schedule_json['every_minutes'] === 30);
            $this->assertNull($rule->conditions_json);
        }
    }

    public function test_create_audits_the_transition(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        Sanctum::actingAs($actor);

        $ruleId = $this->store([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Audited rule',
            'enabled' => true,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
        ])->assertCreated()->json('data.id');

        $audit = AuditLog::query()
            ->where('action', 'automation_rule_created')
            ->where('target_type', 'automation_rule')
            ->where('target_id', $ruleId)
            ->firstOrFail();

        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($site->id, $audit->site_id);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame(26, strlen($audit->correlation_id));
        $this->assertTrue($audit->after_json['enabled']);
        $this->assertFalse($audit->remote_execution_claimed ?? false);
    }

    public function test_trigger_action_and_conditions_are_server_controlled(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        Sanctum::actingAs($actor);

        $this->store([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Escalation attempt',
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
            'trigger_type' => 'incident_event',
            'action_type' => 'action.delete_site',
            'conditions_json' => ['severity' => 'critical'],
        ])->assertCreated()
            ->assertJsonPath('data.trigger_type', AutomationRule::TRIGGER_SCHEDULE)
            ->assertJsonPath('data.action_type', AutomationRule::ACTION_CACHE_CLEAR)
            ->assertJsonPath('data.conditions_json', null);
    }

    public function test_create_defaults_cache_type_and_rejects_other_cache_types(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        Sanctum::actingAs($actor);

        $this->store([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Default target',
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
            'target_json' => [],
        ])->assertCreated()->assertJsonPath('data.target_json.cache_type', 'wordpress');

        $this->store([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Foreign target',
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
            'target_json' => ['cache_type' => 'redis'],
        ])->assertStatus(422)->assertJsonValidationErrorFor('target_json.cache_type');
    }

    public function test_create_rejects_an_invalid_schedule(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        Sanctum::actingAs($actor);

        $schedules = [
            'zero_interval' => ['every_minutes' => 0, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
            'malformed_start' => ['every_minutes' => 5, 'starts_at_utc' => 'not-a-timestamp'],
            'unexpected_key' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z', 'timezone' => 'UTC'],
            'missing_interval' => ['starts_at_utc' => '2026-10-01T00:00:00Z'],
            'missing_start' => ['every_minutes' => 5],
        ];

        foreach ($schedules as $case => $schedule) {
            $this->store([
                'organization_id' => $organization->id,
                'site_id' => $site->id,
                'name' => 'Bad schedule '.$case,
                'schedule_json' => $schedule,
            ])->assertStatus(422);
        }

        $this->assertSame(0, AutomationRule::query()->count());
    }

    public function test_create_rejects_a_site_outside_the_organization(): void
    {
        [$actor, $organization] = $this->makeTenant('owner');
        $foreignOrganization = Organization::factory()->create();
        $foreignSite = Site::factory()->create(['organization_id' => $foreignOrganization->id]);
        Sanctum::actingAs($actor);

        $this->store([
            'organization_id' => $organization->id,
            'site_id' => $foreignSite->id,
            'name' => 'Cross tenant',
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.reason', 'site_not_in_organization');
    }

    /* ---------------------------------------------------------------------
     | Authorization
     | ------------------------------------------------------------------ */

    public function test_viewer_and_non_member_cannot_mutate_rules(): void
    {
        [$viewer, $organization, $site] = $this->makeTenant('viewer');
        $payload = [
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Denied',
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
        ];

        Sanctum::actingAs($viewer);
        $this->store($payload)->assertForbidden()->assertJsonPath('error.code', 'unauthorized');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->store($payload)->assertForbidden();

        $this->assertSame(0, AutomationRule::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'automation_rule_created')->count());
    }

    public function test_inactive_actor_membership_organization_and_site_are_denied(): void
    {
        [$actor, $organization, $site, $membership] = $this->makeTenant('owner');
        $actor->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->store($this->payload($organization, $site))->assertForbidden();

        [$actor, $organization, $site, $membership] = $this->makeTenant('owner');
        $membership->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->store($this->payload($organization, $site))->assertForbidden();

        [$actor, $organization, $site] = $this->makeTenant('owner');
        $organization->update(['status' => 'suspended']);
        Sanctum::actingAs($actor);
        $this->store($this->payload($organization, $site))->assertForbidden();

        [$actor, $organization, $site] = $this->makeTenant('owner');
        $site->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->store($this->payload($organization, $site))->assertForbidden();
    }

    public function test_rule_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/automation/rules')->assertUnauthorized();
        $this->postJson('/api/v1/automation/rules', [])->assertUnauthorized();
    }

    /* ---------------------------------------------------------------------
     | Listing and reading
     | ------------------------------------------------------------------ */

    public function test_index_lists_only_visible_rules_and_paginates(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('viewer');
        $this->persistRule($organization, $site, $actor, 'Visible A');
        $this->persistRule($organization, $site, $actor, 'Visible B');

        [, $otherOrganization, $otherSite] = $this->makeForeignTenant();
        $this->persistRule($otherOrganization, $otherSite, $actor, 'Foreign');

        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/automation/rules')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertEqualsCanonicalizing(['Visible A', 'Visible B'], $names);
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.current_page'));

        $filtered = $this->getJson('/api/v1/automation/rules?per_page=1')->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame(1, $filtered->json('meta.per_page'));
        $this->assertSame(2, $filtered->json('meta.last_page'));

        $bySite = $this->getJson('/api/v1/automation/rules?site_id='.$otherSite->id)->assertOk();
        $this->assertSame([], $bySite->json('data'));
    }

    public function test_index_and_show_hide_inactive_and_cross_tenant_rules(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('viewer');
        $rule = $this->persistRule($organization, $site, $actor, 'Hidden later');
        [, $otherOrganization, $otherSite] = $this->makeForeignTenant();
        $foreignRule = $this->persistRule($otherOrganization, $otherSite, $actor, 'Foreign');

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/automation/rules/'.$rule->id)->assertOk();
        $this->getJson('/api/v1/automation/rules/'.$foreignRule->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $organization->update(['status' => 'suspended']);
        $this->getJson('/api/v1/automation/rules/'.$rule->id)->assertNotFound();
        $this->getJson('/api/v1/automation/rules')->assertOk()->assertJsonPath('meta.total', 0);

        [$actor, $organization, $site] = $this->makeTenant('viewer');
        $rule = $this->persistRule($organization, $site, $actor, 'Hidden site');
        $site->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/automation/rules/'.$rule->id)->assertNotFound();
    }

    public function test_show_reports_run_count_and_deletability(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $rule = $this->persistRule($organization, $site, $actor, 'With run');

        $run = app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new DateTimeImmutable('2026-10-01T00:05:00Z'),
        );
        $this->assertNotNull($run->id);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/automation/rules/'.$rule->id)
            ->assertOk()
            ->assertJsonPath('data.runs_count', 1)
            ->assertJsonPath('data.deletable', false);
    }

    /* ---------------------------------------------------------------------
     | Updating
     | ------------------------------------------------------------------ */

    public function test_update_changes_name_enabled_and_schedule_only(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $rule = $this->persistRule($organization, $site, $actor, 'Original');
        Sanctum::actingAs($actor);

        $response = $this->patchJson('/api/v1/automation/rules/'.$rule->id, [
            'name' => 'Renamed',
            'enabled' => true,
            'schedule_json' => ['every_minutes' => 15, 'starts_at_utc' => '2026-10-02T00:00:00Z'],
            'site_id' => Site::factory()->create(['organization_id' => $organization->id])->id,
            'action_type' => 'action.delete_site',
        ])->assertOk();

        $this->assertSame('Renamed', $response->json('data.name'));
        $this->assertTrue($response->json('data.enabled'));
        $this->assertSame(15, $response->json('data.schedule_json.every_minutes'));
        $this->assertSame($site->id, $response->json('data.site_id'));
        $this->assertSame(AutomationRule::ACTION_CACHE_CLEAR, $response->json('data.action_type'));

        $fresh = $rule->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertTrue($fresh->enabled);
        $this->assertSame('2026-10-02T00:00:00Z', $fresh->schedule_json['starts_at_utc']);
    }

    public function test_update_audits_before_and_after_state(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $rule = $this->persistRule($organization, $site, $actor, 'Before name', false);
        Sanctum::actingAs($actor);

        $this->patchJson('/api/v1/automation/rules/'.$rule->id, ['enabled' => true])->assertOk();

        $audit = AuditLog::query()
            ->where('action', 'automation_rule_updated')
            ->where('target_id', $rule->id)
            ->firstOrFail();

        $this->assertFalse($audit->before_json['enabled']);
        $this->assertTrue($audit->after_json['enabled']);
        $this->assertSame('Before name', $audit->before_json['name']);
        $this->assertSame(26, strlen($audit->correlation_id));
    }

    public function test_update_requires_at_least_one_mutable_field(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $rule = $this->persistRule($organization, $site, $actor, 'Unchanged');
        Sanctum::actingAs($actor);

        $this->patchJson('/api/v1/automation/rules/'.$rule->id, [])->assertStatus(422);
        $this->patchJson('/api/v1/automation/rules/'.$rule->id, ['site_id' => $site->id])->assertStatus(422);
        $this->assertSame('Unchanged', $rule->fresh()->name);
    }

    public function test_update_rejects_an_invalid_schedule(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $rule = $this->persistRule($organization, $site, $actor, 'Schedule guard');
        Sanctum::actingAs($actor);

        $this->patchJson('/api/v1/automation/rules/'.$rule->id, [
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z', 'extra' => 1],
        ])->assertStatus(422);

        $this->patchJson('/api/v1/automation/rules/'.$rule->id, [
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00+00:00'],
        ])->assertStatus(422)->assertJsonPath('error.details.reason', 'invalid_schedule');

        $this->assertSame(5, $rule->fresh()->schedule_json['every_minutes']);
    }

    public function test_update_is_denied_for_viewers_and_hidden_across_tenants(): void
    {
        [$viewer, $organization, $site] = $this->makeTenant('viewer');
        $rule = $this->persistRule($organization, $site, $viewer, 'Viewer target', false);
        Sanctum::actingAs($viewer);

        $this->patchJson('/api/v1/automation/rules/'.$rule->id, ['enabled' => true])
            ->assertForbidden();
        $this->assertFalse($rule->fresh()->enabled);

        [$owner, $organization, $site] = $this->makeTenant('owner');
        $foreignRule = $this->persistRule($organization, $site, $owner, 'Foreign target');
        [$outsider] = $this->makeForeignTenant();
        Sanctum::actingAs($outsider);

        $this->patchJson('/api/v1/automation/rules/'.$foreignRule->id, ['enabled' => true])
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Deleting
     | ------------------------------------------------------------------ */

    public function test_delete_removes_a_rule_without_runs_and_audits_it(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $rule = $this->persistRule($organization, $site, $actor, 'Disposable');
        Sanctum::actingAs($actor);

        $this->deleteJson('/api/v1/automation/rules/'.$rule->id)
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertNull(AutomationRule::query()->find($rule->id));

        $audit = AuditLog::query()
            ->where('action', 'automation_rule_deleted')
            ->where('target_id', $rule->id)
            ->firstOrFail();

        $this->assertSame('Disposable', $audit->before_json['name']);
        $this->assertNull($audit->after_json);
    }

    public function test_delete_refuses_to_destroy_run_history(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $rule = $this->persistRule($organization, $site, $actor, 'Historic');
        app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new DateTimeImmutable('2026-10-01T00:05:00Z'),
        );

        Sanctum::actingAs($actor);
        $this->deleteJson('/api/v1/automation/rules/'.$rule->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'rule_has_runs');

        $this->assertNotNull(AutomationRule::query()->find($rule->id));
        $this->assertSame(1, $rule->runs()->count());
    }

    public function test_delete_is_denied_for_viewers_and_hidden_across_tenants(): void
    {
        [$viewer, $organization, $site] = $this->makeTenant('viewer');
        $rule = $this->persistRule($organization, $site, $viewer, 'Viewer delete');
        Sanctum::actingAs($viewer);

        $this->deleteJson('/api/v1/automation/rules/'.$rule->id)->assertForbidden();
        $this->assertNotNull(AutomationRule::query()->find($rule->id));

        [$owner, $organization, $site] = $this->makeTenant('owner');
        $foreignRule = $this->persistRule($organization, $site, $owner, 'Foreign delete');
        [$outsider] = $this->makeForeignTenant();
        Sanctum::actingAs($outsider);

        $this->deleteJson('/api/v1/automation/rules/'.$foreignRule->id)->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | End-to-end: a created rule is consumable by the existing engine
     | ------------------------------------------------------------------ */

    public function test_created_enabled_rule_drives_an_operation_through_the_existing_engine(): void
    {
        [$actor, $organization, $site] = $this->makeTenant('owner');
        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => AutomationRule::ACTION_CACHE_CLEAR,
            'enabled' => true,
            'reported_supported' => true,
            'reported_at' => now(),
            'discovered_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        $ruleId = $this->store([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Product loop',
            'enabled' => true,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
        ])->assertCreated()->json('data.id');

        $results = app(AutomationSchedulerService::class)
            ->processDueRules(new DateTimeImmutable('2026-10-01T10:05:00Z'));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['skipped']);
        $this->assertSame($ruleId, $results[0]['rule_id']);
        $this->assertTrue($results[0]['run_created']);
        $this->assertTrue($results[0]['claimed']);

        $operation = Operation::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertSame(AutomationRule::ACTION_CACHE_CLEAR, $operation->operation_type);
        $this->assertSame(['cache_type' => 'wordpress'], $operation->target_json);
        $this->assertSame($actor->id, $operation->requested_by);
        $this->assertSame(
            sprintf('automation:run:%s:operation:v1', $results[0]['run_id']),
            $operation->idempotency_key,
        );

        $run = AutomationRun::query()->findOrFail($results[0]['run_id']);
        $this->assertSame($operation->id, $run->operation_id);
        $this->assertNotSame(AutomationRun::STATUS_PENDING, $run->status);
        $this->assertNotSame(AutomationRun::STATUS_EVALUATING, $run->status);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    private function payload(Organization $organization, Site $site): array
    {
        return [
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Denied rule',
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
        ];
    }

    private function store(array $payload)
    {
        return $this->postJson('/api/v1/automation/rules', $payload);
    }

    private function persistRule(
        Organization $organization,
        Site $site,
        User $creator,
        string $name,
        bool $enabled = true,
    ): AutomationRule {
        return AutomationRule::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => $name,
            'enabled' => $enabled,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $creator->id,
        ]);
    }

    /** @return array{0: User, 1: Organization, 2: Site, 3: OrganizationMember} */
    private function makeTenant(string $roleKey): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => $roleKey]);
        $membership = OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        return [$user, $organization, $site, $membership];
    }

    /** @return array{0: User, 1: Organization, 2: Site} */
    private function makeForeignTenant(string $roleKey = 'owner'): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => $roleKey]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);

        return [$user, $organization, $site];
    }
}
