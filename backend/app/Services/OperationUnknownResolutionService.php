<?php

namespace App\Services;

use App\Exceptions\OperationUnknownResolutionException;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationUnknownResolutionService
{
    public function __construct(
        private OperationUnknownResolutionAuthorization $authorization,
        private OperationUnknownResolutionPreconditions $preconditions,
    ) {}

    public function resolve(
        User $actor,
        Operation $source,
        string $resolution,
        string $reason,
        ?string $correlationId = null,
        ?Site $expectedSite = null,
    ): Operation {
        return DB::transaction(function () use ($actor, $source, $resolution, $reason, $correlationId, $expectedSite): Operation {
            $lockedSource = Operation::query()->lockForUpdate()->find($source->getKey());
            if ($lockedSource === null) {
                throw new OperationUnknownResolutionException(OperationUnknownResolutionException::SOURCE_MISSING);
            }

            if ($lockedSource->isResolved()) {
                throw new OperationUnknownResolutionException(OperationUnknownResolutionException::ALREADY_RESOLVED);
            }

            if ($lockedSource->status !== Operation::STATUS_UNKNOWN) {
                throw new OperationUnknownResolutionException(OperationUnknownResolutionException::SOURCE_NOT_UNKNOWN);
            }

            if ($expectedSite !== null && (string) $expectedSite->getKey() !== (string) $lockedSource->site_id) {
                throw new OperationUnknownResolutionException(OperationUnknownResolutionException::WRONG_TENANT);
            }

            $this->preconditions->assess($actor, $lockedSource, $resolution, $reason, $expectedSite);

            $previousStatus = $lockedSource->status;
            $previousResolution = $lockedSource->resolution;

            $lockedSource->update([
                'status' => match ($resolution) {
                    Operation::RESOLUTION_SUCCESS => Operation::STATUS_UNKNOWN,
                    Operation::RESOLUTION_FAILED => Operation::STATUS_FAILED,
                    Operation::RESOLUTION_CANCELLED => Operation::STATUS_CANCELLED,
                    default => $lockedSource->status,
                },
                'resolution' => $resolution,
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                'resolution_reason' => trim($reason),
                'finished_at' => $lockedSource->finished_at ?? now(),
            ]);

            $correlationId ??= (string) Str::uuid();

            $finalAttempt = $lockedSource->attempts()->orderByDesc('attempt_number')->first();
            $auditMetadata = [
                'operation_id' => $lockedSource->id,
                'site_id' => $lockedSource->site_id,
                'organization_id' => $lockedSource->site->organization_id,
                'actor_id' => $actor->id,
                'previous_status' => $previousStatus,
                'previous_resolution' => $previousResolution,
                'resolution' => $resolution,
                'reason' => trim($reason),
                'attempt_number' => $finalAttempt?->attempt_number,
                'connector_job_id' => $finalAttempt?->connector_job_id,
                'correlation_id' => $correlationId,
                'resolved_at' => now('UTC')->toIso8601String(),
            ];

            AuditLog::create([
                'organization_id' => $lockedSource->site->organization_id,
                'user_id' => $actor->id,
                'site_id' => $lockedSource->site_id,
                'action' => 'operation_unknown_resolved',
                'target_type' => 'operation',
                'target_id' => $lockedSource->id,
                'correlation_id' => $correlationId,
                'policy_result' => $lockedSource->policy_result,
                'metadata_json' => $auditMetadata,
            ]);

            return $lockedSource->refresh();
        });
    }
}
