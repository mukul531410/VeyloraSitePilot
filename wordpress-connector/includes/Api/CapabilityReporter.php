<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Api;

use Veylora\SitePilotConnector\Http\SitePilotHttpClient;
use Veylora\SitePilotConnector\Site\SiteIdentity;

final class CapabilityReporter
{
    public function __construct(private readonly SitePilotHttpClient $client, private readonly SiteIdentity $identity) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        return $this->client->request('POST', '/api/v1/connector/capabilities/report', [
            'connector_version' => $this->identity->connectorVersion(),
            'reported_at' => $this->identity->utcTimestamp(),
            'capabilities' => [
                ['key' => 'read.wordpress', 'supported' => true],
                ['key' => 'read.plugins', 'supported' => true],
                ['key' => 'read.themes', 'supported' => true],
            ],
        ], 'signed');
    }
}
