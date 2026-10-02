<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Operation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AutomationRunMySqlConcurrencyTest extends TestCase
{
    public function test_two_mysql_workers_serialize_reconciliation_for_the_same_run(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Run this integration test with DB_CONNECTION=mysql.');
        }

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $organization->id]);
        $rule = AutomationRule::create([
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'MySQL reconciliation concurrency test',
            'enabled' => true,
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'schedule_json' => ['every_minutes' => 5, 'starts_at_utc' => '2026-10-01T10:00:00Z'],
            'conditions_json' => null,
            'action_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'created_by' => $user->id,
        ]);
        $run = AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'occurrence_key' => 'mysql-concurrency:'.uniqid('', true),
            'status' => AutomationRun::STATUS_PENDING,
        ]);
        $run->transitionToEvaluating();
        $run->save();
        $run->refresh();
        $run->transitionTo(AutomationRun::STATUS_SUBMITTED);
        $run->save();
        $operation = Operation::create([
            'site_id' => $site->id,
            'operation_type' => AutomationRule::ACTION_CACHE_CLEAR,
            'target_json' => ['cache_type' => 'wordpress'],
            'status' => Operation::STATUS_SUCCEEDED,
            'idempotency_key' => 'automation:run:'.$run->id.':operation:v1',
            'requested_by' => $user->id,
        ]);
        $run->refresh();
        $run->operation_id = $operation->id;
        $run->save();

        $processes = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            AutomationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            foreach ([1, 2] as $_) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('artisan'),
                    'sitepilot:automation-reconcile',
                    '--run='.$run->id,
                ], base_path(), ['DB_CONNECTION' => 'mysql']);
                $process->start();
                $processes[] = $process;
            }

            $waitingWorkers = 0;
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline && $waitingWorkers < 2) {
                $waitingWorkers = collect(DB::select('SHOW FULL PROCESSLIST'))
                    ->filter(fn (object $process): bool => $process->Command === 'Execute'
                        && (int) $process->Time >= 1
                        && str_contains((string) ($process->Info ?? ''), 'automation_runs'))
                    ->count();

                if ($waitingWorkers < 2) {
                    usleep(100000);
                }
            }

            $this->assertSame(2, $waitingWorkers, 'Both worker queries must remain active while the run row is locked.');
            DB::commit();
            $transactionOpen = false;

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }

            $combinedOutput = implode("\n", array_map(fn (Process $process): string => $process->getOutput(), $processes));
            $this->assertSame(1, substr_count($combinedOutput, '"outcome":"reconciled"'));
            $this->assertSame(1, substr_count($combinedOutput, '"outcome":"unchanged"'));
            $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'automation_run_reconciled')->where('target_id', $run->id)->count());
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            DB::table('audit_logs')->where('target_id', $run->id)->delete();
            AutomationRun::query()->whereKey($run->id)->delete();
            Operation::query()->whereKey($operation->id)->delete();
            AutomationRule::query()->whereKey($rule->id)->delete();
            Site::query()->whereKey($site->id)->delete();
            Organization::query()->whereKey($organization->id)->delete();
            User::query()->whereKey($user->id)->delete();
        }
    }
}
