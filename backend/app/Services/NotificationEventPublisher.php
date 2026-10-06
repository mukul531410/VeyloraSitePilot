<?php

namespace App\Services;

use App\Events\NotificationSourceEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationEventPublisher
{
    public function publish(string $sourceType, string $sourceId, string $notificationType): void
    {
        DB::afterCommit(function () use ($sourceType, $sourceId, $notificationType): void {
            try {
                NotificationSourceEvent::dispatch($sourceType, $sourceId, $notificationType);
            } catch (\Throwable $exception) {
                Log::error('Unable to dispatch notification source event.', [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'notification_type' => $notificationType,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }
}
