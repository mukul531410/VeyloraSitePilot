<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Security;

use Veylora\SitePilotConnector\Support\ConnectorException;

final class SignatureBuilder
{
    public function canonicalInput(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            'SP-HMAC-SHA256',
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', strtoupper($method) === 'GET' ? '' : $body),
        ]);
    }

    /** @return array<string, string> */
    public function headers(
        string $credentialId,
        string $encodedSecret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): array {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $credentialId)) {
            throw new ConnectorException('invalid_credentials');
        }

        $secret = base64_decode($encodedSecret, true);
        if ($secret === false || strlen($secret) !== 32) {
            throw new ConnectorException('invalid_credentials');
        }

        $canonical = $this->canonicalInput($method, $path, $timestamp, $nonce, $body);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');

        return [
            'X-SP-Credential-Id' => $credentialId,
            'X-SP-Timestamp' => $timestamp,
            'X-SP-Nonce' => $nonce,
            'X-SP-Signature' => $signature,
            'Authorization' => 'SP-HMAC ' . $credentialId,
        ];
    }
}
