<?php

namespace App\Services;

use App\Exceptions\OperationCancellationException;
use App\Exceptions\OperationUnknownResolutionException;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;

/**
 * Cancellation is an operator disposition on an operation, so it reuses the
 * existing operation-level owner/admin authorization rather than introducing a
 * second authorization system. `OperationUnknownResolutionAuthorization` is the
 * canonical active-owner/admin check for an Operation and returns exactly the
 * reason vocabulary needed here, so its verdict is translated instead of copied.
 */
class OperationCancellationAuthorization
{
    public const PERMISSION = 'site.cancel_operation';

    public function __construct(
        private OperationUnknownResolutionAuthorization $authorization,
    ) {}

    public function canCancel(?User $actor, Operation $operation, ?Site $expectedSite = null): bool
    {
        return $this->failureReason($actor, $operation, $expectedSite) === null;
    }

    public function authorize(?User $actor, Operation $operation, ?Site $expectedSite = null): void
    {
        $reason = $this->failureReason($actor, $operation, $expectedSite);

        if ($reason !== null) {
            throw new OperationCancellationException($reason);
        }
    }

    public function failureReason(?User $actor, Operation $operation, ?Site $expectedSite = null): ?string
    {
        try {
            $this->authorization->authorize($actor, $operation, $expectedSite);
        } catch (OperationUnknownResolutionException $exception) {
            return match ($exception->reason) {
                OperationUnknownResolutionException::SOURCE_MISSING => OperationCancellationException::OPERATION_MISSING,
                default => $exception->reason,
            };
        }

        return null;
    }
}
