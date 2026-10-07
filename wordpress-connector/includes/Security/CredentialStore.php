<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Security;

use Veylora\SitePilotConnector\Support\ConnectorException;

final class CredentialStore
{
    private const OPTION = 'sitepilot_connector_credentials';

    /** @param array{token: string, credential_id: string, credential_secret: string} $credentials */
    public function save(array $credentials): void
    {
        $plain = wp_json_encode($credentials, JSON_UNESCAPED_SLASHES);
        if (! is_string($plain) || ! function_exists('openssl_encrypt')) {
            throw new ConnectorException('invalid_credentials');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, 'sitepilot-connector-v1');
        if (! is_string($ciphertext) || $tag === '') {
            throw new ConnectorException('invalid_credentials');
        }

        $stored = base64_encode($iv . $tag . $ciphertext);
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $stored, '', false);
        } else {
            update_option(self::OPTION, $stored, false);
        }
    }

    /** @return array{token: string, credential_id: string, credential_secret: string}|null */
    public function get(): ?array
    {
        $stored = get_option(self::OPTION, null);
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = base64_decode($stored, true);
        if ($decoded === false || strlen($decoded) < 29 || ! function_exists('openssl_decrypt')) {
            throw new ConnectorException('invalid_credentials');
        }

        $plain = openssl_decrypt(
            substr($decoded, 28),
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($decoded, 0, 12),
            substr($decoded, 12, 16),
            'sitepilot-connector-v1',
        );
        $credentials = is_string($plain) ? json_decode($plain, true) : null;
        if (! is_array($credentials)
            || ! is_string($credentials['token'] ?? null)
            || ! is_string($credentials['credential_id'] ?? null)
            || ! is_string($credentials['credential_secret'] ?? null)) {
            throw new ConnectorException('invalid_credentials');
        }

        return $credentials;
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    private function key(): string
    {
        if (! function_exists('wp_salt')) {
            throw new ConnectorException('invalid_credentials');
        }

        $salt = wp_salt('auth');
        if (! is_string($salt) || $salt === '') {
            throw new ConnectorException('invalid_credentials');
        }

        return hash('sha256', 'sitepilot-connector-v1|' . $salt, true);
    }
}
