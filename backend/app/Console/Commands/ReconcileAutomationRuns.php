<?php

namespace App\Console\Commands;

use App\Models\AutomationRun;
use App\Services\AutomationRunReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ReconcileAutomationRuns extends Command
{
    protected $signature = 'sitepilot:automation-reconcile {--run= : Reconcile a single run by ID}';

    protected $description = 'Reconcile linked automation runs with authoritative operation states';

    public function handle(AutomationRunReconciliationService $reconciliation): int
    {
        $results = [];

        $query = AutomationRun::query()
            ->where(function ($query): void {
                $query->whereNotNull('operation_id')
                    ->orWhere(function ($query): void {
                        $query->whereNull('operation_id')->where('status', AutomationRun::STATUS_EVALUATING);
                    });
            });

        if ($this->option('run')) {
            $query->whereKey((string) $this->option('run'));
        }

        $query->orderBy('id')
            ->chunkById(100, function (Collection $runs) use ($reconciliation, &$results): void {
                foreach ($runs as $run) {
                    $results[] = $reconciliation->reconcile($run);
                }
            });

        $this->line(json_encode(['results' => $results], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
