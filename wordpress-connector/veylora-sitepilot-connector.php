<?php
/**
 * Plugin Name: Veylora SitePilot Connector
 * Description: A secure transport and inventory bridge between WordPress and Veylora SitePilot.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: veylora-sitepilot-connector
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('SITEPILOT_CONNECTOR_VERSION', '0.1.0');
define('SITEPILOT_CONNECTOR_FILE', __FILE__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Veylora\\SitePilotConnector\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

add_action('plugins_loaded', static function (): void {
    (new \Veylora\SitePilotConnector\Plugin())->register();
});
