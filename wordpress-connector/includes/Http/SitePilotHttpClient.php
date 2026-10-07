<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Http;

use Veylora\SitePilotConnector\Security\CredentialStore;
use Veylora\SitePilotConnector\Security\NonceGenerator;
use Veylora\SitePilotConnector\Security\SignatureBuilder;
use Veylora\SitePilotConnector\Site\ConfigStore;
use Veylora\SitePilotConnector\Support\ConnectorException;

final class SitePilotHttpClient
{
    /** @var callable|null */
    private $transport;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly CredentialStore $credentials,
        private readonly SignatureBuilder $signatures,
        private readonly NonceGenerator $nonces,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /** @return array<string, mixed> */
    public function request(string $method, string $path, ?array $payload = null, string $auth = 'bearer'): array
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST'], true) || ! preg_match('#^/api/v1/connector/[a-zA-Z0-9_./{}-]+$#', $path)) {
            throw new ConnectorException('invalid_configuration');
        }

        $config = $this->config->get();
        if ($config['base_url'] === '') {
            throw new ConnectorException('invalid_configuration');
        }
        $baseUrl = ConfigStore::validatedBaseUrl($config['base_url']);
        $url = $baseUrl . $path;
        $body = $payload === null ? '' : wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            throw new ConnectorException('request_failed');
        }

        $headers = ['Accept' => 'application/json'];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($auth !== 'public') {
            $credentials = $this->credentials->get();
            if ($credentials === null || $credentials['token'] === '') {
                throw new ConnectorException('missing_credentials');
            }

            if ($auth === 'bearer') {
                $headers['Authorization'] = 'Bearer ' . $credentials['token'];
            } elseif ($auth === 'signed') {
                $timestamp = (string) time();
                $headers += $this->signatures->headers(
                    $credentials['credential_id'],
                    $credentials['credential_secret'],
                    $method,
                    $path,
                    $timestamp,
                    $this->nonces->generate(),
                    $body,
                );
            } else {
                throw new ConnectorException('invalid_configuration');
            }
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
        ];
        $response = is_callable($this->transport)
            ? ($this->transport)($url, $args)
            : wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new ConnectorException('request_failed');
        }

        $status = wp_remote_retrieve_response_code($response);
        if (! is_int($status) || $status < 200 || $status >= 300) {
            throw new ConnectorException('request_failed');
        }

        $responseBody = wp_remote_retrieve_body($response);
        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
            throw new ConnectorException('request_failed');
        }

        return $decoded['data'];
    }
}
