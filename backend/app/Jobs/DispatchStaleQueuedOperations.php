<?php

namespace App\Jobs;

use App\Models\Operation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DispatchStaleQueuedOperations implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $operations = Operation::where('status', Operation::STATUS_QUEUED)
            ->whereNull('started_at')
            ->where('operation_type', 'action.cache_clear')
            ->get();

        foreach ($operations as $operation) {
            DispatchOperationJob::dispatch($operation->id);
        }
    }
}