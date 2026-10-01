<?php

namespace App\Observers;

use App\Jobs\DispatchOperationJob;
use App\Models\Operation;
use LogicException;

class OperationObserver
{
    public function updating(Operation $operation): void
    {
        if (! $operation->isDirty('status')) {
            return;
        }

        $previous = $operation->getOriginal('status');
        $next = $operation->status;
        if ($previous === Operation::STATUS_UNKNOWN && $next !== Operation::STATUS_UNKNOWN) {
            $resolutionStatus = match ($operation->resolution) {
                Operation::RESOLUTION_FAILED => Operation::STATUS_FAILED,
                Operation::RESOLUTION_CANCELLED => Operation::STATUS_CANCELLED,
                default => null,
            };

            if (! in_array($next, [Operation::STATUS_FAILED, Operation::STATUS_CANCELLED], true)
                || $resolutionStatus !== $next
                || $operation->resolved_at === null
                || $operation->resolved_by === null
                || trim((string) $operation->resolution_reason) === '') {
                throw new LogicException('Unknown operations may only leave unknown through the operator resolution workflow');
            }
        }

        $forbidden = [
            Operation::STATUS_UNKNOWN => [
                Operation::STATUS_QUEUED,
                Operation::STATUS_RUNNING,
                Operation::STATUS_VERIFICATION_PENDING,
                Operation::STATUS_DEAD_LETTER,
            ],
            Operation::STATUS_SUCCEEDED => [Operation::STATUS_RUNNING, Operation::STATUS_UNKNOWN],
            Operation::STATUS_FAILED => [Operation::STATUS_RUNNING],
            Operation::STATUS_DEAD_LETTER => [Operation::STATUS_RUNNING, Operation::STATUS_SUCCEEDED],
            Operation::STATUS_CANCELLED => [Operation::STATUS_RUNNING],
        ];

        if (in_array($next, $forbidden[$previous] ?? [], true)) {
            throw new LogicException("Illegal operation lifecycle transition: {$previous} -> {$next}");
        }
    }

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
