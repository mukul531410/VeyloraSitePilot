<?php

namespace App\Http\Middleware;

use App\Models\SiteConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConnectorAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Connector token is required.',
                    'details' => [],
                ],
                'request_id' => $request->header('X-Request-ID'),
            ], 401);
        }

        $connection = SiteConnection::where('connector_token_hash', $this->hashToken($token))
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if (! $connection) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Invalid or revoked connector token.',
                    'details' => [],
                ],
                'request_id' => $request->header('X-Request-ID'),
            ], 401);
        }

        $request->attributes->set('connector_connection', $connection);

        return $next($request);
    }

    protected function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
