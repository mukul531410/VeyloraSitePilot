<?php

namespace App\Http\Middleware;

use App\Services\ConnectorHmacVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConnectorSignedAuth
{
    public function __construct(private ConnectorHmacVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->verifier->authenticate($request);
        if (isset($result['error'])) {
            $code = $result['error'];
            $status = $code === ConnectorHmacVerifier::REQUEST_REPLAYED ? 409 : 401;

            return new JsonResponse([
                'error' => [
                    'code' => $code,
                    'message' => match ($code) {
                        ConnectorHmacVerifier::TIMESTAMP_EXPIRED => 'Connector request timestamp is outside the allowed window.',
                        ConnectorHmacVerifier::UNAUTHORIZED => 'Connector credential is not authorized.',
                        ConnectorHmacVerifier::REQUEST_REPLAYED => 'Connector request nonce has already been used.',
                        default => 'Connector request signature is invalid.',
                    },
                    'details' => (object) [],
                ],
                'request_id' => $request->header('X-Request-ID'),
            ], $status);
        }

        $request->attributes->set('connector_credential', $result['credential']);

        return $next($request);
    }
}
