<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use Database\Seeders\E2eTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class E2eTenantSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(E2eTenantSeeder::class);
    }

    public function test_seeder_creates_the_minimum_active_tenant(): void
    {
        $user = User::query()->where('email', E2eTenantSeeder::USER_EMAIL)->firstOrFail();

        $this->assertSame('active', $user->status);
        $this->assertNotSame('test@example.com', $user->email);

        $organization = Organization::query()->where('slug', E2eTenantSeeder::ORGANIZATION_SLUG)->firstOrFail();
        $this->assertSame('active', $organization->status);
        $this->assertSame(E2eTenantSeeder::APPROVAL_POLICY, $organization->approval_policy);

        $role = Role::query()
            ->where('organization_id', $organization->id)
            ->where('key', E2eTenantSeeder::ROLE_KEY)
            ->firstOrFail();
        $this->assertSame('owner', $role->key);

        $membership = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $this->assertSame('active', $membership->status);
        $this->assertSame($role->id, $membership->role_id);

        $site = Site::query()
            ->where('organization_id', $organization->id)
            ->where('url', E2eTenantSeeder::SITE_URL)
            ->firstOrFail();
        $this->assertSame('active', $site->status);
        $this->assertSame('low', $site->business_criticality);
    }

    public function test_seeder_is_idempotent_across_repeat_runs(): void
    {
        $this->seed(E2eTenantSeeder::class);
        $this->seed(E2eTenantSeeder::class);

        $this->assertSame(1, User::query()->where('email', E2eTenantSeeder::USER_EMAIL)->count());
        $this->assertSame(1, Organization::query()->where('slug', E2eTenantSeeder::ORGANIZATION_SLUG)->count());
        $this->assertSame(1, Role::query()->where('key', E2eTenantSeeder::ROLE_KEY)->count());
        $this->assertSame(1, OrganizationMember::query()->count());
        $this->assertSame(1, Site::query()->where('url', E2eTenantSeeder::SITE_URL)->count());
    }

    public function test_repeat_runs_never_reset_an_existing_password(): void
    {
        $passwordBefore = User::query()->where('email', E2eTenantSeeder::USER_EMAIL)->firstOrFail()->password;

        $this->seed(E2eTenantSeeder::class);

        $this->assertSame(
            $passwordBefore,
            User::query()->where('email', E2eTenantSeeder::USER_EMAIL)->firstOrFail()->password,
        );
    }

    public function test_seeded_password_matches_the_documented_local_credential(): void
    {
        $user = User::query()->where('email', E2eTenantSeeder::USER_EMAIL)->firstOrFail();

        $this->assertTrue(Hash::check(E2eTenantSeeder::USER_PASSWORD, $user->password));
    }

    public function test_seeder_creates_no_connection_intent_capability_or_token(): void
    {
        $this->assertSame(0, SiteConnection::query()->count());
        $this->assertSame(0, DB::table('connector_capabilities')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_seeded_tenant_is_authorized_for_operations_by_role_fallback(): void
    {
        $user = User::query()->where('email', E2eTenantSeeder::USER_EMAIL)->firstOrFail();
        $site = Site::query()->where('url', E2eTenantSeeder::SITE_URL)->firstOrFail();

        // Mirrors PolicyEngine::checkOperatePermission's owner/admin fallback.
        $this->assertTrue(
            $user->organizations()
                ->whereHas('sites', fn ($q) => $q->whereKey($site->id))
                ->whereHas('roles', fn ($q) => $q->whereIn('key', ['owner', 'admin']))
                ->exists(),
        );
    }

    public function test_seeded_approval_policy_is_valid_for_policy_engine(): void
    {
        $site = Site::query()->where('url', E2eTenantSeeder::SITE_URL)->firstOrFail();
        $policy = $site->organization->approval_policy;

        $this->assertIsArray($policy);
        $this->assertArrayHasKey('require_approval', $policy);
        $this->assertArrayHasKey('high_criticality_requires_approval', $policy);
        $this->assertIsBool($policy['require_approval']);
        $this->assertIsBool($policy['high_criticality_requires_approval']);
    }
}
