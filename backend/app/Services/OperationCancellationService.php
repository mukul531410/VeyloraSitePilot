<?php

namespace App\Services;

use App\Exceptions\OperationCancellationException;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationCancellationService
{
    public const AUDIT_CANCELLED = 'operation_cancelled';

    public function __construct(
        private OperationCancellationPreconditions $preconditions,
    ) {}

    public function cancel(
        User $actor,
        Operation $source,
        string $reason,
        ?string $correlationId = null,
        ?Site $expectedSite = null,
    ): Operation {
        return DB::transaction(function () use ($actor, $source, $reason, $correlationId, $expectedSite): Operation {
            $locked = Operation::query()->lockForUpdate()->find($source->getKey());

            if ($locked === null) {
                throw new OperationCancellationException(OperationCancellationException::OPERATION_MISSING);
            }

            $this->preconditions->assess($actor, $locked, $reason, $expectedSite);

            $previousStatus = $locked->status;
            $trimmedReason = trim($reason);

            $locked->update([
                'status' => Operation::STATUS_CANCELLED,
                'finished_at' => $locked->finished_at ?? now(),
            ]);

            // ULID, not a UUID: audit_logs.correlation_id is char(26).
            $correlationId = $this->correlationIdFor($correlationId);

            AuditLog::create([
                'organization_id' => $locked->site->organization_id,
                'user_id' => $actor->id,
                'site_id' => $locked->site_id,
                'action' => self::AUDIT_CANCELLED,
                'target_type' => 'operation',
                'target_id' => $locked->id,
                'correlation_id' => $correlationId,
                'policy_result' => $locked->policy_result,
                'before_json' => ['status' => $previousStatus],
                'after_json' => ['status' => Operation::STATUS_CANCELLED],
                'metadata_json' => [
                    'operation_id' => $locked->id,
                    'site_id' => $locked->site_id,
                    'organization_id' => $locked->site->organization_id,
                    'operation_type' => $locked->operation_type,
                    'actor_id' => $actor->id,
                    'previous_status' => $previousStatus,
                    'status' => Operation::STATUS_CANCELLED,
                    'reason' => $trimmedReason,
                    'requested_by' => $locked->requested_by,
                    'connector_dispatch_claimed' => false,
                    'cancelled_at' => now('UTC')->toIso8601String(),
                ],
            ]);

            return $locked->refresh();
        });
    }

    /**
     * `audit_logs.correlation_id` is a char(26) ULID, but the caller-supplied
     * `X-Request-ID` is untrusted and unbounded. An oversized value is rejected
     * here rather than being allowed to fail the audit insert on MySQL, which is
     * the same guard the automation recovery workflow applies.
     */
    private function correlationIdFor(?string $correlationId): string
    {
        $correlationId = $correlationId === null ? '' : trim($correlationId);

        if ($correlationId !== '' && strlen($correlationId) <= 26) {
            return $correlationId;
        }

        return Str::ulid()->toString();
    }
}
