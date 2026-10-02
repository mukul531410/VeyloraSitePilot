<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AutomationRequesterAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationRequesterAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_owner_and_admin_rule_creators_are_authorized(): void
    {
        foreach (['owner', 'admin'] as $roleKey) {
            [$rule] = $this->makeRule($roleKey);
            $this->assertTrue(app(AutomationRequesterAuthorization::class)->canRequest($rule));
        }
    }

    public function test_inactive_creator_is_denied(): void
    {
        [$rule, $creator] = $this->makeRule();
        $creator->update(['status' => 'inactive']);

        $this->assertFalse(app(AutomationRequesterAuthorization::class)->canRequest($rule));
    }

    public function test_deleted_or_missing_rule_creator_is_denied(): void
    {
        [$rule, $creator] = $this->makeRule();
        $creator->delete();

        $this->assertFalse(app(AutomationRequesterAuthorization::class)->canRequest($rule));
        $this->assertFalse(app(AutomationRequesterAuthorization::class)->canRequest(new AutomationRule));
    }

    public function test_inactive_organization_site_or_membership_is_denied(): void
    {
        foreach (['organization', 'site', 'membership'] as $subject) {
            [$rule, $creator, $organization, $site, $membership] = $this->makeRule();
            match ($subject) {
                'organization' => $organization->update(['status' => 'inactive']),
                'site' => $site->update(['status' => 'inactive']),
                'membership' => $membership->update(['status' => 'inactive']),
            };

            $this->assertFalse(app(AutomationRequesterAuthorization::class)->canRequest($rule), $subject);
        }
    }

    public function test_cross_organization_rule_site_mismatch_is_denied(): void
    {
        [$rule] = $this->makeRule();
        $otherOrganization = Organization::factory()->create();
        $otherSite = Site::factory()->create(['organization_id' => $otherOrganization->id]);
        DB::table('automation_rules')->where('id', $rule->id)->update(['site_id' => $otherSite->id]);

        $this->assertFalse(app(AutomationRequesterAuthorization::class)->canRequest($rule));
    }

    public function test_viewer_or_operator_without_site_operate_role_is_denied(): void
    {
        foreach (['viewer', 'operator'] as $roleKey) {
            [$rule] = $this->makeRule($roleKey);
            $this->assertFalse(app(AutomationRequesterAuthorization::class)->canRequest($rule), $roleKey);
        }
    }

    private function makeRule(string $roleKey = 'owner'): array
    {
        $creator = User::factory()->create();
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => $roleKey]);
        $membership = OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $creator->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $rule = AutomationRule::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Authorized schedule',
            'enabled' => true,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $creator->id,
        ]);

        return [$rule, $creator, $organization, $site, $membership];
    }
}
