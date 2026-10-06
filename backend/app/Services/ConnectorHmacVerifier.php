<?php

namespace App\Services;

use App\Contracts\ConnectorCredentialResolver;
use App\Data\ConnectorCredential;
use Illuminate\Http\Request;

final class ConnectorHmacVerifier
{
    public const SIGNATURE_INVALID = 'connector_signature_invalid';

    public const TIMESTAMP_EXPIRED = 'connector_timestamp_expired';

    public const UNAUTHORIZED = 'connector_unauthorized';

    public const REQUEST_REPLAYED = 'connector_request_replayed';

    public function __construct(
        private ConnectorCredentialResolver $credentials,
        private ConnectorRequestCanonicalizer $canonicalizer,
        private ConnectorRequestNonceStore $nonces,
    ) {}

    /** @return array{credential: ConnectorCredential}|array{error: string} */
    public function authenticate(Request $request): array
    {
        $credentialId = $request->header('X-SP-Credential-Id');
        $authorization = $request->header('Authorization');
        $timestamp = $request->header('X-SP-Timestamp');
        $nonce = $request->header('X-SP-Nonce');
        $signature = $request->header('X-SP-Signature');

        if (! is_string($credentialId) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $credentialId)
            || ! is_string($authorization) || ! preg_match('/^SP-HMAC ([0-9A-HJKMNP-TV-Z]{26})$/i', $authorization, $authMatch)
            || ! hash_equals(strtolower($credentialId), strtolower($authMatch[1]))) {
            return ['error' => self::UNAUTHORIZED];
        }

        $credential = $this->credentials->resolve($credentialId);
        if ($credential === null || ! hash_equals(strtolower($credential->credentialId), strtolower($credentialId))) {
            return ['error' => self::UNAUTHORIZED];
        }

        if (! is_string($timestamp) || ! preg_match('/^(0|[1-9][0-9]*)$/', $timestamp)
            || ! is_string($nonce) || ! $this->validNonce($nonce)
            || ! is_string($signature) || ! $this->validSignatureEncoding($signature)) {
            return ['error' => self::SIGNATURE_INVALID];
        }

        $timestampValue = (int) $timestamp;
        if (abs(now()->timestamp - $timestampValue) > 300) {
            return ['error' => self::TIMESTAMP_EXPIRED];
        }

        $canonical = $this->canonicalizer->canonicalize($request, $timestamp, $nonce);
        $expected = $this->base64Url(hash_hmac('sha256', $canonical, $credential->secret, true));
        if (! hash_equals($expected, $signature)) {
            return ['error' => self::SIGNATURE_INVALID];
        }

        if (! $this->nonces->reserve($credential->nonceScopeId, $nonce)) {
            return ['error' => self::REQUEST_REPLAYED];
        }

        return ['credential' => $credential];
    }

    public function sign(string $secret, string $canonical): string
    {
        return $this->base64Url(hash_hmac('sha256', $canonical, $secret, true));
    }

    private function validNonce(string $nonce): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]{22}$/', $nonce)) {
            return false;
        }

        $decoded = base64_decode(strtr($nonce, '-_', '+/').'==', true);

        return $decoded !== false && strlen($decoded) === 16 && hash_equals($this->base64Url($decoded), $nonce);
    }

    private function validSignatureEncoding(string $signature): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $signature)) {
            return false;
        }

        $decoded = base64_decode(strtr($signature, '-_', '+/').'=', true);

        return $decoded !== false && strlen($decoded) === 32 && hash_equals($this->base64Url($decoded), $signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
