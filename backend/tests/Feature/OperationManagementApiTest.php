<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\ConnectorCapability;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\OperationCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationManagementApiTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | List
     | ------------------------------------------------------------------ */

    public function test_authenticated_user_lists_visible_operations(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $this->makeOperation($site, ['status' => Operation::STATUS_REQUESTED]);
        $this->makeOperation($site, ['status' => Operation::STATUS_SUCCEEDED]);

        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/operations')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(25, $response->json('meta.per_page'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(
            [Operation::STATUS_SUCCEEDED, Operation::STATUS_REQUESTED],
            array_column($response->json('data'), 'status'),
        );
        $this->assertNotNull($response->json('request_id'));
    }

    public function test_operation_list_requires_authentication(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $this->makeOperation($site);

        $this->getJson('/api/v1/operations')->assertUnauthorized();

        [$actor, $site] = $this->makeTenant('owner');
        $this->makeOperation($site);

        $this->getJson('/api/v1/operations/'.Operation::query()->firstOrFail()->id)
            ->assertUnauthorized();
    }

    public function test_operation_list_is_tenant_isolated(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $mine = $this->makeOperation($site, ['operation_type' => 'action.cache_clear']);

        [, $foreignSite] = $this->makeTenant('owner');
        $foreign = $this->makeOperation($foreignSite, ['operation_type' => 'action.cache_clear']);

        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/operations')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($foreign->id, $ids);

        $this->getJson('/api/v1/operations/'.$foreign->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_operation_list_hides_inactive_sites_and_organizations(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/operations')->assertOk()->assertJsonPath('meta.total', 1);

        $site->update(['status' => 'inactive']);
        $this->getJson('/api/v1/operations')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/operations/'.$operation->id)->assertNotFound();

        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site);
        $site->organization->update(['status' => 'suspended']);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/operations')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/operations/'.$operation->id)->assertNotFound();
    }

    public function test_operation_list_supports_site_and_status_filters(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $this->makeOperation($site, ['status' => Operation::STATUS_REQUESTED]);
        $this->makeOperation($site, ['status' => Operation::STATUS_FAILED]);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/operations?site_id='.$site->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/operations?status=failed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', Operation::STATUS_FAILED);

        $this->getJson('/api/v1/operations?site_id=01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_operation_list_paginates_with_default_and_maximum(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        for ($i = 0; $i < 30; $i++) {
            $this->makeOperation($site);
        }

        Sanctum::actingAs($actor);

        $default = $this->getJson('/api/v1/operations')->assertOk();
        $this->assertCount(25, $default->json('data'));
        $this->assertSame(25, $default->json('meta.per_page'));
        $this->assertSame(2, $default->json('meta.last_page'));
        $this->assertSame(30, $default->json('meta.total'));

        $this->getJson('/api/v1/operations?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 6);

        $this->getJson('/api/v1/operations?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        // Above the documented maximum is clamped, never honoured.
        $this->getJson('/api/v1/operations?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/v1/operations?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson('/api/v1/operations?per_page=abc')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    /* ---------------------------------------------------------------------
     | Detail
     | ------------------------------------------------------------------ */

    public function test_operation_detail_includes_attempts_and_results(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_SUCCEEDED]);

        $attempt = OperationAttempt::create([
            'operation_id' => $operation->id,
            'attempt_number' => 1,
            'status' => OperationAttempt::STATUS_RESULT_RECEIVED,
            'connector_job_id' => 'job-1',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinute(),
        ]);

        OperationResult::create([
            'operation_id' => $operation->id,
            'operation_attempt_id' => $attempt->id,
            'result_status' => 'success',
            'verification_status' => OperationResult::VERIFICATION_PASSED,
            'result_summary' => 'Cache cleared for wordpress',
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/operations/'.$operation->id)->assertOk();

        $this->assertSame($operation->id, $response->json('data.id'));
        $this->assertSame($site->id, $response->json('data.site_id'));
        $this->assertSame(Operation::STATUS_SUCCEEDED, $response->json('data.status'));
        $this->assertSame(1, $response->json('data.latest_attempt.attempt_number'));
        $this->assertSame('job-1', $response->json('data.latest_attempt.connector_job_id'));
        $this->assertSame('success', $response->json('data.result.result_status'));
        $this->assertSame('verified', $response->json('data.result.verification_status'));
        $this->assertNotNull($response->json('request_id'));
    }

    public function test_operation_detail_returns_404_for_unknown_operation(): void
    {
        [$actor] = $this->makeTenant('owner');

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/operations/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_operation_detail_does_not_leak_connector_credentials(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site);

        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/operations/'.$operation->id)->assertOk();

        $body = $response->getContent();
        foreach (['connector_token_hash', 'credential_ciphertext', 'connection_intent', 'lock_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /* ---------------------------------------------------------------------
     | Cancellation happy path
     | ------------------------------------------------------------------ */

    public function test_owner_cancels_a_cancellable_operation(): void
    {
        Queue::fake();
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        Sanctum::actingAs($actor);
        $response = $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', [
            'reason' => 'Scheduled maintenance window closed',
        ])->assertOk();

        $this->assertSame(Operation::STATUS_CANCELLED, $response->json('data.status'));
        $this->assertTrue($response->json('data.finished_at') !== null);
        $this->assertNotNull($response->json('request_id'));

        $fresh = $operation->fresh();
        $this->assertSame(Operation::STATUS_CANCELLED, $fresh->status);
        $this->assertTrue($fresh->isTerminal());

        // Cancelling must not dispatch connector work.
        Queue::assertNothingPushed();
        $this->assertSame(0, OperationAttempt::query()->count());
    }

    public function test_admin_cancels_a_queued_operation_without_dispatch(): void
    {
        Queue::fake();
        [$actor, $site] = $this->makeTenant('admin');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_QUEUED]);

        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('data.status', Operation::STATUS_CANCELLED);

        Queue::assertNothingPushed();

        // A dispatch that was already queued must no-op against a terminal operation.
        $this->assertSame(0, OperationAttempt::query()->count());
    }

    public function test_cancellation_audits_the_transition(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', [
            'reason' => 'Owner changed their mind',
        ])->assertOk();

        $audit = AuditLog::query()
            ->where('action', OperationCancellationService::AUDIT_CANCELLED)
            ->where('target_id', $operation->id)
            ->firstOrFail();

        $this->assertSame('operation', $audit->target_type);
        $this->assertSame($site->id, $audit->site_id);
        $this->assertSame($site->organization_id, $audit->organization_id);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame(26, strlen($audit->correlation_id));
        $this->assertSame(Operation::STATUS_APPROVED, $audit->before_json['status']);
        $this->assertSame(Operation::STATUS_CANCELLED, $audit->after_json['status']);
        $this->assertSame('Owner changed their mind', $audit->metadata_json['reason']);
        $this->assertSame(Operation::STATUS_APPROVED, $audit->metadata_json['previous_status']);
        $this->assertFalse($audit->metadata_json['connector_dispatch_claimed']);
        $this->assertNotNull($audit->policy_result);
    }

    public function test_oversized_request_id_does_not_break_the_audit_insert(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        // audit_logs.correlation_id is char(26); an untrusted header must not reach it verbatim.
        $long = str_repeat('x', 64);

        Sanctum::actingAs($actor);
        $this->withHeaders(['X-Request-ID' => $long])
            ->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Long correlation id'])
            ->assertOk()
            ->assertJsonPath('data.status', Operation::STATUS_CANCELLED);

        $audit = AuditLog::query()
            ->where('action', OperationCancellationService::AUDIT_CANCELLED)
            ->firstOrFail();

        $this->assertSame(26, strlen($audit->correlation_id));
        $this->assertNotSame($long, $audit->correlation_id);
    }

    public function test_short_request_id_is_preserved_as_correlation_id(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        Sanctum::actingAs($actor);
        $this->withHeaders(['X-Request-ID' => 'req-abc-123'])
            ->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Short correlation id'])
            ->assertOk();

        $this->assertSame(
            'req-abc-123',
            AuditLog::query()->where('action', OperationCancellationService::AUDIT_CANCELLED)->firstOrFail()->correlation_id,
        );
    }

    public function test_repeated_cancellation_is_rejected_without_a_second_audit(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'First'])
            ->assertOk();

        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Second'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'already_cancelled');

        $this->assertSame(1, AuditLog::query()->where('action', OperationCancellationService::AUDIT_CANCELLED)->count());
        $this->assertSame(Operation::STATUS_CANCELLED, $operation->fresh()->status);
    }

    /* ---------------------------------------------------------------------
     | Cancellation authorization
     | ------------------------------------------------------------------ */

    public function test_viewer_and_non_member_cannot_cancel(): void
    {
        [$viewer, $site] = $this->makeTenant('viewer');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        Sanctum::actingAs($viewer);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Denied'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'unauthorized');

        $outsider = $this->makeOutsider();
        Sanctum::actingAs($outsider);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Denied'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame(Operation::STATUS_APPROVED, $operation->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', OperationCancellationService::AUDIT_CANCELLED)->count());
    }

    public function test_inactive_actor_membership_and_site_are_denied(): void
    {
        [$actor, $site, $membership] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);
        $actor->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'x'])->assertForbidden();

        [$actor, $site, $membership] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);
        $membership->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        // An inactive member cannot see the operation at all, so it is not found.
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'x'])->assertNotFound();

        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);
        $site->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'x'])->assertNotFound();

        $this->assertSame(
            0,
            AuditLog::query()->where('action', OperationCancellationService::AUDIT_CANCELLED)->count(),
        );
    }

    public function test_cancel_requires_authentication(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'x'])
            ->assertUnauthorized();

        $this->assertSame(Operation::STATUS_APPROVED, $operation->fresh()->status);
    }

    /* ---------------------------------------------------------------------
     | Cancellation state rules
     | ------------------------------------------------------------------ */

    public function test_terminal_operations_cannot_be_cancelled(): void
    {
        foreach ([
            Operation::STATUS_SUCCEEDED,
            Operation::STATUS_FAILED,
            Operation::STATUS_DEAD_LETTER,
            Operation::STATUS_UNKNOWN,
        ] as $status) {
            [$actor, $site] = $this->makeTenant('owner');
            $operation = $this->makeOperation($site, ['status' => $status]);

            Sanctum::actingAs($actor);
            $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Too late'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'operation_terminal');

            $this->assertSame($status, $operation->fresh()->status);
        }

        $this->assertSame(0, AuditLog::query()->where('action', OperationCancellationService::AUDIT_CANCELLED)->count());
    }

    public function test_in_flight_operations_are_refused_with_resolution_pointer(): void
    {
        foreach ([Operation::STATUS_RUNNING, Operation::STATUS_VERIFICATION_PENDING] as $status) {
            [$actor, $site] = $this->makeTenant('owner');
            $operation = $this->makeOperation($site, ['status' => $status]);

            Sanctum::actingAs($actor);
            $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Stop it'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'operation_in_flight')
                ->assertJsonPath('error.details.reason', 'operation_in_flight');

            $this->assertSame($status, $operation->fresh()->status);
        }
    }

    public function test_operation_with_pending_approval_points_at_the_approval_workflow(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_PENDING_APPROVAL]);

        ApprovalRequest::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'operation_id' => $operation->id,
            'requested_by' => $actor->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => 'Changed plans'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation_approval_pending');

        $this->assertSame(Operation::STATUS_PENDING_APPROVAL, $operation->fresh()->status);
    }

    public function test_cancel_validates_the_reason(): void
    {
        [$actor, $site] = $this->makeTenant('owner');
        $operation = $this->makeOperation($site, ['status' => Operation::STATUS_APPROVED]);

        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reason');

        $this->postJson('/api/v1/operations/'.$operation->id.'/cancel', ['reason' => '   '])
            ->assertStatus(422);

        $this->assertSame(Operation::STATUS_APPROVED, $operation->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', OperationCancellationService::AUDIT_CANCELLED)->count());
    }

    public function test_cancel_returns_404_for_unknown_operation(): void
    {
        [$actor] = $this->makeTenant('owner');

        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/operations/01ARZ3NDEKTSV4RRFFQ69G5FAV/cancel', ['reason' => 'x'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    private function makeOperation(Site $site, array $attributes = []): Operation
    {
        return Operation::create(array_merge([
            'site_id' => $site->id,
            'operation_type' => 'action.cache_clear',
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_REQUESTED,
            'policy_result' => 'allowed',
            'approval_required' => false,
            'idempotency_key' => 'test:'.uniqid(),
            'max_attempts' => 3,
            'requested_by' => User::factory()->create()->id,
        ], $attributes));
    }

    /** @return array{0: User, 1: Site, 2: OrganizationMember} */
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

        $connection = SiteConnection::factory()->create(['site_id' => $site->id]);
        $connection->activate('1.4.0');
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => 'action.cache_clear',
            'enabled' => true,
            'discovered_at' => now(),
        ]);

        return [$user, $site, $membership];
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
}
