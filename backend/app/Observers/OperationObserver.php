<?php

namespace App\Observers;

use App\Models\Operation;
use App\Jobs\DispatchOperationJob;

class OperationObserver
{
    public function updated(Operation $operation): void
    {
        if ($operation->wasChanged('status')) {
            $originalStatus = $operation->getOriginal('status');
            $newStatus = $operation->status;

            if ($newStatus === Operation::STATUS_QUEUED) {
                if ($originalStatus === Operation::STATUS_PENDING_APPROVAL) {
                    DispatchOperationJob::dispatch($operation->id);
                } elseif ($originalStatus === Operation::STATUS_REQUESTED) {
                    DispatchOperationJob::dispatch($operation->id);
                }
            }
        }
    }
}