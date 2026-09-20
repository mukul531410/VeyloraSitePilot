<?php

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
});
