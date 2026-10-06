<?php

namespace Tests\Feature;

use App\Events\NotificationSourceEvent;
use App\Models\ApprovalRequest;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AvailableUpdate;
use App\Models\Incident;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\Operation;
use App\Models\OperationResult;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\NotificationDeliveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_constraints_relationships_defaults_and_idempotent_mark_read(): void
    {
        [$user, $organization, $site] = $this->member('owner');
        $notification = $this->notification($user, $organization, $site, 'src_one');

        $this->assertNull($notification->read_at);
        $this->assertSame($organization->id, $notification->organization->id);
        $this->assertSame($site->id, $notification->site->id);
        $this->assertSame($user->id, $notification->user->id);

        $channel = NotificationChannel::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'type' => 'in_app'], ['enabled' => true],
        );
        NotificationPreference::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'event_type' => Notification::TYPE_INCIDENT_DETECTED,
            'channel_id' => $channel->id,
            'enabled' => false,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/notifications/'.$notification->id.'/read')->assertOk();
        $readAt = $notification->fresh()->read_at->toIso8601String();
        $this->postJson('/api/v1/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertSame($readAt, $notification->fresh()->read_at->toIso8601String());

        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'enabled' => false]);
        $this->assertDatabaseHas('notification_channels', ['organization_id' => $organization->id, 'type' => 'in_app']);
    }

    public function test_database_enforces_channel_preference_and_source_recipient_uniqueness(): void
    {
        [$user, $organization, $site] = $this->member('owner');
        $notification = $this->notification($user, $organization, $site, 'stable-source');

        try {
            $this->notification($user, $organization, $site, 'stable-source');
            $this->fail('Duplicate source/type/recipient notification was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('notifications', 1);
        }

        $channel = NotificationChannel::query()->create([
            'organization_id' => $organization->id, 'type' => 'in_app', 'enabled' => true,
        ]);
        try {
            NotificationChannel::query()->create([
                'organization_id' => $organization->id, 'type' => 'in_app', 'enabled' => true,
            ]);
            $this->fail('Duplicate organization in-app channel was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('notification_channels', 1);
        }

        $preference = [
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'event_type' => Notification::TYPE_INCIDENT_DETECTED, 'channel_id' => $channel->id, 'enabled' => true,
        ];
        NotificationPreference::query()->create($preference);
        try {
            NotificationPreference::query()->create($preference);
            $this->fail('Duplicate event/channel preference was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('notification_preferences', 1);
        }

        $this->assertNotNull($notification->id);
    }

    public function test_list_filters_paginates_orders_and_isolated_to_the_recipient(): void
    {
        [$user, $organization, $site] = $this->member('viewer');
        [$other, $otherOrganization, $otherSite] = $this->member('owner');
        $older = $this->notification($user, $organization, $site, 'source_a');
        $newer = $this->notification($user, $organization, $site, 'source_b', Notification::TYPE_OPERATION_FAILED, 'high');
        $this->notification($other, $otherOrganization, $otherSite, 'source_c');
        $older->forceFill(['created_at' => now()->subMinute()])->save();
        $newer->forceFill(['created_at' => now()])->save();

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications?status=unread&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $newer->id);
        $this->getJson('/api/v1/notifications?type=operation_failed&severity=high&site_id='.$site->id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $newer->id);
        $this->getJson('/api/v1/notifications?status=bad')->assertUnprocessable();
        $this->getJson('/api/v1/notifications/'.$this->notification($other, $otherOrganization, $otherSite, 'source_d')->id)
            ->assertNotFound();
    }

    public function test_delivery_uses_active_membership_preferences_and_deterministic_source_identity(): void
    {
        [$owner, $organization, $site] = $this->member('owner');
        [$viewer] = $this->member('viewer', $organization, $site);
        [$admin] = $this->member('admin', $organization, $site);
        $inactiveUser = User::factory()->create(['status' => 'inactive']);
        $this->addMember($inactiveUser, $organization, 'owner', 'active');
        $inactiveMember = User::factory()->create();
        $this->addMember($inactiveMember, $organization, 'owner', 'inactive');

        $incident = Incident::factory()->create([
            'site_id' => $site->id,
            'status' => 'detected',
            'severity' => 'critical',
            'title' => 'Site unreachable',
        ]);
        NotificationSourceEvent::dispatch('incident', $incident->id, Notification::TYPE_INCIDENT_DETECTED);
        $this->assertSame(3, Notification::query()->where('source_id', $incident->id)->count());
        $this->assertSame(['critical'], Notification::query()->where('source_id', $incident->id)->pluck('severity')->unique()->all());

        $channel = NotificationChannel::query()->where('organization_id', $organization->id)->where('type', 'in_app')->firstOrFail();
        NotificationPreference::query()->create([
            'organization_id' => $organization->id, 'user_id' => $viewer->id,
            'event_type' => Notification::TYPE_INCIDENT_RESOLVED, 'channel_id' => $channel->id, 'enabled' => false,
        ]);
        $incident->update(['status' => 'resolved', 'resolved_at' => now()]);
        app(NotificationDeliveryService::class)->deliver('incident', $incident->id, Notification::TYPE_INCIDENT_RESOLVED);
        $this->assertSame(2, Notification::query()->where('source_id', $incident->id)->where('type', Notification::TYPE_INCIDENT_RESOLVED)->count());
        $this->assertDatabaseMissing('notifications', ['source_id' => $incident->id, 'type' => Notification::TYPE_INCIDENT_RESOLVED, 'user_id' => $viewer->id]);

        app(NotificationDeliveryService::class)->deliver('incident', $incident->id, Notification::TYPE_INCIDENT_RESOLVED);
        $this->assertSame(5, Notification::query()->where('source_id', $incident->id)->count());
        $this->assertNotSame($owner->id, $admin->id);
    }

    public function test_approval_notifications_only_reach_owner_admin_and_exclude_requester(): void
    {
        [$requester, $organization, $site] = $this->member('owner');
        [$owner] = $this->member('owner', $organization, $site);
        [$admin] = $this->member('admin', $organization, $site);
        [$viewer] = $this->member('viewer', $organization, $site);
        $operation = Operation::query()->create([
            'site_id' => $site->id, 'operation_type' => 'action.cache_clear', 'target_json' => [],
            'status' => 'pending_approval', 'idempotency_key' => 'approval-test', 'requested_by' => $requester->id,
        ]);
        $approval = ApprovalRequest::query()->create([
            'organization_id' => $organization->id, 'site_id' => $site->id, 'operation_id' => $operation->id,
            'status' => 'pending', 'requested_by' => $requester->id, 'expires_at' => now()->addDay(),
        ]);

        app(NotificationDeliveryService::class)->deliver('approval_request', $approval->id, Notification::TYPE_APPROVAL_REQUESTED);
        $this->assertSame(2, Notification::query()->where('source_id', $approval->id)->count());
        $this->assertDatabaseHas('notifications', ['source_id' => $approval->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('notifications', ['source_id' => $approval->id, 'user_id' => $admin->id]);
        $this->assertDatabaseMissing('notifications', ['source_id' => $approval->id, 'user_id' => $requester->id]);
        $this->assertDatabaseMissing('notifications', ['source_id' => $approval->id, 'user_id' => $viewer->id]);
    }

    public function test_all_durable_source_types_can_be_delivered_with_stable_source_ids(): void
    {
        [$user, $organization, $site] = $this->member('owner');
        $delivery = app(NotificationDeliveryService::class);

        $finding = AvailableUpdate::query()->create([
            'site_id' => $site->id, 'type' => 'plugin', 'item_identifier' => 'sample/plugin.php',
            'severity' => 'info', 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $delivery->deliver('available_update', $finding->id, Notification::TYPE_AVAILABLE_UPDATE_DETECTED);

        $operation = Operation::query()->create([
            'site_id' => $site->id, 'operation_type' => 'action.cache_clear', 'target_json' => [],
            'status' => 'failed', 'idempotency_key' => 'failure-test', 'requested_by' => $user->id,
        ]);
        $delivery->deliver('operation', $operation->id, Notification::TYPE_OPERATION_FAILED);
        $operation->update(['status' => 'unknown']);
        $delivery->deliver('operation', $operation->id, Notification::TYPE_OPERATION_UNKNOWN);

        $result = OperationResult::query()->create([
            'operation_id' => $operation->id, 'result_status' => 'failed', 'verification_status' => 'failed',
            'verification_error' => 'stale_read',
        ]);
        $delivery->deliver('operation_result', $result->id, Notification::TYPE_OPERATION_VERIFICATION_FAILED);

        $rule = AutomationRule::query()->create([
            'organization_id' => $organization->id, 'site_id' => $site->id, 'name' => 'Test rule',
            'enabled' => false, 'trigger_type' => 'schedule', 'schedule_json' => ['every_minutes' => 30, 'starts_at_utc' => '2026-10-01T00:00:00Z'],
            'conditions_json' => null, 'action_type' => 'action.cache_clear', 'target_json' => [], 'created_by' => $user->id,
        ]);
        $run = AutomationRun::query()->create([
            'automation_rule_id' => $rule->id, 'organization_id' => $organization->id, 'site_id' => $site->id,
            'occurrence_key' => 'test-occurrence', 'status' => 'pending', 'failure_code' => 'evaluation_failed',
            'failure_message' => 'Rule evaluation failed.',
        ]);
        $delivery->deliver('automation_run', $run->id, Notification::TYPE_AUTOMATION_FAILED);

        $this->assertDatabaseHas('notifications', ['source_type' => 'available_update', 'source_id' => $finding->id, 'type' => Notification::TYPE_AVAILABLE_UPDATE_DETECTED]);
        $this->assertDatabaseHas('notifications', ['source_type' => 'operation', 'source_id' => $operation->id, 'type' => Notification::TYPE_OPERATION_FAILED]);
        $this->assertDatabaseHas('notifications', ['source_type' => 'operation', 'source_id' => $operation->id, 'type' => Notification::TYPE_OPERATION_UNKNOWN]);
        $this->assertDatabaseHas('notifications', ['source_type' => 'operation_result', 'source_id' => $result->id, 'type' => Notification::TYPE_OPERATION_VERIFICATION_FAILED]);
        $this->assertDatabaseHas('notifications', ['source_type' => 'automation_run', 'source_id' => $run->id, 'type' => Notification::TYPE_AUTOMATION_FAILED]);
    }

    private function member(string $role, ?Organization $organization = null, ?Site $site = null): array
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create();
        $this->addMember($user, $organization, $role);
        $site ??= Site::factory()->create(['organization_id' => $organization->id]);

        return [$user, $organization, $site];
    }

    private function addMember(User $user, Organization $organization, string $role, string $status = 'active'): void
    {
        $roleModel = Role::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'key' => $role], ['name' => ucfirst($role)],
        );
        OrganizationMember::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role_id' => $roleModel->id, 'status' => $status,
        ]);
    }

    private function notification(User $user, Organization $organization, Site $site, string $sourceId, string $type = Notification::TYPE_INCIDENT_DETECTED, string $severity = 'info'): Notification
    {
        return Notification::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'site_id' => $site->id,
            'source_type' => 'incident', 'source_id' => $sourceId, 'type' => $type,
            'severity' => $severity, 'title' => 'Test notice', 'body' => 'Test body',
        ]);
    }
}
