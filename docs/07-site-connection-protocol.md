# Veylora SitePilot — Site Connection Protocol

## Objective

Create a secure, revocable relationship between one SitePilot site record and one WordPress installation.

## Connection lifecycle

1. User creates a site in SitePilot.
2. SitePilot generates a one-time connection intent.
3. User installs the connector on the WordPress site.
4. Connector presents the connection intent.
5. SitePilot validates the intent.
6. Connector and SitePilot establish credentials.
7. Site receives a unique connection identity.
8. Capability discovery runs.
9. Initial inventory sync runs.
10. Connection becomes active only after verification.

## Security requirements

- one-time connection codes must expire;
- codes cannot be reused after successful exchange;
- connector credentials must be revocable;
- credentials are scoped to a specific site;
- secrets are encrypted at rest;
- connector requests are authenticated;
- sensitive requests include replay protection;
- server validates site ownership/connection state;
- every privileged operation is auditable.

## Capability model

The connector should declare capabilities rather than exposing arbitrary execution.

Examples:

- read.site
- read.wordpress
- read.plugins
- read.themes
- read.health
- action.plugin_update
- action.theme_update
- action.core_update
- action.cache_clear
- action.backup
- action.maintenance_mode

Capabilities are granted by SitePilot policy, not automatically trusted merely because the connector reports them.

## Heartbeat

The connector periodically reports:
- connector version
- WordPress version
- PHP version
- timestamp
- connection status
- supported capabilities
- basic health signal

No secrets should be sent as telemetry.

## Command lifecycle

For remote operations:

requested → authorized → queued → dispatched → accepted → executing → result_received → verified

Failure branches must explicitly record:
- timeout
- rejected
- unavailable
- failed
- verification_failed

## Connector principle

The connector is a capability bridge, not a second SitePilot backend.
