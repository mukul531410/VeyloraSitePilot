<?php

namespace App\Observers;

use App\Models\AvailableUpdate;
use App\Models\Notification;
use App\Services\NotificationEventPublisher;

class AvailableUpdateObserver
{
    public function created(AvailableUpdate $finding): void
    {
        if ($finding->status === AvailableUpdate::STATUS_OPEN) {
            app(NotificationEventPublisher::class)->publish('available_update', $finding->id, Notification::TYPE_AVAILABLE_UPDATE_DETECTED);
        }
    }

    public function updated(AvailableUpdate $finding): void
    {
        if ($finding->wasChanged('status') && $finding->status === AvailableUpdate::STATUS_OPEN) {
            app(NotificationEventPublisher::class)->publish('available_update', $finding->id, Notification::TYPE_AVAILABLE_UPDATE_DETECTED);
        }
    }
}
