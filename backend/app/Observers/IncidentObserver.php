<?php

namespace App\Observers;

use App\Models\Incident;
use App\Models\Notification;
use App\Services\NotificationEventPublisher;

class IncidentObserver
{
    public function created(Incident $incident): void
    {
        if ($incident->status === Incident::STATUS_DETECTED) {
            app(NotificationEventPublisher::class)->publish('incident', $incident->id, Notification::TYPE_INCIDENT_DETECTED);
        }
    }

    public function updated(Incident $incident): void
    {
        if (! $incident->wasChanged('status')) {
            return;
        }

        $type = match ($incident->status) {
            Incident::STATUS_DETECTED => Notification::TYPE_INCIDENT_DETECTED,
            Incident::STATUS_RESOLVED => Notification::TYPE_INCIDENT_RESOLVED,
            default => null,
        };
        if ($type !== null) {
            app(NotificationEventPublisher::class)->publish('incident', $incident->id, $type);
        }
    }
}
