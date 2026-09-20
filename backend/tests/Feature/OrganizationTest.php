<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_organizations_returns_only_user_organizations(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org1->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org1->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        OrganizationMember::create([
            'organization_id' => $org2->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org2->id, 'key' => 'admin'])->id,
            'status' => 'active',
        ]);

        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role_id' => Role::factory()->create(['organization_id' => $otherOrg->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organizations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'status']],
                'meta' => ['current_page', 'per_page', 'total'],
                'request_id',
            ])
            ->assertJson([
                'meta' => ['total' => 2],
            ]);

        $orgIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($org1->id, $orgIds);
        $this->assertContains($org2->id, $orgIds);
        $this->assertNotContains($otherOrg->id, $orgIds);
    }

    public function test_create_organization_creates_org_member_and_role(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'slug', 'status'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'name' => 'Acme Corp',
                    'slug' => 'acme-corp',
                    'status' => 'active',
                ],
            ]);

        $org = Organization::where('slug', 'acme-corp')->first();
        $this->assertNotNull($org);

        $member = OrganizationMember::where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($member);
        $this->assertEquals('active', $member->status);

        $role = Role::where('organization_id', $org->id)
            ->where('key', 'owner')
            ->first();
        $this->assertNotNull($role);
    }

    public function test_create_organization_validation_errors(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => '',
            'slug' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_show_organization_for_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organizations/' . $org->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'slug', 'status'],
                'meta',
                'request_id',
            ])
            ->assertJson([
                'data' => [
                    'name' => $org->name,
                    'slug' => $org->slug,
                ],
            ]);
    }

    public function test_show_organization_for_non_member_returns_403(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $otherUser->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organizations/' . $org->id);

        $response->assertStatus(403);
    }

    public function test_update_organization_for_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/organizations/' . $org->id, [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => 'Updated Name',
                ],
            ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_organization_for_non_member_returns_403(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org = Organization::factory()->create();

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $otherUser->id,
            'role_id' => Role::factory()->create(['organization_id' => $org->id, 'key' => 'owner'])->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/organizations/' . $org->id, [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(403);
    }
}
