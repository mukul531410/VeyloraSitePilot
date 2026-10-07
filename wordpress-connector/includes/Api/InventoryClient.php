<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Api;

use Veylora\SitePilotConnector\Http\SitePilotHttpClient;
use Veylora\SitePilotConnector\Site\SiteIdentity;
use Veylora\SitePilotConnector\Support\ConnectorException;

final class InventoryClient
{
    public function __construct(private readonly SitePilotHttpClient $client, private readonly SiteIdentity $identity) {}

    /** @return array<string, mixed> */
    public function collect(): array
    {
        $capabilities = $this->client->request('GET', '/api/v1/connector/capabilities', null, 'bearer');
        $effective = [];
        foreach ($capabilities as $capability) {
            if (is_array($capability) && ($capability['effective'] ?? false) === true) {
                $effective[(string) ($capability['capability_key'] ?? '')] = true;
            }
        }
        if (! isset($effective['read.wordpress'])) {
            throw new ConnectorException('capability_unavailable');
        }

        $startedAt = $this->identity->utcTimestamp();
        $wordpress = [
            'version' => $this->identity->wordpressVersion(),
            'php_version' => $this->identity->phpVersion(),
            'status' => 'active',
        ];
        $inventory = [
            'started_at' => $startedAt,
            'category_completeness' => ['wordpress' => true],
            'wordpress' => $wordpress,
        ];

        if (isset($effective['read.plugins'])) {
            $inventory['plugins'] = $this->plugins();
            $inventory['category_completeness']['plugins'] = true;
        }
        if (isset($effective['read.themes'])) {
            $inventory['themes'] = $this->themes();
            $inventory['category_completeness']['themes'] = true;
        }

        $inventory['completed_at'] = $this->identity->utcTimestamp();

        return $inventory;
    }

    /** @return array<string, mixed> */
    public function send(): array
    {
        return $this->client->request('POST', '/api/v1/connector/inventory', $this->collect(), 'bearer');
    }

    /** @return array<int, array<string, mixed>> */
    private function plugins(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $updates = get_site_transient('update_plugins');
        $updateResponses = is_object($updates) && isset($updates->response) && is_array($updates->response)
            ? $updates->response
            : [];
        $noUpdates = is_object($updates) && isset($updates->no_update) && is_array($updates->no_update)
            ? $updates->no_update
            : [];
        $items = [];
        foreach (is_array($plugins) ? $plugins : [] as $key => $plugin) {
            if (! is_array($plugin)) {
                continue;
            }
            $active = function_exists('is_plugin_active') && is_plugin_active((string) $key);
            $item = [
                'key' => plugin_basename((string) $key),
                'name' => (string) ($plugin['Name'] ?? plugin_basename((string) $key)),
                'version' => isset($plugin['Version']) ? (string) $plugin['Version'] : null,
                'active' => $active,
                'status' => $active ? 'active' : 'inactive',
            ];
            if (isset($updateResponses[$key])) {
                $item['update_available'] = true;
            } elseif (isset($noUpdates[$key])) {
                $item['update_available'] = false;
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function themes(): array
    {
        $updates = get_site_transient('update_themes');
        $updateResponses = is_object($updates) && isset($updates->response) && is_array($updates->response)
            ? $updates->response
            : [];
        $noUpdates = is_object($updates) && isset($updates->no_update) && is_array($updates->no_update)
            ? $updates->no_update
            : [];
        $activeStylesheet = (string) get_option('stylesheet', '');
        $items = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            if (! is_object($theme)) {
                continue;
            }
            $active = (string) $stylesheet === $activeStylesheet;
            $item = [
                'key' => (string) $stylesheet,
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'active' => $active,
                'status' => $active ? 'active' : 'inactive',
            ];
            if (isset($updateResponses[$stylesheet])) {
                $item['update_available'] = true;
            } elseif (isset($noUpdates[$stylesheet])) {
                $item['update_available'] = false;
            }
            $items[] = $item;
        }

        return $items;
    }
}
