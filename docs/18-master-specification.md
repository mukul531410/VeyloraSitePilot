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

For `cache_type: wordpress`, the connector must declare `read.cache_state` to submit authoritative state at `POST /api/v1/connector/jobs/{job}/state`. The payload includes `read_at`, `cache_generation`, `cleared_types`, and `cache_state`, optionally `wp_version` and `connector_version`; it omits `cache_type`. The exact cleared type set is `object_cache`, `page_cache`, `transient_cache`, `rewrite_cache`, `file_cache`, and `opcache`. `cache_state` is an object with exactly those six keys and each value must be `cleared`. Cache-clear operation creation defaults an omitted target cache type to `wordpress` and rejects other values. State submission requires attempt `result_received`, operation `verification_pending`, and verification `pending`, guarded by row locks. `read_at` must fall within the 60-second UTC window beginning when server-side verification starts. The action result is only a connector claim. Authoritative state is stored in `operation_results.actual_state_json`; `verification_status` begins pending, while `verified_at` and `verification_error` record the outcome. Only verified state can mark the operation succeeded. An identical pending submission can retry queue dispatch, with a unique verification job per attempt. Retry and dead-letter orchestration remains deferred.

### Operation attempt integrity (Checkpoint 2D-1)

Dispatch uses the stored `operations.max_attempts` (database default 3), locks the operation row while allocating an attempt, and relies on a unique `(operation_id, attempt_number)` constraint. The retry classifier is implemented as a domain foundation only; it does not schedule retries. Unclaimed dispatch may be safe to replay under the connector claim-before-execute contract. A claimed or otherwise uncertain mutation is not automatically repeated. Verification errors that do not prove cache failure are recorded as verification failure while leaving the attempt available for uncertainty handling; they do not mark the operation succeeded or failed. Timeout scheduling, operator retry/resolution, and dead-letter transitions remain planned.

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
