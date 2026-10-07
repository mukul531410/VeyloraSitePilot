<?php

namespace Tests\Feature;

use App\Exceptions\AutomationRunRecoveryException;
use App\Jobs\DispatchOperationJob;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\AutomationOperationOrigin;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationRunIntent;
use App\Models\AutomationRunRecovery;
use App\Models\ConnectorCapability;
use App\Models\Operation;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\User;
use App\Services\AutomationRunClaimService;
use App\Services\AutomationRunRecoveryService;
use App\Services\AutomationRunService;
use App\Services\OperationService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomationRunRecoveryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /* ---------------------------------------------------------------------
     | Authorization
     | ------------------------------------------------------------------ */

    public function test_owner_and_admin_may_recover_a_stranded_run(): void
    {
        foreach (['owner', 'admin'] as $roleKey) {
            [$actor, , , , , , $run] = $this->makeStranded($roleKey);

            Sanctum::actingAs($actor);
            $this->abandon($run, 'recovery-authorization-'.$roleKey)
                ->assertOk()
                ->assertJsonPath('data.run_status', AutomationRun::STATUS_ABANDONED);

            $this->assertSame(AutomationRun::STATUS_ABANDONED, $run->fresh()->status);
        }
    }

    public function test_viewer_is_denied(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('viewer');
        Sanctum::actingAs($actor);

        $this->abandon($run, 'recovery-viewer')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'unauthorized');

        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
    }

    public function test_inactive_actor_is_denied(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        $actor->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);

        $this->abandon($run, 'recovery-inactive-actor')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_inactive_membership_organization_and_site_are_hidden(): void
    {
        [$actor, , $membership, $organization, $site, , $run] = $this->makeStranded('owner');
        $membership->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->abandon($run, 'recovery-inactive-membership')->assertNotFound();

        [$actor, , $membership, $organization, $site, , $run] = $this->makeStranded('owner');
        $organization->update(['status' => 'suspended']);
        Sanctum::actingAs($actor);
        $this->abandon($run, 'recovery-inactive-org')->assertNotFound();

        [$actor, , , , $site, , $run] = $this->makeStranded('owner');
        $site->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->abandon($run, 'recovery-inactive-site')->assertNotFound();
    }

    public function test_cross_organization_and_cross_site_are_hidden_or_rejected(): void
    {
        [$actor] = $this->makeStranded('owner');
        [, , , , , , $foreignRun] = $this->makeStranded('owner');

        Sanctum::actingAs($actor);
        $this->abandon($foreignRun, 'recovery-cross-org')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_service_rejects_a_wrong_expected_site(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        $foreignOrganization = Organization::factory()->create();
        $foreignSite = Site::factory()->create(['organization_id' => $foreignOrganization->id]);

        $this->expectException(AutomationRunRecoveryException::class);
        app(AutomationRunRecoveryService::class)->recover(
            $actor,
            $run,
            AutomationRunRecovery::ACTION_ABANDON,
            'Wrong site supplied.',
            'recovery-wrong-site',
            null,
            $foreignSite,
        );
    }

    public function test_service_reports_the_cross_tenant_reason_for_a_wrong_expected_site(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        $foreignOrganization = Organization::factory()->create();
        $foreignSite = Site::factory()->create(['organization_id' => $foreignOrganization->id]);

        try {
            app(AutomationRunRecoveryService::class)->recover(
                $actor,
                $run,
                AutomationRunRecovery::ACTION_ABANDON,
                'Wrong site supplied.',
                'recovery-wrong-site-reason',
                null,
                $foreignSite,
            );
            $this->fail('Expected the wrong expected site to be rejected.');
        } catch (AutomationRunRecoveryException $exception) {
            $this->assertSame(AutomationRunRecoveryException::WRONG_TENANT, $exception->reason);
        }
    }

    /* ---------------------------------------------------------------------
     | Inspection
     | ------------------------------------------------------------------ */

    public function test_run_inspection_reports_intent_recovery_and_execution_evidence(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();
        Sanctum::actingAs($actor);

        $this->withHeader('X-Request-ID', 'recovery-inspect-1')
            ->getJson('/api/v1/automation/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('request_id', 'recovery-inspect-1')
            ->assertJsonPath('data.stranded', true)
            ->assertJsonPath('data.recoverable', true)
            ->assertJsonPath('data.run.status', AutomationRun::STATUS_EVALUATING)
            ->assertJsonPath('data.intent.original_requester_id', $intent->original_requester_id)
            ->assertJsonPath('data.intent.original_idempotency_key', $intent->original_idempotency_key)
            ->assertJsonPath('data.recovery', null)
            ->assertJsonPath('data.execution_evidence.submitted', false)
            ->assertJsonPath('data.allowed_actions', AutomationRunRecovery::ACTIONS);
    }

    public function test_run_inspection_is_tenant_isolated(): void
    {
        [$actor] = $this->makeStranded('owner');
        [, , , , , , $foreignRun] = $this->makeStranded('owner');

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/automation/runs/'.$foreignRun->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_run_inspection_surfaces_operation_evidence_for_a_key_candidate(): void
    {
        [$actor, $user, , , , $site, $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();
        $candidate = Operation::create([
            'site_id' => $site->id,
            'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_QUEUED,
            'idempotency_key' => $intent->original_idempotency_key,
            'requested_by' => $user->id,
        ]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/automation/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('data.candidate_operation.id', $candidate->id)
            ->assertJsonPath('data.execution_evidence.submitted', true)
            ->assertJsonPath('data.execution_evidence.origin_exists', false);
    }

    public function test_run_inspection_reports_a_recorded_recovery(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->abandon($run, 'recovery-inspect-after')->assertOk();

        $this->getJson('/api/v1/automation/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('data.stranded', false)
            ->assertJsonPath('data.recoverable', false)
            ->assertJsonPath('data.recovery.state', AutomationRunRecovery::STATE_ABANDONED)
            ->assertJsonPath('data.recovery.action', AutomationRunRecovery::ACTION_ABANDON)
            ->assertJsonPath('data.allowed_actions', []);
    }

    /* ---------------------------------------------------------------------
     | Link
     | ------------------------------------------------------------------ */

    public function test_link_uses_immutable_provenance_and_mirrors_operation_state(): void
    {
        [$actor, , , , , $site, $run, $operation] = $this->makeLinkedStranded();
        Sanctum::actingAs($actor);

        $this->withHeader('X-Request-ID', 'recovery-link-1')
            ->withHeader('Idempotency-Key', 'link-valid-1')
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_LINK,
                'reason' => 'Provenance already links this Operation.',
            ])
            ->assertOk()
            ->assertJsonPath('request_id', 'recovery-link-1')
            ->assertJsonPath('data.recovery_state', AutomationRunRecovery::STATE_LINKED)
            ->assertJsonPath('data.operation_id', $operation->id)
            ->assertJsonPath('data.run_status', AutomationRun::STATUS_SUBMITTED);

        $run = $run->fresh();
        $this->assertSame($operation->id, $run->operation_id);
        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $run->status);
        $this->assertSame(1, AutomationOperationOrigin::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_operation_linked')->count());
        Queue::assertNotPushed(DispatchOperationJob::class);
    }

    public function test_link_requires_provenance_and_rejects_a_key_only_candidate(): void
    {
        [$actor, $user, , , , $site, $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();
        Operation::create([
            'site_id' => $site->id,
            'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_QUEUED,
            'idempotency_key' => $intent->original_idempotency_key,
            'requested_by' => $user->id,
        ]);
        Sanctum::actingAs($actor);

        $this->link($run, 'link-key-only-1')->assertStatus(409)
            ->assertJsonPath('error.code', 'recovery_precondition_failed')
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::PROVENANCE_MISSING);

        $this->assertNull($run->fresh()->operation_id);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
    }

    public function test_link_rejects_provenance_that_does_not_prove_ownership(): void
    {
        $cases = [
            'wrong action' => ['operation_type' => 'action.other'],
            'wrong target' => ['target_json' => ['cache_type' => 'object']],
            'wrong requester' => ['requested_by' => 'requester-swap'],
            'wrong idempotency key' => ['idempotency_key' => 'automation:run:other:operation:v1'],
        ];

        foreach ($cases as $label => $overrides) {
            [$actor, $user, , , , $site, $run] = $this->makeStranded('owner');
            $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();
            $attributes = array_merge([
                'site_id' => $site->id,
                'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
                'target_json' => ['cache_type' => 'wordpress'],
                'status' => Operation::STATUS_QUEUED,
                'idempotency_key' => $intent->original_idempotency_key,
                'requested_by' => $user->id,
            ], $overrides);

            if ($overrides['requested_by'] ?? null) {
                $attributes['requested_by'] = $this->addMemberForRun($run)->id;
            }

            $operation = Operation::create($attributes);
            AutomationOperationOrigin::create([
                'automation_run_id' => $run->id,
                'operation_id' => $operation->id,
                'site_id' => $site->id,
                'organization_id' => $run->organization_id,
                'linked_at' => now('UTC'),
                'version' => 1,
            ]);

            Sanctum::actingAs($actor);
            $this->link($run, 'link-mismatch-'.str($label)->slug())
                ->assertStatus(409)
                ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::PROVENANCE_MISMATCH);

            $this->assertNull($run->fresh()->operation_id, $label);
        }
    }

    public function test_link_rejects_a_wrong_site_and_wrong_organization(): void
    {
        [$actor, , , , , $site, $run] = $this->makeLinkedStranded();
        $origin = AutomationOperationOrigin::query()->where('automation_run_id', $run->id)->firstOrFail();
        $foreignOrganization = Organization::factory()->create();
        $foreignSite = Site::factory()->create(['organization_id' => $foreignOrganization->id]);
        DB::table('automation_operation_origins')->where('id', $origin->id)
            ->update(['site_id' => $foreignSite->id]);

        Sanctum::actingAs($actor);
        $this->link($run, 'link-wrong-site')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::PROVENANCE_MISMATCH);

        [$actor, , , , , $site, $run] = $this->makeLinkedStranded();
        $origin = AutomationOperationOrigin::query()->where('automation_run_id', $run->id)->firstOrFail();
        DB::table('automation_operation_origins')->where('id', $origin->id)
            ->update(['organization_id' => $foreignOrganization->id]);

        Sanctum::actingAs($actor);
        $this->link($run, 'link-wrong-org')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::PROVENANCE_MISMATCH);

        $this->assertNull($run->fresh()->operation_id);
    }

    public function test_link_refuses_an_operation_already_claimed_by_another_run(): void
    {
        [$actor, $user, , , , $site, $run, $operation] = $this->makeLinkedStranded();
        $otherOrganization = Organization::factory()->create();
        $otherSite = Site::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherRule = AutomationRule::create([
            'organization_id' => $otherOrganization->id,
            'site_id' => $otherSite->id,
            'name' => 'Other rule',
            'enabled' => true,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $user->id,
        ]);
        $otherRun = AutomationRun::create([
            'automation_rule_id' => $otherRule->id,
            'organization_id' => $otherOrganization->id,
            'site_id' => $otherSite->id,
            'occurrence_key' => 'schedule:v1:2026-10-01T10:00:00Z',
            'status' => AutomationRun::STATUS_PENDING,
        ]);
        DB::table('automation_runs')->where('id', $otherRun->id)
            ->update(['operation_id' => $operation->id]);

        Sanctum::actingAs($actor);
        $this->link($run, 'link-another-run')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::PROVENANCE_MISMATCH);

        $this->assertNull($run->fresh()->operation_id);
    }

    public function test_link_preserves_unknown_and_terminal_authoritative_states(): void
    {
        [$actor, , , , , , $run, $unknown] = $this->makeLinkedStranded();
        $unknown->update(['status' => Operation::STATUS_UNKNOWN]);
        Sanctum::actingAs($actor);
        $this->link($run, 'link-unknown')->assertOk()
            ->assertJsonPath('data.run_status', AutomationRun::STATUS_UNKNOWN);
        $this->assertSame(Operation::STATUS_UNKNOWN, $unknown->fresh()->status);

        [$actor, , , , , , $run, $succeeded] = $this->makeLinkedStranded();
        $succeeded->update(['status' => Operation::STATUS_SUCCEEDED]);
        Sanctum::actingAs($actor);
        $this->link($run, 'link-terminal')->assertOk()
            ->assertJsonPath('data.run_status', AutomationRun::STATUS_COMPLETED);
        $this->assertSame(Operation::STATUS_SUCCEEDED, $succeeded->fresh()->status);
    }

    public function test_link_never_creates_repairs_or_dispatches_provenance(): void
    {
        [$actor, , , , , , $run, $operation] = $this->makeLinkedStranded();
        $originsBefore = AutomationOperationOrigin::query()->count();
        $operationsBefore = Operation::query()->count();
        $approvalsBefore = ApprovalRequest::query()->count();
        Sanctum::actingAs($actor);

        $this->link($run, 'link-no-side-effects')->assertOk();

        $this->assertSame($originsBefore, AutomationOperationOrigin::query()->count());
        $this->assertSame($operationsBefore, Operation::query()->count());
        $this->assertSame($approvalsBefore, ApprovalRequest::query()->count());
        $this->assertSame(Operation::STATUS_QUEUED, $operation->fresh()->status);
        Queue::assertNothingPushed();
    }

    /* ---------------------------------------------------------------------
     | Re-evaluate
     | ------------------------------------------------------------------ */

    public function test_re_evaluate_submits_the_original_intent_as_the_original_requester(): void
    {
        [$actor, $requester, , , , , $run] = $this->makeStranded('owner');
        $operator = $this->addMemberForRun($run);
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();

        Sanctum::actingAs($operator);
        $this->withHeader('Idempotency-Key', 'reevaluate-valid-1')
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_RE_EVALUATE,
                'reason' => 'Evaluation was interrupted before submission.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.recovery_state', AutomationRunRecovery::STATE_SUBMITTED)
            ->assertJsonPath('data.run_status', AutomationRun::STATUS_SUBMITTED);

        $operation = Operation::query()->where('idempotency_key', $intent->original_idempotency_key)->firstOrFail();
        $this->assertSame($requester->id, $operation->requested_by);
        $this->assertNotSame($operator->id, $operation->requested_by);
        $this->assertSame($intent->original_operation_type, $operation->operation_type);
        $this->assertSame($intent->original_target_json, $operation->target_json);
        $this->assertSame($intent->original_idempotency_key, $operation->idempotency_key);
        $this->assertSame($run->id, AutomationOperationOrigin::query()->firstOrFail()->automation_run_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_reevaluation_started')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_submitted')->count());
        Queue::assertNotPushed(DispatchOperationJob::class);
    }

    public function test_re_evaluate_uses_the_snapshotted_intent_not_the_current_rule(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();

        DB::table('automation_rules')->where('id', $run->automation_rule_id)
            ->update(['target_json' => json_encode(['cache_type' => 'object'])]);
        DB::table('automation_run_intents')->where('id', $intent->id)
            ->update(['original_target_json' => json_encode(['cache_type' => 'page'])]);

        Sanctum::actingAs($actor);
        $this->reEvaluate($run, 'reevaluate-snapshot-1')->assertStatus(202);

        $operation = Operation::query()->where('idempotency_key', $intent->original_idempotency_key)->firstOrFail();
        $this->assertSame(['cache_type' => 'page'], $operation->target_json);
    }

    public function test_re_evaluate_applies_the_current_approval_policy(): void
    {
        [$actor, , , $organization, , , $run] = $this->makeStranded('owner');
        $organization->update(['approval_policy' => [
            'require_approval' => true,
            'high_criticality_requires_approval' => false,
        ]]);
        Sanctum::actingAs($actor);

        $this->reEvaluate($run, 'reevaluate-approval-1')
            ->assertStatus(202)
            ->assertJsonPath('data.recovery_state', AutomationRunRecovery::STATE_AWAITING_APPROVAL)
            ->assertJsonPath('data.run_status', AutomationRun::STATUS_AWAITING_APPROVAL)
            ->assertJsonPath('data.operation_status', Operation::STATUS_PENDING_APPROVAL);

        $operation = Operation::query()->firstOrFail();
        $this->assertTrue($operation->approval_required);
        $this->assertSame(1, ApprovalRequest::query()->where('operation_id', $operation->id)->count());
        Queue::assertNotPushed(DispatchOperationJob::class);
    }

    public function test_re_evaluate_fails_closed_on_live_authorization_and_policy(): void
    {
        // The original requester is deactivated while a separate operator recovers.
        [$requester, , , , , , $run] = $this->makeStranded('owner');
        $operator = $this->addMemberForRun($run, 'admin');
        DB::table('users')->where('id', $requester->id)->update(['status' => 'inactive']);
        Sanctum::actingAs($operator);
        $this->reEvaluate($run, 'reevaluate-inactive-requester')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::REQUESTER_INACTIVE);

        // The original requester lost the owner/admin role.
        [$requester, , , , , , $run] = $this->makeStranded('owner');
        $operator = $this->addMemberForRun($run, 'admin');
        Role::query()->where('organization_id', $run->organization_id)
            ->where('key', 'owner')->update(['key' => 'viewer']);
        Sanctum::actingAs($operator);
        $this->reEvaluate($run, 'reevaluate-requester-unauthorized')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::REQUESTER_UNAUTHORIZED);

        // Connector connection is gone.
        [$actor, , , , $site, , $run] = $this->makeStranded('owner');
        DB::table('site_connections')->where('site_id', $site->id)->update(['status' => 'revoked']);
        Sanctum::actingAs($actor);
        $this->reEvaluate($run, 'reevaluate-no-connection')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::CONNECTION_UNAVAILABLE);

        // Required capability is no longer granted.
        [$actor, , , , , $site, $run] = $this->makeStranded('owner');
        DB::table('connector_capabilities')->where('site_connection_id', '!=', '')
            ->update(['enabled' => false]);
        Sanctum::actingAs($actor);
        $this->reEvaluate($run, 'reevaluate-no-capability')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::CAPABILITY_NOT_GRANTED);

        // Current policy is unreadable and fails closed.
        [$actor, , , $organization, , , $run] = $this->makeStranded('owner');
        $organization->update(['approval_policy' => ['require_approval' => 'yes']]);
        Sanctum::actingAs($actor);
        $this->reEvaluate($run, 'reevaluate-policy-denied')->assertStatus(403)
            ->assertJsonPath('error.code', 'policy_denied')
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::CURRENT_POLICY_DENIED);

        $this->assertSame(0, Operation::query()->count());
    }

    public function test_re_evaluate_is_blocked_by_any_execution_evidence(): void
    {
        // A key-only candidate is never adopted.
        [$actor, $requester, , , , $site, $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();
        Operation::create([
            'site_id' => $site->id,
            'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_QUEUED,
            'idempotency_key' => $intent->original_idempotency_key,
            'requested_by' => $requester->id,
        ]);
        Sanctum::actingAs($actor);
        $this->reEvaluate($run, 'reevaluate-candidate')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::CANDIDATE_OPERATION_EXISTS);
        $this->assertSame(1, Operation::query()->count());

        // Provenance proves ownership: that is a link case, never a re-evaluation.
        [$actor, , , , , , $run] = $this->makeLinkedStranded();
        Sanctum::actingAs($actor);
        $this->reEvaluate($run, 'reevaluate-proven')->assertStatus(409)
            ->assertJsonPath('error.code', 'link_required')
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::LINK_REQUIRED);

        $this->assertNull($run->fresh()->operation_id);
    }

    public function test_re_evaluate_requires_a_stranded_run_and_never_creates_a_successor_run(): void
    {
        [$actor, , , , , , $run, $operation] = $this->makeLinkedStranded();
        $run->refresh();
        $run->operation_id = $operation->id;
        $run->save();

        Sanctum::actingAs($actor);
        $this->reEvaluate($run, 'reevaluate-not-stranded')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::RUN_NOT_STRANDED);

        $this->assertSame(1, AutomationRun::query()->count());
    }

    /* ---------------------------------------------------------------------
     | Abandon
     | ------------------------------------------------------------------ */

    public function test_abandon_ends_the_run_without_claiming_a_remote_failure(): void
    {
        [$actor, , , , , $site, $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->abandon($run, 'abandon-valid-1')
            ->assertOk()
            ->assertJsonPath('data.recovery_state', AutomationRunRecovery::STATE_ABANDONED)
            ->assertJsonPath('data.run_status', AutomationRun::STATUS_ABANDONED)
            ->assertJsonPath('data.operation_id', null);

        $run = $run->fresh();
        $this->assertSame(AutomationRun::STATUS_ABANDONED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotSame(AutomationRun::STATUS_CANCELLED, $run->status);
        $this->assertFalse($run->evaluation_metadata_json['remote_execution_claimed']);
        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(0, AutomationOperationOrigin::query()->count());
        Queue::assertNothingPushed();

        $audit = AuditLog::query()->where('action', 'automation_run_recovery_abandoned')->firstOrFail();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertFalse($audit->metadata_json['remote_execution_claimed']);
    }

    public function test_abandon_requires_a_non_empty_reason(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->withHeader('Idempotency-Key', 'abandon-no-reason')
            ->withHeader('X-Request-ID', 'recovery-abandon-1')
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_ABANDON,
                'reason' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('request_id', 'recovery-abandon-1');

        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
    }

    public function test_abandon_is_blocked_when_the_intent_may_have_been_submitted(): void
    {
        [$actor, $requester, , , , $site, $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();
        Operation::create([
            'site_id' => $site->id,
            'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_QUEUED,
            'idempotency_key' => $intent->original_idempotency_key,
            'requested_by' => $requester->id,
        ]);
        Sanctum::actingAs($actor);

        $this->abandon($run, 'abandon-submitted')->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::EXECUTION_EVIDENCE_PRESENT);

        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
    }

    /* ---------------------------------------------------------------------
     | Validation and state isolation
     | ------------------------------------------------------------------ */

    public function test_recovery_requires_idempotency_key_and_a_supported_action(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
            'action' => AutomationRunRecovery::ACTION_ABANDON,
            'reason' => 'No key supplied.',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');

        $this->withHeader('Idempotency-Key', 'recovery-bad-action')
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => 'retry',
                'reason' => 'Unsupported action.',
            ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');

        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
        $this->assertSame(0, AutomationRunRecovery::query()->count());
    }

    public function test_a_long_request_id_never_breaks_the_audit_write(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->withHeader('Idempotency-Key', 'recovery-long-correlation')
            ->withHeader('X-Request-ID', 'a-very-long-non-ulid-request-identifier-value')
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_ABANDON,
                'reason' => 'The automation intent was never submitted.',
            ])->assertOk();

        $audit = AuditLog::query()->where('action', 'automation_run_recovery_abandoned')->firstOrFail();
        $this->assertSame($run->id, $audit->correlation_id);
        $this->assertLessThanOrEqual(26, strlen($audit->correlation_id));
    }

    public function test_client_supplied_operation_and_scope_fields_are_rejected(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->withHeader('Idempotency-Key', 'recovery-prohibited-fields')
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_LINK,
                'operation_id' => 'client-supplied-operation',
                'status' => AutomationRun::STATUS_COMPLETED,
                'site_id' => 'client-supplied-site',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertNull($run->fresh()->operation_id);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
    }

    public function test_a_blocked_recovery_leaves_the_run_evaluating_and_can_be_retried(): void
    {
        [$actor, , , , $site, , $run] = $this->makeStranded('owner');
        DB::table('site_connections')->where('site_id', $site->id)->update(['status' => 'revoked']);
        Sanctum::actingAs($actor);

        $this->reEvaluate($run, 'reevaluate-blocked-1')->assertStatus(409);

        $recovery = AutomationRunRecovery::query()->firstOrFail();
        $this->assertSame(AutomationRunRecovery::STATE_BLOCKED, $recovery->state);
        $this->assertSame(AutomationRunRecoveryException::CONNECTION_UNAVAILABLE, $recovery->failure_reason);
        $this->assertNull($recovery->active_automation_run_id);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_blocked')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_authorized')->count());

        // The blocked attempt released the run, so a later legitimate attempt works.
        SiteConnection::query()->where('site_id', $site->id)->update(['status' => 'active']);
        $this->reEvaluate($run, 'reevaluate-blocked-2')->assertStatus(202);
        $this->assertSame(2, AutomationRunRecovery::query()->count());
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     | ------------------------------------------------------------------ */

    private function abandon(AutomationRun $run, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_ABANDON,
                'reason' => 'The automation intent was never submitted.',
            ]);
    }

    private function link(AutomationRun $run, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_LINK,
                'reason' => 'Immutable provenance already links this Operation.',
            ]);
    }

    private function reEvaluate(AutomationRun $run, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_RE_EVALUATE,
                'reason' => 'Re-evaluating the snapshotted intent.',
            ]);
    }

    /**
     * A run left stranded: `evaluating` with no linked Operation.
     *
     * @return array{0: User, 1: User, 2: OrganizationMember, 3: Organization, 4: Site, 5: Site, 6: AutomationRun}
     */
    private function makeStranded(string $roleKey = 'owner', bool $withCapability = true): array
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
        $rule = AutomationRule::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Scheduled cache clear',
            'enabled' => true,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $user->id,
        ]);
        $run = app(AutomationRunService::class)->createForOccurrence(
            $rule,
            new DateTimeImmutable('2026-10-01T10:05:00Z'),
        );
        $run = app(AutomationRunClaimService::class)->claim($run);

        if ($withCapability) {
            $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
            ConnectorCapability::create([
                'site_connection_id' => $connection->id,
                'capability_key' => AutomationRule::ACTION_CACHE_CLEAR,
                'enabled' => true,
                'reported_supported' => true,
                'reported_at' => now(),
                'discovered_at' => now(),
            ]);
        }

        return [$user, $user, $membership, $organization, $site, $site, $run];
    }

    /**
     * A run stranded in the exact production shape: OperationService already
     * persisted the Operation and its immutable origin, but the run row was
     * never updated with the link.
     *
     * @return array<int, mixed>
     */
    private function makeLinkedStranded(): array
    {
        [$actor, $user, , , $organization, $site, $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();

        $operation = app(OperationService::class)->createOperation(
            $user,
            $site,
            $intent->original_operation_type,
            $intent->original_target_json,
            $intent->original_idempotency_key,
            $run,
        );

        $this->assertNull($run->fresh()->operation_id);
        $this->assertSame(AutomationRun::STATUS_EVALUATING, $run->fresh()->status);

        return [$actor, $user, null, $organization, $site, $site, $run, $operation];
    }

    private function addMemberForRun(AutomationRun $run, string $roleKey = 'owner'): User
    {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(
            ['organization_id' => $run->organization_id, 'key' => $roleKey],
            ['name' => ucfirst($roleKey)],
        );
        OrganizationMember::create([
            'organization_id' => $run->organization_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
