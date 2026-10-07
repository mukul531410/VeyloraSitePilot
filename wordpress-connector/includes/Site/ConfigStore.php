<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Site;

use Veylora\SitePilotConnector\Support\ConnectorException;

final class ConfigStore
{
    private const OPTION = 'sitepilot_connector_config';

    /** @return array{base_url: string, connection_id: string, status: string, connector_version: string} */
    public function get(): array
    {
        $value = get_option(self::OPTION, []);
        if (! is_array($value)) {
            $value = [];
        }

        return [
            'base_url' => (string) ($value['base_url'] ?? ''),
            'connection_id' => (string) ($value['connection_id'] ?? ''),
            'status' => (string) ($value['status'] ?? 'disconnected'),
            'connector_version' => defined('SITEPILOT_CONNECTOR_VERSION') ? SITEPILOT_CONNECTOR_VERSION : '0.1.0',
        ];
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $baseUrl = self::validatedBaseUrl($baseUrl);
        $config = $this->get();
        $config['base_url'] = $baseUrl;
        $this->save($config);
    }

    public function setConnection(string $connectionId, string $status): void
    {
        $config = $this->get();
        $config['connection_id'] = sanitize_text_field($connectionId);
        $config['status'] = $status === 'active' ? 'active' : 'disconnected';
        $this->save($config);
    }

    public static function validatedBaseUrl(string $baseUrl): string
    {
        $parts = wp_parse_url(trim($baseUrl));
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new ConnectorException('invalid_configuration');
        }

        $host = strtolower($parts['host']);
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (($parts['scheme'] !== 'https' && ! $isLocal) || ($parts['scheme'] === 'http' && ! $isLocal)) {
            throw new ConnectorException('invalid_configuration');
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . $parts['host'] . $port;
    }

    /** @param array<string, string> $config */
    private function save(array $config): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $config, '', false);
        } else {
            update_option(self::OPTION, $config, false);
        }
    }
}
