<?php

namespace App\Observers;

use App\Models\ApprovalRequest;
use App\Models\Notification;
use App\Services\NotificationEventPublisher;

class ApprovalRequestObserver
{
    public function created(ApprovalRequest $approval): void
    {
        if ($approval->status === ApprovalRequest::STATUS_PENDING) {
            app(NotificationEventPublisher::class)->publish('approval_request', $approval->id, Notification::TYPE_APPROVAL_REQUESTED);
        }
    }
}
