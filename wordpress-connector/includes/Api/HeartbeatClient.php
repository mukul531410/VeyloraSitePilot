<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Api;

use Veylora\SitePilotConnector\Http\SitePilotHttpClient;
use Veylora\SitePilotConnector\Site\SiteIdentity;

final class HeartbeatClient
{
    public function __construct(private readonly SitePilotHttpClient $client, private readonly SiteIdentity $identity) {}

    /** @return array<string, mixed> */
    public function send(): array
    {
        return $this->client->request('POST', '/api/v1/connector/heartbeat', [
            'connector_version' => $this->identity->connectorVersion(),
            'wordpress_version' => $this->identity->wordpressVersion(),
            'php_version' => $this->identity->phpVersion(),
            'status' => 'online',
        ], 'bearer');
    }
}
