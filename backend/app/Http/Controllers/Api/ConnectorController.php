<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ConnectorCapabilityReportRequest;
use App\Http\Requests\ConnectorHeartbeatRequest;
use App\Http\Requests\ConnectorRegisterRequest;
use App\Http\Requests\ConnectorSubmitInventoryRequest;
use App\Http\Requests\ConnectorSubmitResultRequest;
use App\Http\Requests\ConnectorSubmitStateRequest;
use App\Http\Requests\ConnectorTelemetryRequest;
use App\Models\HealthCheck;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\SiteConnection;
use App\Services\ConnectorCapabilityReportService;
use App\Services\ConnectorCredentialLifecycle;
use App\Services\InventorySubmissionService;
use App\Services\MaintenanceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ConnectorController extends BaseController
{
    public function __construct(private ConnectorCredentialLifecycle $credentials) {}

    public function register(ConnectorRegisterRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $connection = SiteConnection::query()
                ->where('connection_intent', $request->input('intent'))
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $connection || ! $connection->consumeIntent($request->input('intent'))) {
                return $this->errorResponse('Invalid or expired connection intent.', 'unauthorized', 401);
            }

            $token = $connection->activate($request->input('connector_version'));
            $issued = $this->credentials->issueInitial($connection->refresh());

            return $this->successResponse([
                'connection_id' => $connection->id,
                'status' => $connection->status,
                'token' => $token,
                'credential_id' => $issued['credential']->id,
                'credential_secret' => $issued['secret'],
            ], [], 201);
        });
    }

    public function heartbeat(ConnectorHeartbeatRequest $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $connection->heartbeats()->create([
            'connector_version' => $request->input('connector_version'),
            'wordpress_version' => $request->input('wordpress_version'),
            'php_version' => $request->input('php_version'),
            'status' => $request->input('status'),
            'reported_at' => now(),
        ]);

        $connection->update([
            'last_seen_at' => now(),
            'connector_version' => $request->input('connector_version'),
        ]);

        return $this->successResponse([
            'connection_id' => $connection->id,
            'status' => $connection->status,
        ]);
    }

    public function capabilities(Request $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $capabilities = $connection->capabilities()->orderBy('capability_key')->get();

        return $this->successResponse(
            $capabilities->map(fn ($cap) => [
                'capability_key' => $cap->capability_key,
                'enabled' => $cap->enabled,
                'discovered_at' => $cap->discovered_at?->toIso8601String(),
                'reported_supported' => $cap->reported_supported,
                'reported_at' => $cap->reported_at?->toIso8601String(),
                'effective' => $cap->isEffective(),
            ])->values()->all()
        );
    }

    public function reportCapabilities(
        ConnectorCapabilityReportRequest $request,
        ConnectorCapabilityReportService $reports,
    ) {
        $credential = $request->attributes->get('connector_credential');
        $connection = SiteConnection::query()->findOrFail($credential->siteConnectionId);
        $report = $request->report();

        $connection = $reports->report(
            $connection,
            $report['connector_version'],
            $report['reported_at'],
            $report['capabilities'],
        );

        return $this->successResponse([
            'connection_id' => $connection->id,
            'connector_version' => $connection->connector_version,
            'reported_at' => $report['reported_at'],
        ]);
    }

    public function telemetry(ConnectorTelemetryRequest $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $siteId = $connection->site_id;
        $now = now();

        foreach ($request->input('observations') as $observation) {
            HealthCheck::create([
                'site_id' => $siteId,
                'check_type' => $observation['check_type'],
                'status' => $observation['status'],
                'value_json' => $observation['value'] ?? [],
                'checked_at' => $observation['checked_at'] ?? $now,
            ]);
        }

        \App\Jobs\ProcessHealthCheck::dispatch($siteId)
            ->delay(now()->addSeconds(5));

        return $this->successResponse([
            'connection_id' => $connection->id,
            'observations_stored' => count($request->input('observations')),
        ]);
    }

    public function inventory(ConnectorSubmitInventoryRequest $request, InventorySubmissionService $inventoryService)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $submission = $inventoryService->submit($connection, $request->validated());

        if (isset($submission['error'])) {
            return $this->errorResponse(...$submission['error']);
        }

        $snapshot = $submission['snapshot'];

        return $this->successResponse([
            'connection_id' => $connection->id,
            'site_id' => $snapshot->site_id,
            'inventory_snapshot_id' => $snapshot->id,
            'checksum' => $snapshot->checksum,
            'status' => $snapshot->status,
            'completed_at' => $snapshot->completed_at?->toIso8601String(),
            'created' => $submission['created'],
        ], [], $submission['created'] ? 201 : 200);
    }

    public function jobs(Request $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $attempts = OperationAttempt::whereHas('operation.site', function ($query) use ($connection) {
            $query->where('id', $connection->site_id);
        })
        ->whereHas('operation', function ($query) {
            $query->where('operation_type', 'action.cache_clear')
                ->where('status', Operation::STATUS_RUNNING);
        })
        ->where('status', OperationAttempt::STATUS_DISPATCHED)
        ->with('operation')
        ->get();

        $jobs = $attempts->map(function ($attempt) {
            return [
                'job_id' => $attempt->connector_job_id,
                'operation_id' => $attempt->operation_id,
                'attempt_number' => $attempt->attempt_number,
                'operation_type' => $attempt->operation->operation_type,
                'target_json' => $attempt->operation->target_json,
                'idempotency_key' => $attempt->operation->idempotency_key,
                'timeout_seconds' => 120,
                'expires_at' => $attempt->timeout_at?->toIso8601String(),
            ];
        })->values()->all();

        return $this->successResponse($jobs);
    }

    public function claimJob(Request $request, string $jobId)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');
        $lock = null;

        try {
            $response = DB::transaction(function () use ($jobId, $connection, &$lock) {
                $attempt = OperationAttempt::where('connector_job_id', $jobId)
                    ->whereHas('operation.site', function ($query) use ($connection) {
                        $query->where('id', $connection->site_id);
                    })
                    ->whereHas('operation', function ($query) {
                        $query->where('operation_type', 'action.cache_clear');
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $attempt) {
                    return $this->errorResponse('Job not found', 'not_found', 404);
                }

                $capability = $connection->capabilities()
                    ->where('capability_key', 'action.cache_clear')
                    ->first();

                if (! $capability?->isEffective()) {
                    return $this->errorResponse('Capability not granted', 'capability_denied', 403);
                }

                $lockToken = $attempt->lock_token ?? $attempt->operation_id . ':' . $attempt->attempt_number;

                if ($attempt->status !== OperationAttempt::STATUS_DISPATCHED) {
                    if ($attempt->claimed_by_connection_id === $connection->id) {
                        return $this->successResponse([
                            'job_id' => $attempt->connector_job_id,
                            'operation_id' => $attempt->operation_id,
                            'attempt_number' => $attempt->attempt_number,
                            'operation_type' => $attempt->operation->operation_type,
                            'target_json' => $attempt->operation->target_json,
                            'idempotency_key' => $attempt->operation->idempotency_key,
                            'lock_token' => $lockToken,
                        ]);
                    }

                    return $this->errorResponse('Job already claimed', 'already_claimed', 409);
                }

                $maintenanceLock = app(MaintenanceLock::class);

                $lockAcquired = $maintenanceLock->acquire(
                    $connection->site_id,
                    $attempt->operation_id,
                    $attempt->attempt_number
                );

                if (! $lockAcquired) {
                    return $this->errorResponse('Could not acquire lock', 'lock_unavailable', 409);
                }

                $lock = [$connection->site_id, $attempt->operation_id, $attempt->attempt_number];

                $attempt->update([
                    'status' => OperationAttempt::STATUS_ACCEPTED,
                    'claimed_by_connection_id' => $connection->id,
                ]);

                \App\Models\AuditLog::create([
                    'organization_id' => $connection->site->organization_id,
                    'user_id' => null,
                    'site_id' => $connection->site_id,
                    'action' => 'job_claimed',
                    'target_type' => 'operation',
                    'target_id' => $attempt->operation_id,
                    'correlation_id' => $attempt->operation_id,
                    'policy_result' => $attempt->operation->policy_result,
                    'metadata_json' => [
                        'operation_type' => 'action.cache_clear',
                        'attempt_id' => $attempt->id,
                        'attempt_number' => $attempt->attempt_number,
                        'connector_job_id' => $attempt->connector_job_id,
                        'claimed_at' => now()->toIso8601String(),
                    ],
                ]);

                return $this->successResponse([
                    'job_id' => $attempt->connector_job_id,
                    'operation_id' => $attempt->operation_id,
                    'attempt_number' => $attempt->attempt_number,
                    'operation_type' => $attempt->operation->operation_type,
                    'target_json' => $attempt->operation->target_json,
                    'idempotency_key' => $attempt->operation->idempotency_key,
                    'lock_token' => $lockToken,
                ]);
            });
        } finally {
            if ($lock !== null) {
                app(MaintenanceLock::class)->release($lock[0], $lock[1], $lock[2]);
            }
        }

        return $response;
    }

    public function submitResult(ConnectorSubmitResultRequest $request, string $jobId)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');

        $attempt = OperationAttempt::where('connector_job_id', $jobId)
            ->whereHas('operation.site', function ($query) use ($connection) {
                $query->where('id', $connection->site_id);
            })
            ->whereHas('operation', function ($query) {
                $query->where('operation_type', 'action.cache_clear');
            })
            ->first();

        if (! $attempt) {
            return $this->errorResponse('Job not found', 'not_found', 404);
        }

        $validated = $request->validated();
        $this->validateResultPayload($validated);

        if ($attempt->status === OperationAttempt::STATUS_RESULT_RECEIVED) {
            $existingResult = $attempt->result;
            if ($existingResult && $this->resultMatchesPayload($existingResult, $validated)) {
                return $this->successResponse([
                    'job_id' => $jobId,
                    'operation_id' => $attempt->operation_id,
                    'attempt_number' => $attempt->attempt_number,
                    'result_status' => $existingResult->result_status,
                    'cache_cleared_at' => $existingResult->cache_cleared_at?->toIso8601String(),
                    'cleared_types' => $existingResult->cleared_types,
                    'cache_generation' => $existingResult->cache_generation,
                    'error_code' => $existingResult->error_code,
                    'error_message' => $existingResult->error_message,
                ]);
            }

            return $this->errorResponse('Result already received', 'already_submitted', 409);
        }

        if (in_array($attempt->status, OperationAttempt::TERMINAL_STATUSES, true)) {
            return $this->errorResponse('Job already terminal', 'already_terminal', 409);
        }

        if ($attempt->status === OperationAttempt::STATUS_DISPATCHED) {
            return $this->errorResponse('Job not claimed', 'not_claimed', 409);
        }

        if ($attempt->status !== OperationAttempt::STATUS_ACCEPTED
            && $attempt->status !== OperationAttempt::STATUS_EXECUTING) {
            return $this->errorResponse('Job not in claimable state', 'invalid_state', 409);
        }

        $result = DB::transaction(function () use ($attempt, $connection, $validated, $jobId) {
            $lockedAttempt = OperationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($lockedAttempt->operation_id)->lockForUpdate()->firstOrFail();

            if ($lockedAttempt->status === OperationAttempt::STATUS_RESULT_RECEIVED) {
                $existingResult = $lockedAttempt->result()->first();
                if ($existingResult && $this->resultMatchesPayload($existingResult, $validated)) {
                    return $existingResult;
                }

                throw ValidationException::withMessages([
                    'status' => ['Conflicting result for an already completed job'],
                ]);
            }

            if (in_array($lockedAttempt->status, OperationAttempt::TERMINAL_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Job already terminal'],
                ]);
            }

            if ($lockedAttempt->status !== OperationAttempt::STATUS_ACCEPTED
                && $lockedAttempt->status !== OperationAttempt::STATUS_EXECUTING) {
                throw ValidationException::withMessages([
                    'status' => ['Job is not in a result-submittable state'],
                ]);
            }

            if ($operation->status !== Operation::STATUS_RUNNING) {
                throw ValidationException::withMessages([
                    'status' => ['Operation is no longer running'],
                ]);
            }

            if ($lockedAttempt->connector_job_id !== $jobId) {
                throw ValidationException::withMessages([
                    'job_id' => ['Connector job ID mismatch'],
                ]);
            }

            $result = OperationResult::create([
                'operation_id' => $lockedAttempt->operation_id,
                'operation_attempt_id' => $lockedAttempt->id,
                'connector_job_id' => $jobId,
                'verification_status' => OperationResult::VERIFICATION_PENDING,
                'result_status' => $validated['status'] === 'success' ? 'success' : 'failed',
                'cache_cleared_at' => $validated['cache_cleared_at'] ?? null,
                'cleared_types' => $validated['cleared_types'] ?? null,
                'cache_generation' => $validated['cache_generation'] ?? null,
                'error_code' => $validated['error_code'] ?? null,
                'error_message' => $validated['error_message'] ?? null,
                'result_summary' => $validated['status'] === 'success' ? 'Cleared' : 'Failed',
            ]);

            $lockedAttempt->update([
                'status' => OperationAttempt::STATUS_RESULT_RECEIVED,
                'finished_at' => now(),
            ]);

            $operation->update([
                'status' => Operation::STATUS_VERIFICATION_PENDING,
                'finished_at' => null,
            ]);

            \App\Models\AuditLog::create([
                'organization_id' => $connection->site->organization_id,
                'user_id' => null,
                'site_id' => $connection->site_id,
                'action' => 'result_received',
                'target_type' => 'operation',
                'target_id' => $lockedAttempt->operation_id,
                'correlation_id' => $lockedAttempt->operation_id,
                'policy_result' => $operation->policy_result,
                'metadata_json' => [
                    'operation_type' => 'action.cache_clear',
                    'attempt_id' => $lockedAttempt->id,
                    'attempt_number' => $lockedAttempt->attempt_number,
                    'connector_job_id' => $jobId,
                    'result_status' => $validated['status'],
                    'error_code' => $validated['error_code'] ?? null,
                ],
            ]);

            return $result;
        });

        return $this->successResponse([
            'job_id' => $jobId,
            'operation_id' => $attempt->operation_id,
            'attempt_number' => $attempt->attempt_number,
            'result_status' => $result->result_status,
            'cache_cleared_at' => $result->cache_cleared_at?->toIso8601String(),
            'cleared_types' => $result->cleared_types,
            'cache_generation' => $result->cache_generation,
            'error_code' => $result->error_code,
            'error_message' => $result->error_message,
        ]);
    }

    public function submitState(ConnectorSubmitStateRequest $request, string $jobId)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('connector_connection');
        $attempt = OperationAttempt::where('connector_job_id', $jobId)
            ->whereHas('operation.site', fn ($query) => $query->where('id', $connection->site_id))
            ->whereHas('operation', fn ($query) => $query->where('operation_type', 'action.cache_clear'))
            ->first();

        if (! $attempt) {
            return $this->errorResponse('Job not found', 'not_found', 404);
        }
        $state = $request->validated();
        $submission = DB::transaction(function () use ($attempt, $connection, $jobId, $state) {
            $lockedConnection = SiteConnection::whereKey($connection->id)->lockForUpdate()->first();
            if (! $lockedConnection || ! $lockedConnection->isActive()) {
                return ['error' => ['Connector is inactive or revoked', 'unauthorized', 401]];
            }
            $capability = $lockedConnection->capabilities()->where('capability_key', 'read.cache_state')->first();
            if (! $capability?->isEffective()) {
                return ['error' => ['Capability not granted', 'capability_denied', 403]];
            }
            $lockedAttempt = OperationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($lockedAttempt->operation_id)->lockForUpdate()->firstOrFail();
            $lockedResult = OperationResult::where('operation_attempt_id', $lockedAttempt->id)->lockForUpdate()->first();

            if ($lockedAttempt->connector_job_id !== $jobId) {
                return ['error' => ['Job not found', 'not_found', 404]];
            }
            if ($operation->isTerminal()) {
                return ['error' => ['Operation already terminal', 'already_terminal', 409]];
            }
            if ($lockedAttempt->claimed_by_connection_id !== $connection->id) {
                return ['error' => ['Job is not claimed by this connector', 'not_claimed', 409]];
            }
            if ($lockedAttempt->status !== OperationAttempt::STATUS_RESULT_RECEIVED) {
                return ['error' => ['Attempt is not awaiting verification state', 'invalid_state', 409]];
            }
            if ($operation->status !== Operation::STATUS_VERIFICATION_PENDING) {
                return ['error' => ['Operation is not awaiting verification', 'invalid_state', 409]];
            }
            if (! $lockedResult) {
                return ['error' => ['Connector action result is required first', 'result_required', 409]];
            }
            if ($lockedResult->verification_status !== OperationResult::VERIFICATION_PENDING) {
                return ['error' => ['Verification is not pending', 'verification_terminal', 409]];
            }
            if ($lockedResult->actual_state_json !== null) {
                if (! $this->sameState($lockedResult->actual_state_json, $state)) {
                    return ['error' => ['Conflicting authoritative state submission', 'already_submitted', 409]];
                }

                return ['created' => false];
            }

            $lockedResult->update([
                'actual_state_json' => $state,
                'verification_error' => null,
                'verified_at' => null,
            ]);

            return ['created' => true];
        });

        if (isset($submission['error'])) {
            return $this->errorResponse(...$submission['error']);
        }

        try {
            \App\Jobs\VerifyOperationAttempt::dispatch($attempt->id);
        } catch (Throwable $exception) {
            try {
                (new \Illuminate\Bus\UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class)))
                    ->release(new \App\Jobs\VerifyOperationAttempt($attempt->id));
            } catch (Throwable) {
                // A later identical submission can retry once the queue/cache is available.
            }
            report($exception);

            return $this->errorResponse('Verification could not be queued; retry the same state submission.', 'verification_enqueue_failed', 503);
        }

        return $this->successResponse(
            ['job_id' => $jobId, 'verification_status' => OperationResult::VERIFICATION_PENDING],
            [],
            $submission['created'] ? 202 : 200
        );
    }

    private function sameState(?array $stored, array $submitted): bool
    {
        if ($stored === null) {
            return false;
        }
        return json_encode($this->canonicalState($stored)) === json_encode($this->canonicalState($submitted));
    }

    private function canonicalState(array $state): array
    {
        ksort($state);
        foreach ($state as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $state[$key] = $this->canonicalState($value);
            }
        }

        return $state;
    }

    private function validateResultPayload(array $data): void
    {
        if (($data['status'] ?? null) === 'success') {
            if (empty($data['cache_cleared_at'])) {
                throw ValidationException::withMessages([
                    'cache_cleared_at' => ['Required when status is success'],
                ]);
            }
            if (empty($data['cleared_types'])) {
                throw ValidationException::withMessages([
                    'cleared_types' => ['Required when status is success'],
                ]);
            }
        }

        if (($data['status'] ?? null) === 'failed') {
            if (empty($data['error_code'])) {
                throw ValidationException::withMessages([
                    'error_code' => ['Required when status is failed'],
                ]);
            }
        }
    }

    private function resultMatchesPayload(OperationResult $result, array $data): bool
    {
        if ($result->result_status !== ($data['status'] === 'success' ? 'success' : 'failed')) {
            return false;
        }

        if ($data['status'] === 'success') {
            if (! $result->cache_cleared_at
                || ! $result->cache_cleared_at->equalTo(\Carbon\Carbon::parse($data['cache_cleared_at']))) {
                return false;
            }
            if ($result->cleared_types !== $data['cleared_types']) {
                return false;
            }
            if ($result->cache_generation !== ($data['cache_generation'] ?? null)) {
                return false;
            }
        }

        if ($data['status'] === 'failed') {
            if ($result->error_code !== $data['error_code']) {
                return false;
            }
        }

        return true;
    }
}
