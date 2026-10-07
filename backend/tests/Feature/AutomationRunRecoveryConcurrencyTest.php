<?php

namespace Tests\Feature;

use App\Exceptions\AutomationRunRecoveryException;
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
use App\Services\AutomationRunReconciliationService;
use App\Services\AutomationRunRecoveryService;
use App\Services\AutomationRunService;
use App\Services\OperationService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomationRunRecoveryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_duplicate_same_idempotency_key_replays_without_a_second_attempt(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $first = $this->abandon($run, 'recovery-replay-1')->assertOk();
        $second = $this->abandon($run, 'recovery-replay-1')->assertOk();

        $this->assertFalse($first->json('data.replayed'));
        $this->assertTrue($second->json('data.replayed'));
        $this->assertSame($first->json('data.recovery.id'), $second->json('data.recovery.id'));
        $this->assertSame(AutomationRunRecovery::STATE_ABANDONED, $second->json('data.recovery_state'));
        $this->assertSame(1, AutomationRunRecovery::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_abandoned')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_requested')->count());
    }

    public function test_duplicate_re_evaluate_replays_without_creating_a_second_operation(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $first = $this->reEvaluate($run, 'reevaluate-replay-1')->assertStatus(202);
        $second = $this->reEvaluate($run, 'reevaluate-replay-1')->assertStatus(202);

        $this->assertFalse($first->json('data.replayed'));
        $this->assertTrue($second->json('data.replayed'));
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(1, AutomationOperationOrigin::query()->count());
        $this->assertSame(1, AutomationRunRecovery::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_submitted')->count());
    }

    public function test_a_different_concurrent_request_conflicts_and_does_not_run(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->abandon($run, 'recovery-first-key')->assertOk();

        $this->reEvaluate($run, 'recovery-second-key')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'recovery_conflict')
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::RECOVERY_CONFLICT);

        $this->assertSame(AutomationRun::STATUS_ABANDONED, $run->fresh()->status);
        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_recovery_conflict')->count());
    }

    public function test_the_same_key_with_a_different_action_conflicts(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);

        $this->abandon($run, 'recovery-shared-key')->assertOk();

        $this->reEvaluate($run, 'recovery-shared-key')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'recovery_conflict');

        $this->assertSame(AutomationRun::STATUS_ABANDONED, $run->fresh()->status);
        $this->assertSame(1, AutomationRunRecovery::query()->count());
    }

    public function test_recovery_versus_reconciliation_locks_and_rereads_the_run(): void
    {
        [$actor, , , , , $site, $run, $operation] = $this->makeLinkedStranded();
        Sanctum::actingAs($actor);

        // Reconciling a stranded run is a no-op that must not create anything.
        $reconciler = app(AutomationRunReconciliationService::class);
        $this->assertSame('stranded_evaluating', $reconciler->reconcile($run->id)['outcome']);
        $this->assertNull($run->fresh()->operation_id);

        $this->link($run, 'recovery-vs-reconcile')->assertOk();

        // After the link commits, reconciliation mirrors the authoritative state
        // and repeated reads stay idempotent.
        $this->assertSame('unchanged', $reconciler->reconcile($run->id)['outcome']);
        $this->assertSame(AutomationRun::STATUS_SUBMITTED, $run->fresh()->status);
        $this->assertSame(Operation::STATUS_QUEUED, $operation->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'automation_run_reconciled')->count());
    }

    public function test_reconciliation_never_reopens_an_abandoned_run(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);
        $this->abandon($run, 'recovery-abandoned-reconcile')->assertOk();

        $result = app(AutomationRunReconciliationService::class)->reconcile($run->id);

        $this->assertSame('missing_operation', $result['outcome']);
        $this->assertSame(AutomationRun::STATUS_ABANDONED, $run->fresh()->status);
        $this->assertNull($run->fresh()->operation_id);
    }

    public function test_a_new_recovery_is_refused_once_a_run_is_no_longer_stranded(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        Sanctum::actingAs($actor);
        $this->abandon($run, 'recovery-terminal-1')->assertOk();

        $this->abandon($run, 'recovery-terminal-2')
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', AutomationRunRecoveryException::RECOVERY_CONFLICT);

        $this->assertSame(AutomationRun::STATUS_ABANDONED, $run->fresh()->status);
        $this->assertSame(1, AutomationRunRecovery::query()->where('action', AutomationRunRecovery::ACTION_ABANDON)->count());
    }

    public function test_service_level_conflict_does_not_persist_a_second_occupying_recovery(): void
    {
        [$actor, , , , , , $run] = $this->makeStranded('owner');
        $service = app(AutomationRunRecoveryService::class);

        $service->recover($actor, $run, AutomationRunRecovery::ACTION_ABANDON, 'First.', 'service-key-1');

        try {
            $service->recover($actor, $run, AutomationRunRecovery::ACTION_RE_EVALUATE, 'Second.', 'service-key-2');
            $this->fail('Expected a recovery conflict.');
        } catch (AutomationRunRecoveryException $exception) {
            $this->assertSame(AutomationRunRecoveryException::RECOVERY_CONFLICT, $exception->reason);
        }

        $this->assertSame(1, AutomationRunRecovery::query()->count());
        $this->assertSame(1, AutomationRunRecovery::query()->whereNotNull('active_automation_run_id')->count());
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

    private function reEvaluate(AutomationRun $run, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/automation/runs/'.$run->id.'/recover', [
                'action' => AutomationRunRecovery::ACTION_RE_EVALUATE,
                'reason' => 'Re-evaluating the snapshotted intent.',
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

    /** @return array<int, mixed> */
    private function makeStranded(string $roleKey = 'owner'): array
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

        $connection = SiteConnection::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        ConnectorCapability::create([
            'site_connection_id' => $connection->id,
            'capability_key' => AutomationRule::ACTION_CACHE_CLEAR,
            'enabled' => true,
            'reported_supported' => true,
            'reported_at' => now(),
            'discovered_at' => now(),
        ]);

        return [$user, $user, $membership, $organization, $site, $site, $run];
    }

    /** @return array<int, mixed> */
    private function makeLinkedStranded(): array
    {
        [$actor, $user, , $organization, $site, , $run] = $this->makeStranded('owner');
        $intent = AutomationRunIntent::query()->where('automation_run_id', $run->id)->firstOrFail();

        $operation = app(OperationService::class)->createOperation(
            $user,
            $site,
            $intent->original_operation_type,
            $intent->original_target_json,
            $intent->original_idempotency_key,
            $run,
        );

        return [$actor, $user, null, $organization, $site, $site, $run, $operation];
    }
}
