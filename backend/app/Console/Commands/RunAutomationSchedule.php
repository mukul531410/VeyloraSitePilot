<?php

namespace App\Console\Commands;

use App\Services\AutomationSchedulerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RunAutomationSchedule extends Command
{
    protected $signature = 'sitepilot:automation-schedule {--at= : Evaluation timestamp; defaults to current UTC time}';

    protected $description = 'Evaluate due automation schedule occurrences and submit authorized operations';

    public function handle(AutomationSchedulerService $scheduler): int
    {
        try {
            $evaluationTime = $this->option('at')
                ? CarbonImmutable::parse((string) $this->option('at'))->utc()
                : CarbonImmutable::now('UTC');
        } catch (Throwable) {
            $this->error('Invalid evaluation timestamp.');

            return self::INVALID;
        }

        $this->line(json_encode([
            'evaluation_time' => $evaluationTime->format('Y-m-d\TH:i:s\Z'),
            'results' => $scheduler->processDueRules($evaluationTime),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
