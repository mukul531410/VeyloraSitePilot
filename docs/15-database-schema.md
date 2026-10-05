# Veylora SitePilot — Database Schema v1

This document defines the logical schema before Laravel migrations are implemented.

## Conventions
- Primary keys: UUID/ULID-style opaque identifiers.
- Timestamps: UTC.
- Foreign keys: explicit and indexed.
- Tenant-owned records carry organization_id directly or through a site relationship.
- Secrets are encrypted at application level and never returned by ordinary read APIs.
- State machines use explicit status values.

## Identity
### users
id, name, email, password_hash, status, last_login_at, created_at, updated_at

### organizations
id, name, slug, status, created_at, updated_at

### organization_members
id, organization_id, user_id, role_id, status, created_at, updated_at

### roles
id, organization_id nullable, name, key, created_at, updated_at

### permissions
id, key, description

### role_permissions
role_id, permission_id

## Sites
### sites
id, organization_id, name, url, environment, status, business_criticality, timezone, notes, created_at, updated_at

### site_connections
id, site_id, status, connector_version, credential_ciphertext, credential_version, connector_token_hash, connection_intent, intent_expires_at, connected_at, last_seen_at, revoked_at, created_at, updated_at

### connector_capabilities
id, site_connection_id, capability_key, enabled, discovered_at, updated_at

### connector_heartbeats
id, site_connection_id, connector_version, wordpress_version, php_version, reported_at, status

## Inventory
### inventory_snapshots
id, site_id, snapshot_type, started_at, completed_at, status, checksum, wordpress_complete nullable, plugins_complete nullable, themes_complete nullable, created_at

The nullable category completeness fields record explicit connector declarations.
`true` means the category was completely observed, `false` means it was observed
but is incomplete, and `null` means completeness is unknown (including legacy
snapshots). They are independent of `snapshot_type` and whether a category array
was present in the request.

### site_plugins
id, site_id, inventory_snapshot_id, plugin_key, name, version, update_available, update_available_reported nullable, active, status, metadata_json

### site_themes
id, site_id, inventory_snapshot_id, theme_key, name, version, update_available, update_available_reported nullable, active, status, metadata_json

### site_core_states
id, site_id, inventory_snapshot_id, wordpress_version, php_version, update_available, update_available_reported nullable, status

`update_available_reported` preserves whether the connector explicitly supplied
the optional `update_available` value. `null` represents older rows for which
presence was not recorded. Derived findings treat only a reported value as an
item-level update observation; a missing value is not interpreted as false.

### available_updates
id, site_id, type, item_identifier, severity, status, first_seen_at, last_seen_at, resolved_at, created_at, updated_at

Identity is unique on `(site_id, type, item_identifier)`. `type` is `core`,
`plugin`, or `theme`; current findings use severity `info` and status `open` or
`resolved`. The table has a foreign key from `site_id` to `sites.id` with
cascade-on-delete and an index on `(site_id, status, last_seen_at)`. Findings are
derived from persisted inventory snapshot/component rows; the snapshot remains
the immutable source observation.

## Monitoring
### health_checks
id, site_id, check_type, status, value_json, checked_at, duration_ms

### site_metrics
id, site_id, metric_type, value, unit, observed_at

### uptime_checks
id, site_id, status, http_status, response_ms, checked_at

### incidents
id, site_id, type, severity, status, title, description, first_detected_at, last_detected_at, resolved_at

## Operations
### tasks
id, organization_id, site_id, type, title, description, priority, status, source, due_at, created_by, created_at, updated_at

### operations
id, task_id nullable, site_id, operation_type, target_json, status, policy_result, approval_required, idempotency_key, requested_by, created_at, started_at, finished_at

### operation_attempts
id, operation_id, attempt_number, status, connector_job_id, started_at, finished_at, error_code, error_details_json

### operation_results
id, operation_id, operation_attempt_id, connector_job_id, result_status, cache_cleared_at, cleared_types, cache_generation, error_code, error_message, expected_state_json, actual_state_json, verification_status, verified_at, verification_error, result_summary, created_at

`actual_state_json` stores the connector's authoritative live post-state separately from the original action-result fields. `verification_status` is pending, verified, or failed; `verified_at` records processing completion and `verification_error` records a deterministic failure code.

## Automation
### automation_rules
id, organization_id, site_id, name, enabled, trigger_type, schedule_json, conditions_json nullable, action_type, target_json, created_by, created_at, updated_at

