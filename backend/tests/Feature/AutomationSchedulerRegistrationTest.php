<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileAutomationRuns;
use App\Console\Commands\RunAutomationSchedule;
use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the documented boundary (docs/17 + docs/18 + docs/09 Checkpoint C):
 * the automation schedule/reconcile commands must NOT be registered as
 * recurring scheduled tasks, while the existing operation and monitoring
 * schedules must remain unchanged.
 *
 * Laravel 13 does not expose Schedule::fake()/assertNotScheduled(), so this
 * test reflects the application's real schedule definition (routes/console.php
 * is already loaded into the Schedule singleton by the test harness, and
 * App\Console\Kernel::schedule() is invoked explicitly) and inspects the
 * resulting events directly.
 */
class AutomationSchedulerRegistrationTest extends TestCase
{
    /**
     * The two automation command signatures that must never be scheduled.
     *
     * @var array<int, string>
     */
    private const AUTOMATION_COMMANDS = [
        'sitepilot:automation-schedule',
        'sitepilot:automation-reconcile',
    ];

    /**
     * Collect the descriptions of every scheduled event, from both the
     * application Schedule singleton and the explicitly-invoked kernel
     * schedule definition.
     *
     * @return array<int, string>
     */
    private function scheduledDescriptions(): array
    {
        /** @var Schedule $singleton */
        $singleton = $this->app->make(Schedule::class);

        $descriptions = [];
        foreach ($singleton->events() as $event) {
            $descriptions[] = (string) ($event->description ?? '');
        }

        $kernelSchedule = new Schedule;
        $kernel = $this->app->make(Kernel::class);
        $schedule = new ReflectionMethod($kernel, 'schedule');
        $schedule->setAccessible(true);
        $schedule->invoke($kernel, $kernelSchedule);

        foreach ($kernelSchedule->events() as $event) {
            $descriptions[] = (string) ($event->description ?? '');
        }

        return $descriptions;
    }

    public function test_automation_commands_are_not_registered_as_scheduled_tasks(): void
    {
        $descriptions = $this->scheduledDescriptions();

        foreach (self::AUTOMATION_COMMANDS as $command) {
            $this->assertNotContains(
                $command,
                $descriptions,
                "Automation command [{$command}] must not be registered as a recurring scheduled task.",
            );
        }

        // Also confirm the command classes themselves are not referenced.
        $haystack = implode(' ', $descriptions);
        $this->assertStringNotContainsString(RunAutomationSchedule::class, $haystack);
        $this->assertStringNotContainsString(ReconcileAutomationRuns::class, $haystack);
    }

    public function test_operation_schedules_remain_registered(): void
    {
        $descriptions = $this->scheduledDescriptions();

        $this->assertContains('App\Jobs\DispatchStaleQueuedOperations', $descriptions);
        $this->assertContains('App\Jobs\DetectExpiredOperationAttempts', $descriptions);
    }

    public function test_monitoring_schedule_remains_registered(): void
    {
        /** @var Schedule $singleton */
        $singleton = $this->app->make(Schedule::class);

        $descriptions = [];
        foreach ($singleton->events() as $event) {
            $descriptions[] = (string) ($event->description ?? '');
        }

        $this->assertContains('monitoring.health-and-uptime', $descriptions);
    }

    public function test_no_scheduled_event_references_automation_commands(): void
    {
        /** @var Schedule $singleton */
        $singleton = $this->app->make(Schedule::class);

        $kernelSchedule = new Schedule;
        $kernel = $this->app->make(Kernel::class);
        $schedule = new ReflectionMethod($kernel, 'schedule');
        $schedule->setAccessible(true);
        $schedule->invoke($kernel, $kernelSchedule);

        $events = array_merge($singleton->events(), $kernelSchedule->events());
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $description = (string) ($event->description ?? '');
            foreach (self::AUTOMATION_COMMANDS as $command) {
                $this->assertStringNotContainsString(
                    $command,
                    $description,
                    "Scheduled event [{$description}] must not reference automation command [{$command}].",
                );
            }
        }
    }
}
