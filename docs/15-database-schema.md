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
id, site_id, status, connector_version, credential_ciphertext, credential_version, connected_at, last_seen_at, revoked_at, created_at, updated_at

### connector_capabilities
id, site_connection_id, capability_key, enabled, discovered_at, updated_at

### connector_heartbeats
id, site_connection_id, connector_version, wordpress_version, php_version, reported_at, status

## Inventory
### inventory_snapshots
id, site_id, snapshot_type, started_at, completed_at, status, checksum, created_at

### site_plugins
id, site_id, inventory_snapshot_id, plugin_key, name, version, update_available, active, status, metadata_json

### site_themes
id, site_id, inventory_snapshot_id, theme_key, name, version, update_available, active, status, metadata_json

### site_core_states
id, site_id, inventory_snapshot_id, wordpress_version, php_version, update_available, status

### available_updates
id, site_id, component_type, component_key, current_version, target_version, severity, security_related, detected_at, resolved_at, status

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
id, operation_id, result_status, expected_state_json, actual_state_json, verification_status, result_summary, created_at

## Automation
### automation_rules
id, organization_id, site_id nullable, name, enabled, trigger_type, conditions_json, action_type, action_config_json, approval_policy, retry_policy_json, schedule_json, created_at, updated_at

### automation_runs
id, automation_rule_id, site_id, status, trigger_payload_json, started_at, finished_at, created_at

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
