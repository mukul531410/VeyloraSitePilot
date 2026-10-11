<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    /** A value that must never survive the read surface, whatever field it sits in. */
    private const SENSITIVE_MARKER = 'super-secret-payload-do-not-leak';

    public function test_audit_log_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
    }

    public function test_members_of_every_role_see_only_their_organization_activity(): void
    {
        foreach (['owner', 'admin', 'viewer', 'operator'] as $roleKey) {
            [$actor, $organization, $site] = $this->member($roleKey);
            $mineSite = $this->audit($organization, $site, $actor, 'operation_requested');
            $mineOrgLevel = $this->audit($organization, null, $actor, 'automation_rule_created', 'automation_rule');

            [$stranger, $strangerOrganization, $strangerSite] = $this->member('owner');
            $theirs = $this->audit($strangerOrganization, $strangerSite, $stranger, 'operation_cancelled');

            Sanctum::actingAs($actor);

            $response = $this->withHeader('X-Request-ID', 'audit-'.$roleKey)
                ->getJson('/api/v1/audit-logs')
                ->assertOk()
                ->assertJsonPath('request_id', 'audit-'.$roleKey)
                ->assertJsonPath('meta.total', 2);

            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($mineSite->id));
            $this->assertTrue($ids->contains($mineOrgLevel->id));
            $this->assertFalse($ids->contains($theirs->id));

            $this->assertSame($organization->id, $response->json('data.0.organization_id'));
            $this->assertSame($actor->id, $response->json('data.0.user.id'));
            $this->assertSame($actor->name, $response->json('data.0.user.name'));
            $this->assertNull($response->json('data.0.site'));
            $this->assertSame($site->id, $response->json('data.1.site.id'));
            $this->assertSame($site->name, $response->json('data.1.site.name'));

            AuditLog::query()->delete();
        }
    }

    public function test_sensitive_payload_columns_are_never_serialised(): void
    {
        [$actor, $organization, $site] = $this->member('owner');
        $this->audit($organization, $site, $actor, 'operation_requested');

        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/audit-logs')->assertOk();

        $item = $response->json('data.0');
        $this->assertSame([
            'id',
            'organization_id',
            'site_id',
            'user_id',
            'action',
            'target_type',
            'target_id',
            'correlation_id',
            'policy_result',
            'created_at',
            'user',
            'site',
        ], array_keys($item));

        $this->assertArrayNotHasKey('before_json', $item);
        $this->assertArrayNotHasKey('after_json', $item);
        $this->assertArrayNotHasKey('metadata_json', $item);
        $this->assertStringNotContainsString(self::SENSITIVE_MARKER, $response->getContent());
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'operation_requested',
        ]);
    }

    public function test_other_tenants_activity_is_invisible(): void
    {
        [$actor, $organization, $site] = $this->member('owner');
        $mine = $this->audit($organization, $site, $actor, 'operation_requested');

        [$stranger, $strangerOrganization, $strangerSite] = $this->member('owner');
        $theirs = $this->audit($strangerOrganization, $strangerSite, $stranger, 'operation_requested');

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine->id);

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $theirs->id);
    }

    public function test_inactive_membership_organization_site_and_user_are_excluded(): void
    {
        [$actor, $organization, $site, $membership] = $this->member('owner');
        $this->audit($organization, $site, $actor, 'operation_requested');
        $this->audit($organization, null, $actor, 'automation_rule_created', 'automation_rule');

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('meta.total', 2);

        // An inactive site hides only its site-linked rows; org-level rows survive.
        $site->update(['status' => 'inactive']);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('meta.total', 1);
        $site->update(['status' => 'active']);

        $membership->update(['status' => 'revoked']);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('meta.total', 0);
        $membership->update(['status' => 'active']);

        $organization->update(['status' => 'suspended']);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('meta.total', 0);
        $organization->update(['status' => 'active']);

        $actor->update(['status' => 'inactive']);
        $this->getJson('/api/v1/audit-logs')->assertStatus(403)->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_activity_feed_paginates_deterministically(): void
    {
        [$actor, $organization, $site] = $this->member('owner');

        $first = $this->audit($organization, $site, $actor, 'operation_requested');
        $second = $this->audit($organization, $site, $actor, 'policy_evaluated');
        $third = $this->audit($organization, $site, $actor, 'operation_cancelled');

        // created_at wins over the ULID tiebreaker when the two disagree.
        $first->forceFill(['created_at' => now()->addMinute()])->save();

        Sanctum::actingAs($actor);

        $pageOne = $this->getJson('/api/v1/audit-logs?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 1);

        $this->assertSame(
            [$first->id, $third->id],
            collect($pageOne->json('data'))->pluck('id')->all()
        );

        $this->getJson('/api/v1/audit-logs?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $second->id);
    }

    public function test_activity_filters_narrow_results_and_invalid_filters_are_rejected(): void
    {
        [$actor, $organization, $site] = $this->member('owner');
        [$teammate, , $otherSite] = $this->member('operator', $organization);

        $requested = $this->audit($organization, $site, $actor, 'operation_requested');
        $this->audit($organization, $site, $teammate, 'operation_cancelled');
        $rule = $this->audit($organization, $otherSite, $actor, 'automation_rule_created', 'automation_rule');
        $this->audit($organization, null, $actor, 'connector_credential_issued', 'connector_credential');

        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/audit-logs?action=operation_requested')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $requested->id);

        $this->getJson('/api/v1/audit-logs?target_type=automation_rule')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $rule->id);

        $this->getJson('/api/v1/audit-logs?site_id='.$site->id)
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/audit-logs?user_id='.$teammate->id)
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/audit-logs?action=nothing_matches')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/audit-logs?per_page=500')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->getJson('/api/v1/audit-logs?per_page=abc')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->getJson('/api/v1/audit-logs?page=0')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_activity_surface_is_read_only(): void
    {
        [$actor] = $this->member('owner');
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/audit-logs', [])->assertStatus(405);
        $this->patchJson('/api/v1/audit-logs', [])->assertStatus(405);
        $this->deleteJson('/api/v1/audit-logs')->assertStatus(405);
    }

    private function member(string $roleKey, ?Organization $organization = null, ?Site $site = null): array
    {
        $user = User::factory()->create();
        $organization ??= Organization::factory()->create();
        $site ??= Site::factory()->create(['organization_id' => $organization->id]);
        $role = Role::factory()->create(['organization_id' => $organization->id, 'key' => $roleKey]);
        $membership = OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return [$user, $organization, $site, $membership];
    }

    private function audit(
        Organization $organization,
        ?Site $site,
        User $actor,
        string $action,
        string $targetType = 'operation',
        array $overrides = [],
    ): AuditLog {
        return AuditLog::query()->create(array_merge([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'site_id' => $site?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'before_json' => ['secret' => self::SENSITIVE_MARKER],
            'after_json' => ['secret' => self::SENSITIVE_MARKER],
            'metadata_json' => ['secret' => self::SENSITIVE_MARKER],
        ], $overrides));
    }
}