Phase 3 Foundation MVP supports `trigger_type: schedule` and
`action_type: action.cache_clear`. `conditions_json` is nullable; non-null
conditions are unsupported until a condition contract is defined. The
supported `schedule_json` shape is exactly `{"every_minutes": positive
integer, "starts_at_utc": "YYYY-MM-DDTHH:MM:SSZ"}`. Occurrences are the UTC
anchor plus non-negative integer multiples of the interval.

### automation_runs
id, automation_rule_id, organization_id, site_id, operation_id nullable, occurrence_key, status, evaluation_metadata_json nullable, failure_code nullable, failure_message nullable, started_at nullable, finished_at nullable, created_at, updated_at

An automation run references at most one operation. The database enforces
unique non-null `operation_id` and unique `(automation_rule_id, occurrence_key)` for durable occurrence
deduplication. Run statuses are `pending`, `evaluating`, `awaiting_approval`,
`submitted`, `completed`, `failed`, `skipped`, `unknown`, `cancelled`, and
`abandoned`; they do not replace the
authoritative operation lifecycle. `abandoned` is a terminal run status reachable
only from `evaluating` and records that an automation intent was never submitted;
it is distinct from `cancelled` and never implies a remote operation failure.
Occurrence keys use
`schedule:v1:YYYY-MM-DDTHH:MM:SSZ` normalized to UTC and are unique per rule.
For a given evaluation time, the scheduler resolves only the latest due
occurrence. Older missed occurrences do not create runs or receive later
backfill.

### automation_run_intents
id, automation_run_id unique, intent_version, captured_at, organization_id, site_id, automation_rule_id, occurrence_key, original_operation_type, original_target_json, original_requester_id, original_idempotency_key, policy_context_snapshot, created_at, updated_at

One immutable historical intent belongs to each newly-created run. Its run,
tenant, rule, and requester foreign keys restrict deletion. Existing runs are
not backfilled from a rule's current state.

### automation_operation_origins
id, automation_run_id unique, operation_id unique, site_id, organization_id, linked_at, version, created_at, updated_at

The immutable origin proves which run created an Operation. Run and Operation
foreign keys restrict deletion. Service validation requires its site and
organization to agree with both records; unique run and Operation keys prevent
either side from being linked twice. It must agree with
`automation_runs.operation_id`. An origin may exist for a run whose
`operation_id` is still NULL; that is the stranded state that the `link` recovery
action resolves, and provenance is never created or repaired to force a link.

### automation_run_recoveries
id, automation_run_id, organization_id, site_id, actor_id, action, request_idempotency_key, reason nullable, state, active_automation_run_id nullable, failure_reason nullable, result_metadata_json nullable, requested_at, authorized_at nullable, started_at nullable, completed_at nullable, created_at, updated_at

Recovery state is stored separately from `automation_runs.status`, so a run stays
`evaluating` while a recovery is requested, authorized, in progress or blocked.
`action` is `link`, `re_evaluate`, or `abandon`; `state` is `requested`,
`authorized`, `in_progress`, `linked`, `submitted`, `awaiting_approval`,
`blocked`, `conflict`, or `abandoned`, and the absence of any record is `none`.
`active_automation_run_id` uniquely claims the run for an in-flight or
successful attempt, and is NULL for `blocked` and `conflict` so a refused attempt
releases the run and a later legitimate attempt remains possible. The unique
index on that nullable column is the database-level guarantee of at most one
active recovery per run; because most SQL engines allow repeated NULL values in a
unique index, arbitrarily many refused attempts may be recorded. Run, tenant, and
actor foreign keys restrict deletion.

### approval_requests
id, organization_id, site_id, operation_id, status, requested_by, reviewed_by, reason, expires_at, reviewed_at, created_at

## Notifications
### notification_channels
id, organization_id, type, config_ciphertext, enabled

### notification_preferences
id, organization_id, user_id, event_type, channel_id, enabled

### notifications
id, organization_id, user_id nullable, site_id nullable, type, severity, title, body, read_at, created_at

## Security
### security_findings
id, site_id, finding_type, severity, status, fingerprint, title, description, detected_at, resolved_at, metadata_json

### security_scans
id, site_id, status, started_at, completed_at, summary_json

## Audit
### audit_logs
id, organization_id, user_id nullable, site_id nullable, action, target_type, target_id, correlation_id, policy_result, before_json, after_json, metadata_json, created_at

## Retention
Raw metrics, heartbeats and uptime records require configurable retention/aggregation. Audit and operational history should use a longer retention policy appropriate to the SaaS plan and security requirements.

## Migration rule
Laravel migrations must implement this schema incrementally. Do not create one giant migration for the whole platform.
