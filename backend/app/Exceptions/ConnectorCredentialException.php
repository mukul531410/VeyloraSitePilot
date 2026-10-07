<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ConnectorCredentialException extends RuntimeException
{
    public const UNAUTHORIZED = 'connector_unauthorized';

    public const VERSION_CONFLICT = 'credential_version_conflict';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function render(Request $request): JsonResponse
    {
        $conflict = $this->reason === self::VERSION_CONFLICT;

        return response()->json([
            'error' => [
                'code' => $this->reason,
                'message' => $conflict
                    ? 'The connector credential version is stale.'
                    : 'Connector credential is not authorized.',
                'details' => (object) [],
            ],
            'request_id' => $request->header('X-Request-ID'),
        ], $conflict ? 409 : 401);
    }
}
