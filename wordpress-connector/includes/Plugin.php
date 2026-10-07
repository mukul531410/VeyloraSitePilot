<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector;

use Veylora\SitePilotConnector\Admin\SettingsPage;
use Veylora\SitePilotConnector\Api\CapabilityReporter;
use Veylora\SitePilotConnector\Api\HeartbeatClient;
use Veylora\SitePilotConnector\Api\InventoryClient;
use Veylora\SitePilotConnector\Http\SitePilotHttpClient;
use Veylora\SitePilotConnector\Security\CredentialStore;
use Veylora\SitePilotConnector\Security\NonceGenerator;
use Veylora\SitePilotConnector\Security\SignatureBuilder;
use Veylora\SitePilotConnector\Site\ConfigStore;
use Veylora\SitePilotConnector\Site\SiteIdentity;

final class Plugin
{
    public function register(): void
    {
        if (! is_admin()) {
            return;
        }

        $config = new ConfigStore();
        $credentials = new CredentialStore();
        $client = new SitePilotHttpClient($config, $credentials, new SignatureBuilder(), new NonceGenerator());
        $identity = new SiteIdentity();

        (new SettingsPage(
            $config,
            $credentials,
            $client,
            new CapabilityReporter($client, $identity),
            new HeartbeatClient($client, $identity),
            new InventoryClient($client, $identity),
        ))->register();
    }
}
