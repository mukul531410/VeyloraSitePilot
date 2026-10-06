<?php

namespace App\Observers;

use App\Models\AutomationRun;
use App\Models\Notification;
use App\Services\NotificationEventPublisher;

class AutomationRunObserver
{
    public function updated(AutomationRun $run): void
    {
        if ($run->wasChanged('status') && $run->status === AutomationRun::STATUS_FAILED) {
            app(NotificationEventPublisher::class)->publish('automation_run', $run->id, Notification::TYPE_AUTOMATION_FAILED);
        }
    }
}
