<?php

use App\Http\Controllers\Api\ApprovalsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationRulesController;
use App\Http\Controllers\Api\AutomationRunsController;
use App\Http\Controllers\Api\AvailableUpdatesController;
use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\SiteConnectionController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteMonitoringController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'service' => 'veylora-sitepilot-api',
            ],
            'meta' => [],
            'request_id' => request()->header('X-Request-ID'),
        ]);
    });

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);

        Route::get('/sites', [SiteController::class, 'index']);
        Route::post('/sites', [SiteController::class, 'store']);
        Route::get('/sites/{site}', [SiteController::class, 'show']);
        Route::patch('/sites/{site}', [SiteController::class, 'update']);
        Route::delete('/sites/{site}', [SiteController::class, 'destroy']);

        Route::get('/sites/{site}/connections', [SiteConnectionController::class, 'index']);
        Route::post('/sites/{site}/connections', [SiteConnectionController::class, 'store']);
        Route::get('/sites/{site}/connections/{connection}', [SiteConnectionController::class, 'show']);
        Route::delete('/sites/{site}/connections/{connection}', [SiteConnectionController::class, 'destroy']);

        Route::get('/sites/{site}/health', [SiteMonitoringController::class, 'health']);
        Route::get('/sites/{site}/inventory', [SiteMonitoringController::class, 'inventory']);
        Route::get('/sites/{site}/available-updates', [AvailableUpdatesController::class, 'index']);
        Route::get('/sites/{site}/metrics', [SiteMonitoringController::class, 'metrics']);
        Route::get('/sites/{site}/incidents', [SiteMonitoringController::class, 'incidents']);

        Route::post('/sites/{site}/operations', [OperationsController::class, 'store']);
        Route::get('/sites/{site}/operations/{operation}', [OperationsController::class, 'show']);
        Route::get('/operations', [OperationsController::class, 'index']);
        Route::get('/operations/{operation}', [OperationsController::class, 'showOperation']);
        Route::post('/operations/{operation}/cancel', [OperationsController::class, 'cancel']);
        Route::post('/operations/{operation}/retry', [OperationsController::class, 'retry']);
        Route::post('/operations/{operation}/resolve-unknown', [OperationsController::class, 'resolveUnknown']);
        Route::post('/approvals/{approval}/approve', [ApprovalsController::class, 'approve']);
        Route::post('/approvals/{approval}/reject', [ApprovalsController::class, 'reject']);

        Route::get('/notifications', [NotificationsController::class, 'index']);
        Route::get('/notifications/{notification}', [NotificationsController::class, 'show']);
        Route::post('/notifications/{notification}/read', [NotificationsController::class, 'markRead']);

        Route::get('/automation/rules', [AutomationRulesController::class, 'index']);
        Route::post('/automation/rules', [AutomationRulesController::class, 'store']);
        Route::get('/automation/rules/{rule}', [AutomationRulesController::class, 'show']);
        Route::patch('/automation/rules/{rule}', [AutomationRulesController::class, 'update']);
        Route::delete('/automation/rules/{rule}', [AutomationRulesController::class, 'destroy']);

        Route::get('/automation/runs/{run}', [AutomationRunsController::class, 'show']);
        Route::post('/automation/runs/{run}/recover', [AutomationRunsController::class, 'recover']);
    });

    Route::middleware('connector')->group(function (): void {
        Route::post('/connector/heartbeat', [ConnectorController::class, 'heartbeat']);
        Route::get('/connector/capabilities', [ConnectorController::class, 'capabilities']);
        Route::post('/connector/telemetry', [ConnectorController::class, 'telemetry']);
        Route::post('/connector/inventory', [ConnectorController::class, 'inventory']);
        Route::get('/connector/jobs', [ConnectorController::class, 'jobs']);
        Route::post('/connector/jobs/{job}/claim', [ConnectorController::class, 'claimJob']);
        Route::post('/connector/jobs/{job}/result', [ConnectorController::class, 'submitResult']);
        Route::post('/connector/jobs/{job}/state', [ConnectorController::class, 'submitState']);
    });

    Route::post('/connector/register', [ConnectorController::class, 'register']);
});
