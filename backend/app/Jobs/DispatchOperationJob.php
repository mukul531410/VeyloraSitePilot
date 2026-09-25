<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\ConnectorCapability;
use App\Services\MaintenanceLock;
use App\Services\PolicyEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DispatchOperationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public string $operationId,
    ) {}

    public function uniqueId(): string
    {
        return 'dispatch-operation:' . $this->operationId;
    }

    public function handle(
        MaintenanceLock $maintenanceLock,
        PolicyEngine $policyEngine,
    ): void {
        DB::transaction(function () use ($maintenanceLock, $policyEngine) {
            $operation = Operation::with(['site', 'site.organization'])
                ->whereKey($this->operationId)
                ->firstOrFail();

            if ($operation->operation_type !== 'action.cache_clear') {
                throw new RuntimeException('Unsupported operation type: ' . $operation->operation_type);
            }

            if ($operation->status !== Operation::STATUS_QUEUED) {
                return;
            }

            $site = $operation->site;

            $connection = $this->getActiveConnection($site);
            if (! $connection) {
                throw new RuntimeException('No active site connection');
            }

            $capability = $this->getCapability($connection, $operation->operation_type);
            if (! $capability || ! $capability->enabled) {
                throw new RuntimeException('Capability not granted: ' . $operation->operation_type);
            }

            $attemptNumber = $operation->attempts()->max('attempt_number') ?? 0;
            $attemptNumber++;

            $connectorJobId = (string) Str::ulid();
            $lockToken = $operation->id . ':' . $attemptNumber;

            $lockAcquired = $maintenanceLock->acquire($site->id, $operation->id, $attemptNumber);
            if (! $lockAcquired) {
                throw new RuntimeException('Could not acquire maintenance lock for site ' . $site->id);
            }

            $attempt = OperationAttempt::create([
                'operation_id' => $operation->id,
                'attempt_number' => $attemptNumber,
                'status' => OperationAttempt::STATUS_DISPATCHED,
                'connector_job_id' => $connectorJobId,
                'lock_token' => $lockToken,
                'started_at' => now(),
                'timeout_at' => now()->addMinutes(2),
            ]);

            $operation->update([
                'status' => Operation::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            $this->auditLog($operation, 'attempt_dispatched', [
                'operation_id' => $operation->id,
                'attempt_id' => $attempt->id,
                'attempt_number' => $attemptNumber,
                'connector_job_id' => $connectorJobId,
                'lock_acquired' => true,
            ]);
        });
    }

    private function getActiveConnection(Site $site): ?SiteConnection
    {
        return $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }

    private function getCapability(SiteConnection $connection, string $operationType): ?ConnectorCapability
    {
        return $connection->capabilities()
            ->where('capability_key', $operationType)
            ->where('enabled', true)
            ->first();
    }

    private function auditLog(Operation $operation, string $action, array $metadata): void
    {
        \App\Models\AuditLog::create([
            'organization_id' => $operation->site->organization_id,
            'user_id' => $operation->requested_by,
            'site_id' => $operation->site_id,
            'action' => $action,
            'target_type' => 'operation',
            'target_id' => $operation->id,
            'correlation_id' => $operation->id,
            'policy_result' => $operation->policy_result,
            'metadata_json' => array_merge(['operation_type' => $operation->operation_type], $metadata),
        ]);
    }
}