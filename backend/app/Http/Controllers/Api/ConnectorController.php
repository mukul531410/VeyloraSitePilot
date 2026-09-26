<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ConnectorHeartbeatRequest;
use App\Http\Requests\ConnectorRegisterRequest;
use App\Http\Requests\ConnectorSubmitResultRequest;
use App\Http\Requests\ConnectorTelemetryRequest;
use App\Models\HealthCheck;
use App\Models\Operation;
use App\Models\OperationAttempt;
use App\Models\OperationResult;
use App\Models\SiteConnection;
use App\Services\MaintenanceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConnectorController extends BaseController
{
    public function register(ConnectorRegisterRequest $request)
    {
        $connection = SiteConnection::where('connection_intent', $request->input('intent'))
            ->where('status', 'pending')
            ->first();

        if (! $connection || ! $connection->consumeIntent($request->input('intent'))) {
            return $this->errorResponse('Invalid or expired connection intent.', 'unauthorized', 401);
        }

        return DB::transaction(function () use ($connection, $request) {
            $token = $connection->activate($request->input('connector_version'));

            return $this->successResponse([
                'connection_id' => $connection->id,
                'status' => $connection->status,
                'token' => $token,
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
            ])->values()->all()
        );
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
                    ->where('enabled', true)
                    ->first();

                if (! $capability) {
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
            $attempt->refresh();

            if ($attempt->status === OperationAttempt::STATUS_RESULT_RECEIVED) {
                $existingResult = $attempt->result()->first();
                if ($existingResult && $this->resultMatchesPayload($existingResult, $validated)) {
                    return $existingResult;
                }

                throw ValidationException::withMessages([
                    'status' => ['Conflicting result for an already completed job'],
                ]);
            }

            if (in_array($attempt->status, OperationAttempt::TERMINAL_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Job already terminal'],
                ]);
            }

            if ($attempt->connector_job_id !== $jobId) {
                throw ValidationException::withMessages([
                    'job_id' => ['Connector job ID mismatch'],
                ]);
            }

            $result = OperationResult::create([
                'operation_id' => $attempt->operation_id,
                'operation_attempt_id' => $attempt->id,
                'connector_job_id' => $jobId,
                'result_status' => $validated['status'] === 'success' ? 'success' : 'failed',
                'cache_cleared_at' => $validated['cache_cleared_at'] ?? null,
                'cleared_types' => $validated['cleared_types'] ?? null,
                'cache_generation' => $validated['cache_generation'] ?? null,
                'error_code' => $validated['error_code'] ?? null,
                'error_message' => $validated['error_message'] ?? null,
                'result_summary' => $validated['status'] === 'success' ? 'Cleared' : 'Failed',
            ]);

            $attempt->update([
                'status' => OperationAttempt::STATUS_RESULT_RECEIVED,
                'finished_at' => now(),
            ]);

            $attempt->operation->update([
                'status' => Operation::STATUS_VERIFICATION_PENDING,
                'finished_at' => null,
            ]);

            \App\Models\AuditLog::create([
                'organization_id' => $connection->site->organization_id,
                'user_id' => null,
                'site_id' => $connection->site_id,
                'action' => 'result_received',
                'target_type' => 'operation',
                'target_id' => $attempt->operation_id,
                'correlation_id' => $attempt->operation_id,
                'policy_result' => $attempt->operation->policy_result,
                'metadata_json' => [
                    'operation_type' => 'action.cache_clear',
                    'attempt_id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
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
