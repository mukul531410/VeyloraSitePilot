<?php

namespace App\Services;

use App\Exceptions\OperationCancellationException;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;

/**
 * Cancellation is only legal before the operation leaves the server's control.
 *
 * Once an operation is `running` or `verification_pending` a connector may have
 * already claimed the job and be executing a real remote action. There is no
 * connector-side cancellation capability, so marking such an operation cancelled
 * would assert an outcome the system cannot know and would cause the connector's
 * later `/result` submission to be rejected as `already_terminal`, discarding real
 * evidence. The architecture's answer for an uncertain remote outcome is the
 * existing `unknown` state followed by operator `resolve-unknown`, so in-flight
 * operations are refused here rather than given invented semantics.
 *
 * A `queued` operation is safe: `DispatchOperationJob` re-reads the operation
 * under lock and returns early when it is terminal or no longer `queued`, so an
 * in-flight dispatch cannot resurrect a cancelled operation.
 */
class OperationCancellationPreconditions
{
    /**
     * States with no connector work in flight and no remote action started.
     */
    public const CANCELLABLE_STATUSES = [
        Operation::STATUS_REQUESTED,
        Operation::STATUS_PENDING_APPROVAL,
        Operation::STATUS_APPROVED,
        Operation::STATUS_QUEUED,
    ];

    public function __construct(
        private OperationCancellationAuthorization $authorization,
    ) {}

    public function assess(?User $actor, Operation $operation, ?string $reason, ?Site $expectedSite = null): void
    {
        $this->authorization->authorize($actor, $operation, $expectedSite);

        $source = Operation::query()->with(['site.organization'])->find($operation->getKey());

        if ($source === null) {
            throw new OperationCancellationException(OperationCancellationException::OPERATION_MISSING);
        }

        if ($source->status === Operation::STATUS_CANCELLED) {
            throw new OperationCancellationException(OperationCancellationException::ALREADY_CANCELLED);
        }

        if (trim((string) $reason) === '') {
            throw new OperationCancellationException(OperationCancellationException::MISSING_REASON);
        }

        if ($source->isTerminal()) {
            throw new OperationCancellationException(OperationCancellationException::OPERATION_TERMINAL);
        }

        if (! in_array($source->status, self::CANCELLABLE_STATUSES, true)) {
            throw new OperationCancellationException(OperationCancellationException::OPERATION_IN_FLIGHT);
        }

        // A pending approval request already has a working cancellation path:
        // rejecting it moves the operation to `cancelled` through the existing
        // `OperationService::rejectOperation()`. Refusing here keeps approval
        // semantics in one place instead of duplicating them.
        $approval = $source->approvalRequest()->first();
        if ($approval !== null && $approval->isPending()) {
            throw new OperationCancellationException(OperationCancellationException::OPERATION_APPROVAL_PENDING);
        }
    }
}
