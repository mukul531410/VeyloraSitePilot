<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Admin;

use Veylora\SitePilotConnector\Api\CapabilityReporter;
use Veylora\SitePilotConnector\Api\HeartbeatClient;
use Veylora\SitePilotConnector\Api\InventoryClient;
use Veylora\SitePilotConnector\Http\SitePilotHttpClient;
use Veylora\SitePilotConnector\Security\CredentialStore;
use Veylora\SitePilotConnector\Site\ConfigStore;
use Veylora\SitePilotConnector\Support\ConnectorException;

final class SettingsPage
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly CredentialStore $credentials,
        private readonly SitePilotHttpClient $client,
        private readonly CapabilityReporter $capabilities,
        private readonly HeartbeatClient $heartbeat,
        private readonly InventoryClient $inventory,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_sitepilot_connector_connect', [$this, 'connect']);
        add_action('admin_post_sitepilot_connector_sync', [$this, 'sync']);
    }

    public function menu(): void
    {
        add_options_page(
            'SitePilot Connector',
            'SitePilot Connector',
            'manage_options',
            'sitepilot-connector',
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $config = $this->config->get();
        $notice = isset($_GET['sitepilot_notice']) ? sanitize_key(wp_unslash($_GET['sitepilot_notice'])) : '';
        $messages = [
            'connected' => 'SitePilot connection established. Capability support was reported.',
            'connected_sync_pending' => 'Connection established. Initial reporting could not complete; use Sync to retry.',
            'synced' => 'Heartbeat and available inventory were sent.',
            'sync_failed' => 'Sync could not complete. Check the SitePilot URL, connection, and granted read capabilities.',
            'connect_failed' => 'Connection could not be established. Check the SitePilot URL and one-time intent.',
        ];
        ?>
        <div class="wrap">
            <h1>SitePilot Connector</h1>
            <?php if (isset($messages[$notice])) : ?>
                <div class="notice notice-<?php echo in_array($notice, ['connected', 'synced'], true) ? 'success' : 'error'; ?>"><p><?php echo esc_html($messages[$notice]); ?></p></div>
            <?php endif; ?>
            <p>Status: <strong><?php echo esc_html($config['status']); ?></strong></p>
            <?php if ($config['connection_id'] !== '') : ?>
                <p>Connection ID: <code><?php echo esc_html($config['connection_id']); ?></code></p>
                <p>Connector version: <code><?php echo esc_html($config['connector_version']); ?></code></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sitepilot_connector_sync">
                    <?php wp_nonce_field('sitepilot_connector_sync'); ?>
                    <?php submit_button('Send capability report, heartbeat, and available inventory'); ?>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sitepilot_connector_connect">
                    <?php wp_nonce_field('sitepilot_connector_connect'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="sitepilot_base_url">SitePilot base URL</label></th>
                            <td><input class="regular-text" type="url" id="sitepilot_base_url" name="base_url" value="<?php echo esc_attr($config['base_url']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sitepilot_intent">One-time connection intent</label></th>
                            <td><input class="regular-text" type="password" id="sitepilot_intent" name="intent" autocomplete="new-password" required></td>
                        </tr>
                    </table>
                    <?php submit_button('Connect to SitePilot'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function connect(): void
    {
        $this->authorizePost('sitepilot_connector_connect');
        try {
            $baseUrl = ConfigStore::validatedBaseUrl(sanitize_text_field(wp_unslash($_POST['base_url'] ?? '')));
            $intent = sanitize_text_field(wp_unslash($_POST['intent'] ?? ''));
            if ($intent === '') {
                throw new ConnectorException('invalid_configuration');
            }
            $this->config->setBaseUrl($baseUrl);
            $data = $this->client->request('POST', '/api/v1/connector/register', [
                'intent' => $intent,
                'connector_version' => defined('SITEPILOT_CONNECTOR_VERSION') ? SITEPILOT_CONNECTOR_VERSION : '0.1.0',
            ], 'public');
            foreach (['connection_id', 'status', 'token', 'credential_id', 'credential_secret'] as $key) {
                if (! is_string($data[$key] ?? null) || $data[$key] === '') {
                    throw new ConnectorException('request_failed');
                }
            }

            $this->credentials->save([
                'token' => $data['token'],
                'credential_id' => $data['credential_id'],
                'credential_secret' => $data['credential_secret'],
            ]);
            $this->config->setConnection($data['connection_id'], $data['status']);
            $this->capabilities->report();
            $this->heartbeat->send();
            $this->redirect('connected');
        } catch (\Throwable) {
            $this->redirect($this->config->get()['connection_id'] !== '' ? 'connected_sync_pending' : 'connect_failed');
        }
    }

    public function sync(): void
    {
        $this->authorizePost('sitepilot_connector_sync');
        try {
            $this->capabilities->report();
            $this->heartbeat->send();
            $this->inventory->send();
            $this->redirect('synced');
        } catch (\Throwable) {
            $this->redirect('sync_failed');
        }
    }

    private function authorizePost(string $nonceAction): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage this connector.', 'veylora-sitepilot-connector'));
        }
        check_admin_referer($nonceAction);
    }

    private function redirect(string $notice): never
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'sitepilot-connector',
            'sitepilot_notice' => $notice,
        ], admin_url('options-general.php')));
        exit;
    }
}
