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
- read.cache_state
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

## Inventory category completeness

`POST /api/v1/connector/inventory` may include the optional
`category_completeness` object with boolean keys `wordpress`, `plugins`, and
`themes`. `true` declares that category fully observed; `false` declares that it
was observed but incomplete. An omitted key means unknown. A `true` plugins or
themes declaration requires the corresponding array in the payload; an empty
array with `true` explicitly means the category was completely observed and
contained no items. Declaring plugins or themes complete requires the matching
`read.plugins` or `read.themes` capability, even for an empty array.

The object and all its keys are optional for backward compatibility. A legacy
request without `category_completeness` is accepted and stores all category
completeness values as unknown. Omitted category arrays remain unknown and are
never treated as complete. Completeness is stored on the immutable inventory
snapshot; it is not inferred from `snapshot_type` or array presence. Future
derived findings may be resolved by category absence only when the corresponding
snapshot completeness value is `true`. An explicitly reported item remains a
usable observation regardless of category completeness.

## Command lifecycle

For remote operations:

requested → authorized → queued → dispatched → accepted → executing → result_received → verification_pending → verified

The connector's initial `result` response records its action claim; it does not prove success. Afterward, a connector with `read.cache_state` submits a live authoritative post-state to `POST /api/v1/connector/jobs/{job}/state`. The payload contains `read_at`, `cache_generation`, `cleared_types`, and `cache_state`, with optional `wp_version` and `connector_version`. It does not contain `cache_type`; SitePilot reads `target_json.cache_type` from the operation. Cache-clear creation defaults an omitted cache type to `wordpress` and rejects any other supplied value.

For `cache_type: wordpress`, `cleared_types` must be exactly `object_cache`, `page_cache`, `transient_cache`, `rewrite_cache`, `file_cache`, and `opcache`. `cache_state` is an object with exactly those six keys, each mapped to the string `cleared`; missing or extra keys and any other value fail verification. `read_at` is interpreted in UTC and must be between the server's verification start time and 60 seconds after it. The endpoint accepts state only while attempt is `result_received`, operation is `verification_pending`, and verification is `pending`; these rows are locked during the transition. Accepted evidence is stored with `verification_status: pending`. Only a successful authoritative verification marks the attempt and operation succeeded. Verification failures mark the attempt and verification failed; the operation remains `verification_pending` until later retry/dead-letter orchestration is defined. An identical pending submission retries queue dispatch; verification jobs are unique per attempt while queued.

Failure branches must explicitly record:
- timeout
- rejected
- unavailable
- failed
- verification_failed

## Connector principle

The connector is a capability bridge, not a second SitePilot backend.
