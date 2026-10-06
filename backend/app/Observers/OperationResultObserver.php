<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\OperationResult;
use App\Services\NotificationEventPublisher;

class OperationResultObserver
{
    public function updated(OperationResult $result): void
    {
        if ($result->wasChanged('verification_status')
            && $result->verification_status === OperationResult::VERIFICATION_FAILED) {
            app(NotificationEventPublisher::class)->publish('operation_result', $result->id, Notification::TYPE_OPERATION_VERIFICATION_FAILED);
        }
    }
}
