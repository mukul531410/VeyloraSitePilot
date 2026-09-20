<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    private function createOrgMembership(User $user, Organization $org): void
    {
        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);
    }

    public function test_list_sites_returns_only_user_organizations_sites(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();

        $this->createOrgMembership($user, $org);
        $this->createOrgMembership($otherUser, $otherOrg);

        $site1 = Site::factory()->create(['organization_id' => $org->id]);
        $site2 = Site::factory()->create(['organization_id' => $org->id]);
        $otherSite = Site::factory()->create(['organization_id' => $otherOrg->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'organization_id', 'name', 'url', 'environment', 'status']],
                'meta' => ['current_page', 'per_page', 'total'],
                'request_id',
            ])
            ->assertJson([
                'meta' => ['total' => 2],
            ]);

        $siteIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($site1->id, $siteIds);
        $this->assertContains($site2->id, $siteIds);
        $this->assertNotContains($otherSite->id, $siteIds);
    }

    public function test_create_site_for_organization_user_is_member_of(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sites', [
            'organization_id' => $org->id,
            'name' => 'My WordPress Site',
            'url' => 'https://example.com',
            'environment' => 'production',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'organization_id', 'name', 'url', 'environment', 'status'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'name' => 'My WordPress Site',
                    'url' => 'https://example.com',
                    'environment' => 'production',
                    'status' => 'active',
                ],
            ]);

        $this->assertDatabaseHas('sites', [
            'organization_id' => $org->id,
            'name' => 'My WordPress Site',
            'url' => 'https://example.com',
        ]);
    }

    public function test_create_site_for_organization_user_not_member_returns_403(): void
    {
        $user = User::factory()->create();
        $otherOrg = Organization::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sites', [
            'organization_id' => $otherOrg->id,
            'name' => 'Unauthorized Site',
            'url' => 'https://example.com',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('sites', [
            'organization_id' => $otherOrg->id,
            'name' => 'Unauthorized Site',
        ]);
    }

    public function test_create_site_validation_errors(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sites', [
            'organization_id' => $org->id,
            'name' => '',
            'url' => 'invalid-url',
        ]);

        $response->assertStatus(422);
    }

    public function test_show_site_for_organization_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        $site = Site::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites/' . $site->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'organization_id', 'name', 'url', 'environment', 'status'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'name' => $site->name,
                    'url' => $site->url,
                ],
            ]);
    }

    public function test_show_site_for_non_member_returns_403(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        $otherOrg = Organization::factory()->create();
        $this->createOrgMembership($otherUser, $otherOrg);

        $site = Site::factory()->create(['organization_id' => $otherOrg->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sites/' . $site->id);

        $response->assertStatus(403);
    }

    public function test_update_site_for_organization_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        $site = Site::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/sites/' . $site->id, [
            'name' => 'Updated Site Name',
            'environment' => 'staging',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => 'Updated Site Name',
                    'environment' => 'staging',
                ],
            ]);

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'name' => 'Updated Site Name',
            'environment' => 'staging',
        ]);
    }

    public function test_update_site_for_non_member_returns_403(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        $otherOrg = Organization::factory()->create();
        $this->createOrgMembership($otherUser, $otherOrg);

        $site = Site::factory()->create(['organization_id' => $otherOrg->id]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/sites/' . $site->id, [
            'name' => 'Updated Site Name',
        ]);

        $response->assertStatus(403);
    }

    public function test_delete_site_for_organization_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        $site = Site::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/sites/' . $site->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_delete_site_for_non_member_returns_403(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org = Organization::factory()->create();
        $this->createOrgMembership($user, $org);

        $otherOrg = Organization::factory()->create();
        $this->createOrgMembership($otherUser, $otherOrg);

        $site = Site::factory()->create(['organization_id' => $otherOrg->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/sites/' . $site->id);

        $response->assertStatus(403);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }
}
