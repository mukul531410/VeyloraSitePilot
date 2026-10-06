<?php

namespace App\Services;

use Illuminate\Http\Request;

final class ConnectorRequestCanonicalizer
{
    public function canonicalize(Request $request, string $timestamp, string $nonce): string
    {
        $body = strtoupper($request->getMethod()) === 'GET' ? '' : $request->getContent();

        return implode("\n", [
            'SP-HMAC-SHA256',
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }
}
