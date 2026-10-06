<?php

namespace App\Listeners;

use App\Events\NotificationSourceEvent;
use App\Services\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class DeliverNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function __construct(private NotificationDeliveryService $delivery) {}

    public function handle(NotificationSourceEvent $event): void
    {
        $this->delivery->deliver($event->sourceType, $event->sourceId, $event->notificationType);
    }
}
