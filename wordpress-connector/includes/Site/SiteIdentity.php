<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Site;

final class SiteIdentity
{
    public function connectorVersion(): string
    {
        return defined('SITEPILOT_CONNECTOR_VERSION') ? SITEPILOT_CONNECTOR_VERSION : '0.1.0';
    }

    public function wordpressVersion(): ?string
    {
        $version = function_exists('get_bloginfo') ? get_bloginfo('version') : '';

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function utcTimestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
