# Veylora SitePilot — Master Specification v1.0

## Phase 3 Automation Foundation — Checkpoint A

The persistence foundation supports scheduled automation rules for the
existing `action.cache_clear` operation only. Conditions are optional;
`conditions_json: null` means none is configured, while non-null conditions
are unsupported pending a separate condition contract. Each run references
zero or one Operation, and `(automation_rule_id, occurrence_key)` is unique.
Automation run statuses are `pending`, `evaluating`, `awaiting_approval`,
`submitted`, `completed`, `failed`, and `skipped`; Operation status remains
authoritative for mutation execution. Checkpoint A does not implement schedule
resolution, run execution, APIs, approval APIs, or notifications.

## Phase 3 Automation Foundation — Checkpoint B

The supported schedule is an interval in UTC anchored by
`starts_at_utc`, represented as `{"every_minutes": positive integer,
"starts_at_utc": "YYYY-MM-DDTHH:MM:SSZ"}`. The occurrence key is
`schedule:v1:YYYY-MM-DDTHH:MM:SSZ` after UTC normalization. Database uniqueness
on `(automation_rule_id, occurrence_key)` prevents duplicate runs; repeated
creation returns the existing run. Claiming uses a locked transaction to move
only `pending` to `evaluating`. No scheduler or operation execution is added.

## Phase 3 Automation Foundation — Checkpoint C

Due resolution accepts an explicit evaluation timestamp and chooses only the
latest scheduled occurrence at or before that time. Older missed occurrences
are not backfilled. The scheduler service calls the existing run creation and
claim services, reports skipped/ineligible rules, and does not submit an
Operation. The `sitepilot:automation-schedule` command accepts `--at` and is
not registered as a recurring task.

## Phase 3 Automation Foundation — Checkpoint G1

Newly claimed runs are evaluated by `AutomationRunEvaluationService`, which
reauthorizes the rule creator and submits only `action.cache_clear` through
OperationService. The deterministic site-scoped idempotency key is
`automation:run:{run_ulid}:operation:v1`. Operation creation, unique run link,
and immediate run-state update share a database transaction. Approval remains
pending for a human reviewer. Immediate operation states map to the run states
documented in `docs/09-automation-engine.md`; asynchronous reconciliation and
connector execution are not implemented in automation.

New occurrence creation atomically captures one immutable intent snapshot with
the original requester, action, target, idempotency key, tenant/rule scope,
occurrence and policy context. Duplicate occurrence reuse never replaces that
snapshot. Legacy runs without intent are incomplete for future recovery and
are not reconstructed from current rule state. G1 Operation creation also
inserts an immutable `automation_operation_origins` provenance record in the
same transaction as the run link, run state transition and evaluation audit.
The deterministic idempotency key is not proof of provenance; existing
Operations are accepted only with a matching origin and validated scope,
action, target, requester and key.

## Phase 3 Automation Foundation — Checkpoint F

The MVP scheduled requester is the rule creator, whose active user status,
organization membership, organization/site status and scope, and owner/admin
`site.operate` fallback must be rechecked when a run is evaluated. The rule
does not preserve authorization after the creator loses it. Approval review
uses authenticated approve/reject endpoints for active owner/admin members;
self-review is denied and state changes/audits remain in OperationService.
Automation never approves its own requests.

Automation run statuses include `unknown` and `cancelled` for future
reconciliation. The scheduler still only performs `pending → evaluating`;
no automatic recovery worker is added here. A non-null
`automation_runs.operation_id` is unique, and the G1 evaluator validates scope,
action, target, requester, idempotency key, and immutable provenance before
linking.

## Phase 3 Automation Foundation — Checkpoint G2

`AutomationRunReconciliationService` transactionally reads the linked
Operation under row locks, validates its site, organization, and run-derived
idempotency identity, then applies the approved Operation-to-run mapping via
guarded transitions. Operation is authoritative: only `succeeded` completes
the run; `unknown` remains uncertain (including an operator success
disposition); `cancelled` remains cancelled; and `dead_letter` maps to failed.
Reconciliation audits actual transitions and deduplicates unchanged conflict
reports. A terminal transition conflict is rejected and reported without
reopening the run.

The manual `sitepilot:automation-reconcile` command includes linked runs for
state reconciliation and consistency checks. It reports `evaluating` runs with
no Operation as stranded and leaves them unchanged. It never creates or retries
an Operation, invokes operation recovery, or dispatches connector execution.
The command is not automatically scheduled. PHPUnit uses SQLite, so actual
concurrent MySQL locking is covered by a separate opt-in integration test; two
workers serialized on one run row and produced one transition audit.

Recovery of a stranded run is a separate explicit operator workflow, not part of
reconciliation and not part of Operation retry or unknown resolution. It applies
only to a run that is `evaluating` with no linked Operation, keeps run status at
`evaluating` while the attempt is requested, authorized or blocked, and stores its
own durable state machine in `automation_run_recoveries`. The only actions are
`link` through immutable `automation_operation_origins`, `re_evaluate` from the
immutable intent snapshot by the original requester and original idempotency key,
and `abandon` of an intent that was never submitted. Every one of them fails
closed when any evidence suggests a remote action may already have happened, and
abandonment never claims that a WordPress operation failed. An operator recovers
at most one run at a time, duplicate request keys replay safely, and a different
concurrent request conflicts.

## Phase 3 Automation Foundation — Rule management API

Rule persistence is exposed through `GET/POST /api/v1/automation/rules` and
`GET/PATCH/DELETE /api/v1/automation/rules/{rule}`. Reads require active
membership in the active organization owning the active site, and a rule outside
that scope is reported as not found. Mutating a rule requires an active
organization owner or admin, matching the approval and recovery role fallback.

Rule management is configuration only. It never submits an Operation and never
dispatches connector work, so PolicyEngine and OperationService remain the
authorization boundary at evaluation time. `trigger_type`, `action_type` and
`conditions_json` are server-controlled so a client cannot widen what the engine
supports; `enabled` defaults to `false`; and only `name`, `enabled` and
`schedule_json` are mutable, so a rule cannot be repointed at another site or
action while its runs still reference it. `schedule_json` is validated by the same
occurrence resolver the scheduler uses, so an unusable schedule is rejected at the
API boundary instead of being silently skipped later.

Deleting a rule that already has runs is refused, because run, intent and
provenance history must survive; such a rule is retired by disabling it. Rule
mutations are audited with before/after snapshots and never claim remote
execution.

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
