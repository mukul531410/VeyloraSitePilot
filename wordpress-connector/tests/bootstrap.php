<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '') {}
    }
}

$GLOBALS['sitepilot_test_options'] = [];
$GLOBALS['sitepilot_test_transport'] = null;
$GLOBALS['sitepilot_test_plugins'] = [];
$GLOBALS['sitepilot_test_themes'] = [];
$GLOBALS['sitepilot_test_transients'] = [];

if (! function_exists('add_action')) {
    function add_action(...$args): void {}
}
if (! function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}
if (! function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['sitepilot_test_options'][$key] ?? $default; }
}
if (! function_exists('add_option')) {
    function add_option(string $key, mixed $value, string $deprecated = '', bool|string $autoload = true): bool
    {
        if (array_key_exists($key, $GLOBALS['sitepilot_test_options'])) return false;
        $GLOBALS['sitepilot_test_options'][$key] = $value;
        return true;
    }
}
if (! function_exists('update_option')) {
    function update_option(string $key, mixed $value, bool|string|null $autoload = null): bool
    {
        $GLOBALS['sitepilot_test_options'][$key] = $value;
        return true;
    }
}
if (! function_exists('delete_option')) {
    function delete_option(string $key): bool { unset($GLOBALS['sitepilot_test_options'][$key]); return true; }
}
if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string { return 'test-only-wordpress-salt-' . $scheme; }
}
if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
}
if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url): array|false { return parse_url($url); }
}
if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
}
if (! function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []): mixed
    {
        $transport = $GLOBALS['sitepilot_test_transport'];
        return is_callable($transport) ? $transport($url, $args) : new WP_Error('no_transport', 'No test transport');
    }
}
if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array $response): int|false { return $response['response']['code'] ?? false; }
}
if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array $response): string { return $response['body'] ?? ''; }
}
if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string { return '6.8.1'; }
}
if (! function_exists('get_plugins')) {
    function get_plugins(): array { return $GLOBALS['sitepilot_test_plugins']; }
}
if (! function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin): bool { return $plugin === 'hello/hello.php'; }
}
if (! function_exists('plugin_basename')) {
    function plugin_basename(string $file): string { return str_replace('\\', '/', $file); }
}
if (! function_exists('get_site_transient')) {
    function get_site_transient(string $key): mixed { return $GLOBALS['sitepilot_test_transients'][$key] ?? false; }
}
if (! function_exists('wp_get_themes')) {
    function wp_get_themes(): array { return $GLOBALS['sitepilot_test_themes']; }
}

require dirname(__DIR__) . '/veylora-sitepilot-connector.php';
