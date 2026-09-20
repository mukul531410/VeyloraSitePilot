# Veylora SitePilot — Automation Engine

## Architecture

Scheduler → Trigger Resolver → Rule Engine → Policy Engine → Approval Gate → Operation Queue → Worker → Connector → Verification → Audit

## Trigger types

- schedule
- health state change
- update availability
- incident event
- connector heartbeat loss
- threshold crossing
- manual request

## Rule structure

A rule contains:
- enabled state
- scope
- trigger
- conditions
- action
- schedule/window
- approval policy
- retry policy
- notification policy

## Safety levels

### Level 0 — Read only
Automatic.

Examples:
- inventory refresh
- health check
- metadata collection

### Level 1 — Low impact
May be automatic if explicitly enabled.

Examples:
- cache purge
- non-destructive metadata refresh

### Level 2 — Maintenance mutation
Usually requires explicit automation policy and safe verification.

Examples:
- plugin update
- theme update

### Level 3 — High impact
Approval required by default.

Examples:
- WordPress core update
- database-affecting maintenance
- backup restoration
- configuration changes with broad impact

### Level 4 — Destructive
Never automatic by default.

Examples:
- deleting content/data
- irreversible cleanup
- disabling critical components without recovery

## Rule evaluation

Automation must fail closed.

If required policy data is unavailable, do not execute the action.

## Concurrency

Site-level operations require locking where concurrent actions could conflict.

Example:
Do not run plugin update, core update and backup-restore operations against the same site concurrently unless the operation policy explicitly permits it.

## Scheduling

Long work is queued. Workers enforce timeouts and heartbeats.

## Observability

Each automation run gets:
- run ID
- rule ID
- operation IDs
- timestamps
- policy decision
- result
- verification
- notification status
