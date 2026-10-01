# Veylora SitePilot — Master Specification v1.0

## Status
Architecture baseline approved for implementation.

## Product
A central web platform for controlled WordPress site operations and maintenance.

## Stack
- Laravel backend
- MySQL durable storage
- Redis queues/cache/locks
- Laravel REST API
- Next.js + React frontend
- Separate WordPress connector

## Core loop
Observe → Normalize → Analyze → Decide → Authorize → Execute → Verify → Record → Notify

## Core modules
Identity, Organizations, Sites, Connections, Inventory, Monitoring, Performance, Security, Maintenance, Operations, Automation, Approvals, Notifications, Audit, AI, Reporting, Billing.

## Non-negotiable rules
1. Central application owns business logic.
2. Connector is a capability bridge.
3. AI never bypasses policy or authorization.
4. High-impact actions require approval by default.
5. Automation fails closed.
6. Remote mutations require verification.
7. Important actions are audited.
8. Long work runs through queues/workers.
9. MySQL is the durable source of truth.
10. Secrets never enter Git.
11. APIs are versioned contracts.
12. The WordPress test site is introduced only after connector contracts are stable.

### Cache operation verification

For `cache_type: wordpress`, the connector must declare `read.cache_state` to submit authoritative state at `POST /api/v1/connector/jobs/{job}/state`. The payload includes `read_at`, `cache_generation`, `cleared_types`, and `cache_state`, optionally `wp_version` and `connector_version`; it omits `cache_type`. The exact cleared type set is `object_cache`, `page_cache`, `transient_cache`, `rewrite_cache`, `file_cache`, and `opcache`. `cache_state` is an object with exactly those six keys and each value must be `cleared`. Cache-clear operation creation defaults an omitted target cache type to `wordpress` and rejects other values. State submission requires attempt `result_received`, operation `verification_pending`, and verification `pending`, guarded by row locks. `read_at` must fall within the 60-second UTC window beginning when server-side verification starts. The action result is only a connector claim. Authoritative state is stored in `operation_results.actual_state_json`; `verification_status` begins pending, while `verified_at` and `verification_error` record the outcome. Only verified remote state can mark the operation succeeded. An identical pending submission can retry verification queue dispatch, with a unique verification job per attempt.

### Operation attempt integrity (Checkpoint 2D-1)

Dispatch uses the stored `operations.max_attempts` (database default 3), locks the operation row while allocating an attempt, and relies on a unique `(operation_id, attempt_number)` constraint. The classifier remains classification-only. Checkpoint 2D-2B automatically retries an expired unclaimed `dispatched` timeout through the same operation and existing dispatch job, after rechecking policy, prior approval, active connection, capability, and stored attempt limit. It records `attempt_retry_scheduled`. A claimed or otherwise uncertain mutation is never automatically repeated. Verification errors that do not prove cache failure remain verification outcomes and do not create remote mutation attempts.

### Per-attempt timeout detection (Checkpoint 2D-2A)

The scheduler runs expired-attempt detection every minute using the existing `operation_attempts.timeout_at`. A still-`dispatched` attempt becomes `timeout` with `retryable: true`; if authorized preconditions pass and the stored attempt cap permits, the same operation is queued for the next attempt. An `accepted` or `executing` timeout becomes `timeout` with `retryable: false` and moves a still-running operation to `unknown`; it is never retried. Result-received and terminal attempts are ignored. These transitions are guarded by attempt-then-operation row locks and audited as `attempt_timeout`, plus `operation_unknown` for uncertain outcomes. Unknown means execution outcome is uncertain: automated retry and recovery are forbidden. An authenticated organization owner or admin can call `POST /api/v1/operations/{operation}/resolve-unknown` with a required reason and resolution `success`, `failed`, or `cancelled`. Operator `success` is stored as resolution metadata while status remains `unknown`; it is not connector verification and does not set `succeeded`. Operator `failed` and `cancelled` set their matching terminal statuses. The operator disposition is audited as `operation_unknown_resolved`, preserves historical evidence, and creates no connector job, attempt, result, or successor. No other status transition out of unknown is allowed. Exhausted safe timeouts enter dead letter. Retry backoff/jitter and total operation timeout remain planned.

## Release strategy
Build one vertical slice first, then expand capability by capability.

## Phase 1 exit criteria
- backend boots;
- frontend boots;
- MySQL works;
- Redis works;
- API v1 works;
- authentication works;
- organization/site records work;
- basic dashboard can create and display a site;
- tests cover the first vertical slice;
- no connector dependency is required.

Once these criteria are met, the project moves to connector and monitoring phases.
