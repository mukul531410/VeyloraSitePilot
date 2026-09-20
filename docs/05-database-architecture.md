# Veylora SitePilot — Database Architecture

## Goals
The database is the durable source of truth for application state, operational history, policy decisions and reporting data.

## Core domains

### Identity
- users
- organizations
- organization_members
- roles
- permissions

### Sites
- sites
- site_environments
- site_connections
- connector_capabilities
- connector_heartbeats

### Inventory
- wordpress_core_versions
- site_plugins
- site_themes
- site_inventory_snapshots
- available_updates

### Monitoring
- health_checks
- uptime_checks
- incidents
- site_metrics

### Operations
- tasks
- task_attempts
- operations
- operation_results
- maintenance_windows

### Automation
- automation_rules
- automation_runs
- automation_actions
- approval_requests

### Notifications
- notification_channels
- notification_preferences
- notifications

### Security
- security_findings
- security_scans

### Audit
- audit_logs

## Key relationship model

Organization → many Sites

Site → one or more SiteConnections over its lifecycle

Site → many InventorySnapshots

Site → many HealthChecks / Metrics / Incidents

Site → many Tasks

AutomationRule → many AutomationRuns

AutomationRun → many Actions

Operation → many Attempts

## Important design rules

1. Durable business state belongs in MySQL.
2. Redis keys must never be the only copy of important state.
3. Historical operational records should be append-oriented where practical.
4. IDs should be opaque application identifiers; do not expose sequential database IDs unnecessarily.
5. Timestamps are stored consistently in UTC.
6. Soft deletion should be used only where recovery/audit requirements justify it.
7. Secrets must not be stored as plain text.
8. Connection credentials should be encrypted at rest and access-controlled.
9. Large telemetry/history datasets should be designed for retention and aggregation rather than unlimited raw storage.

## Initial status/state conventions

Use explicit state machines instead of scattered booleans.

Example task states:

pending → queued → running → succeeded
pending → queued → running → failed
pending → cancelled
pending → requires_approval → approved → queued

## Audit requirements

Privileged state changes should retain:
- actor
- organization
- site
- action
- target
- request/correlation ID
- before/after summary where safe
- policy result
- timestamp
- outcome
