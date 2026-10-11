<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local-only tenant for connector end-to-end testing.
 *
 * Creates the minimum records required before a SiteConnection intent can be
 * issued: one active user, one active organization, one owner role, one active
 * membership, and one active site.
 *
 * This seeder is deliberately narrow:
 *  - It never creates a SiteConnection, connection intent, capability grant or
 *    API token. Those come from the supported runtime paths
 *    (POST /sites/{site}/connections, then POST /connector/register).
 *  - It never overwrites an existing user's password or any unrelated field.
 *  - It is idempotent: every record is located first and only created when
 *    absent, so it is safe to run repeatedly.
 *
 * NOT for production. The credential below is a documented, local-only test
 * password for a machine-local WordPress site.
 */
class E2eTenantSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Dedicated local-test identity. Never reuses test@example.com. */
    public const USER_EMAIL = 'sitepilot-e2e@sitepilot.local';

    /**
     * Documented local-only test password. This account exists solely for
     * machine-local connector E2E testing and must never be granted access to
     * a shared or production environment.
     */
    public const USER_PASSWORD = 'SitePilot-E2E-LocalOnly-2026!';

    public const USER_NAME = 'SitePilot E2E Operator';

    public const ORGANIZATION_NAME = 'SitePilot E2E Local Org';

    public const ORGANIZATION_SLUG = 'sitepilot-e2e-local';

    public const ROLE_KEY = 'owner';

    public const ROLE_NAME = 'Owner';

    public const SITE_NAME = 'Coalition Test WordPress';

    public const SITE_URL = 'http://coalitiontest.local';

    public const SITE_ENVIRONMENT = 'development';

    /**
     * PolicyEngine fails closed unless approval_policy is an array containing
     * both keys with boolean values (see PolicyEngine::checkApprovalPolicy and
     * ::requiresApproval). Both flags are false so the low-impact
     * action.cache_clear E2E flow is not gated by a human approval step.
     */
    public const APPROVAL_POLICY = [
        'require_approval' => false,
        'high_criticality_requires_approval' => false,
    ];

    public function run(): void
    {
        $user = $this->findOrCreateUser();
        $organization = $this->findOrCreateOrganization();
        $role = $this->findOrCreateRole($organization);
        $this->findOrCreateMembership($organization, $user, $role);
        $this->findOrCreateSite($organization);
    }

    /**
     * An existing user is returned untouched so a repeat run can never rotate
     * or reset a credential someone may already be using.
     */
    private function findOrCreateUser(): User
    {
        $existing = User::query()->where('email', self::USER_EMAIL)->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::query()->create([
            'name' => self::USER_NAME,
            'email' => self::USER_EMAIL,
            // The model casts `password` to `hashed`, so the plain value is
            // hashed on write.
            'password' => Hash::make(self::USER_PASSWORD),
            'status' => 'active',
        ]);
    }

    private function findOrCreateOrganization(): Organization
    {
        $existing = Organization::query()->where('slug', self::ORGANIZATION_SLUG)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Organization::query()->create([
            'name' => self::ORGANIZATION_NAME,
            'slug' => self::ORGANIZATION_SLUG,
            'status' => 'active',
            'approval_policy' => self::APPROVAL_POLICY,
        ]);
    }

    /**
     * The key must be owner or admin: PolicyEngine's operate-permission
     * fallback and the approval/recovery authorization services all resolve
     * authority through in_array($role->key, ['owner', 'admin'], true).
     */
    private function findOrCreateRole(Organization $organization): Role
    {
        $existing = Role::query()
            ->where('organization_id', $organization->id)
            ->where('key', self::ROLE_KEY)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Role::query()->create([
            'organization_id' => $organization->id,
            'name' => self::ROLE_NAME,
            'key' => self::ROLE_KEY,
        ]);
    }

    /** Unique on (organization_id, user_id), so a duplicate cannot be created. */
    private function findOrCreateMembership(
        Organization $organization,
        User $user,
        Role $role,
    ): OrganizationMember {
        $existing = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    /**
     * sites has no unique constraint, so the natural key is scoped manually.
     */
    private function findOrCreateSite(Organization $organization): Site
    {
        $existing = Site::query()
            ->where('organization_id', $organization->id)
            ->where('url', self::SITE_URL)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Site::query()->create([
            'organization_id' => $organization->id,
            'name' => self::SITE_NAME,
            'url' => self::SITE_URL,
            'environment' => self::SITE_ENVIRONMENT,
            'status' => 'active',
            // Deliberately not 'high'. PolicyEngine escalates only a
            // high-criticality site, so 'low' keeps the E2E cache_clear path
            // ungated when high_criticality_requires_approval is false.
            'business_criticality' => 'low',
            'timezone' => 'UTC',
            'notes' => 'Local-only WordPress site for Veylora SitePilot connector E2E testing.',
        ]);
    }
}
