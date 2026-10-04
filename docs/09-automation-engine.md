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

### Phase 3 Automation Foundation MVP

The initial foundation supports only the `schedule` trigger and
`action.cache_clear`. Conditions are optional at the schema boundary;
`conditions_json: null` means no condition is configured. Non-null conditions
are unsupported and rejected until a separate condition contract is defined.
No condition evaluator is part of this foundation.

An automation run has a unique occurrence key per rule and may reference zero
or one Operation. The database enforces uniqueness on
`(automation_rule_id, occurrence_key)`. Run statuses are `pending`,
`evaluating`, `awaiting_approval`, `submitted`, `completed`, `failed`,
`skipped`, `unknown`, and `cancelled`. These describe automation processing only; `Operation.status`
remains authoritative for mutation execution and verification.

The MVP requester is the rule creator. Scheduled execution must revalidate
that user, active organization membership, organization/site state and scope,
and the executable owner/admin `site.operate` role fallback before any later
operation submission. Loss of authorization blocks the scheduled request; the
rule does not grant or preserve the creator's permissions. OperationService
and PolicyEngine remain mandatory authorization boundaries.

Every newly-created occurrence also receives one immutable
`automation_run_intents` snapshot in the same transaction as the run. It records
the original action, target, requester, deterministic idempotency key, rule and
tenant scope, occurrence, capture time/version, and policy context. Reusing an
existing occurrence never refreshes the snapshot. Older runs without one stay
incomplete; snapshots are not reconstructed from current rule state.

Approval review uses authenticated `POST /api/v1/approvals/{approval}/approve`
and `/reject` endpoints. For this MVP, only active organization owner/admin
members may review, and requesters cannot review their own operation. The API
delegates state changes and audit records to OperationService. Automation never
approves an operation. The endpoints are implemented; evaluator integration
is not part of this checkpoint.

The run lifecycle declares `unknown` and `cancelled` for future reconciliation.
`pending → evaluating` is the only transition currently executed by the
scheduler. Other transitions are guarded and reserved for the evaluator and
future reconciliation; no reconciliation worker is implemented here.

`automation_runs.operation_id` is nullable and unique when populated, so one
operation can be linked to at most one run. Future evaluator code must still
validate organization, site, action, target, requester, and deterministic
run-derived idempotency key against immutable provenance before linking an
operation.

Checkpoint A added persistence and models. Checkpoint B adds only schedule
occurrence validation/key generation, duplicate-safe run creation, and run
claiming. It does not add a scheduler, select due/missed occurrences, perform
operation orchestration, or expose APIs.

The supported `schedule_json` value has exactly two keys:

```json
{"every_minutes": 5, "starts_at_utc": "2026-10-01T00:00:00Z"}
```

`every_minutes` is a positive integer. `starts_at_utc` is a whole-second UTC
timestamp in `YYYY-MM-DDTHH:MM:SSZ` form. Occurrences are the anchor plus
non-negative integer multiples of the interval. Site timezone is not used.
The occurrence-key resolver accepts a supplied scheduled instant only when it
is on this interval and has whole-second precision. Due-time selection uses
the explicit evaluation timestamp described below.

The occurrence key is `schedule:v1:YYYY-MM-DDTHH:MM:SSZ`, with the instant
normalized to UTC. The key is unique within a rule, enforced by the database
unique constraint on `(automation_rule_id, occurrence_key)`. Repeated calls
for the same rule and instant return the existing run; another occurrence or
another rule creates a separate run. Unique-key collision handling returns the
existing scoped run and rethrows unrelated database errors.

Run claiming locks and re-reads the run row in a short transaction. Only
`pending` is claimable; it becomes `evaluating` with `started_at` set in UTC.
Other states return no claimed run, and the transaction ends before any future
work can wait on an Operation or connector lock.

### Checkpoint C: due occurrence discovery

`AutomationScheduleResolver` accepts an explicit evaluation timestamp and
returns the latest occurrence at or before that instant, or no occurrence if
the evaluation is before the UTC anchor. When several intervals were missed,
only the latest due occurrence is eligible. Older occurrences are not inserted
as runs and are not backfilled later.

`AutomationSchedulerService` examines rules and reports deterministic skip
reasons for disabled rules, unsupported triggers/actions, conditions,
invalid scope/schedules, and rules that are not due. For an eligible due rule,
it calls `AutomationRunService` to create or reuse the run, then calls
`AutomationRunClaimService`. Duplicate invocations reuse the unique run; only
a pending run is claimed. Results include the rule, scheduled instant, key,
created/reused state, claim outcome, and run status.

At Checkpoint C, the manual `sitepilot:automation-schedule` command accepted `--at` for a
testable evaluation instant and defaults to current UTC. It is not registered
as a recurring task. At Checkpoint C, scheduler discovery and claiming did not
create Operations or dispatch connector jobs.

### Checkpoint G1: evaluate claimed runs

The scheduler evaluates each newly claimed run through
`AutomationRunEvaluationService`. The service reauthorizes `AutomationRule.creator`,
derives `automation:run:{run_ulid}:operation:v1`, and calls
`OperationService::createOperation()` for `action.cache_clear` only. A unique
`automation_runs.operation_id` link and the run's immediate state update are
written atomically with operation creation. An immutable
`automation_operation_origins` row records the run, Operation, site,
organization, link time, and version in that same transaction. The origin and
`automation_runs.operation_id` must agree. Existing keyed Operations are
reusable only when their origin proves the same run and their scope, type,
target, requester, and key all match. An idempotency-key match alone is not
provenance; missing or conflicting origins fail closed.

Immediate Operation states map as follows: `pending_approval` to
`awaiting_approval`; `requested`, `approved`, `queued`, `running`, and
`verification_pending` to `submitted`; `succeeded` to `completed`; `failed`
and `dead_letter` to `failed`; `unknown` to `unknown`; and `cancelled` to
`cancelled`. Operation remains authoritative. Approval is never automatic.
This does not add a reconciliation worker or connector execution to the
automation layer.

### Checkpoint G2: authoritative Operation reconciliation

`AutomationRunReconciliationService` reads the linked Operation under row
locks and mirrors its current status through the guarded AutomationRun state
machine. Operation remains the sole authority for execution outcome. The
mapping is: `pending_approval` → `awaiting_approval`; `requested`, `approved`,
`queued`, `running`, and `verification_pending` → `submitted`; `succeeded` →
`completed`; `failed` and `dead_letter` → `failed`; `unknown` → `unknown`; and
`cancelled` → `cancelled`. Connector acceptance, attempt results, and
verification start never imply success.

Reconciliation validates that the Operation's site and its organization's
scope match the run, and that its run-derived idempotency key identifies this
run. A mismatch fails closed: the link and run status are left untouched and a
deduplicated `automation_run_reconciliation_conflict` audit event reports the
conflict. Successful state changes write `automation_run_reconciled`; repeated
reads of an unchanged status do not write another transition audit.

Unknown remains an uncertain terminal execution outcome. Operator resolution
to `success` leaves the Operation status `unknown`, so the run stays unknown.
Only an authoritative Operation status of `succeeded` completes a run.
Reconciliation can move an unknown run to failed or cancelled only after the
Operation's own resolution workflow changes its status. Cancellation remains
cancelled, including approval rejection/expiration. Dead-letter Operations
map to failed and do not trigger automation retry or recovery.

The manually invokable `sitepilot:automation-reconcile` command considers
linked runs in any state for transition or consistency checks and reports
`evaluating` runs without an Operation as `stranded_evaluating`. It preserves
those runs and never creates an Operation, invokes OperationService or
OperationRecoveryService, dispatches connector work, or performs automation
retry/recovery. Reconciliation is transactional, locks and rereads the run
before mapping, and audits a transition only once. It is not registered as a
recurring scheduled task. MySQL row-lock concurrency is governed by the
database. The opt-in MySQL concurrency test verified two independent workers
waiting on one run lock, then observing one transition and one unchanged result;
the default feature-test configuration still uses SQLite.

## Stranded run recovery

Reconciliation only reports a stranded run. Recovery of a stranded run is a
separate, explicit operator action exposed through
`POST /automation/runs/{run}/recover`.

A run is **stranded** when `AutomationRun.status` is `evaluating` and
`operation_id` is NULL. Recovery state is separate from run status: the run
stays `evaluating` while a recovery is requested, authorized, in progress or
blocked, so a refused attempt never rewinds a run to `pending`. Recovery states
are `none` → `requested` → `authorized` → `in_progress` → `linked` →
`submitted` → `awaiting_approval` → `blocked` → `conflict` → `abandoned`, and
they are stored in `automation_run_recoveries` with the actor, action, request
idempotency key, reason, timestamps and result metadata. `none` is the absence of
a recovery record. `AutomationRun.cancelled` is never used for abandonment;
`abandoned` is a distinct terminal run status reachable only from `evaluating`.

There are exactly three approved actions, and they do not overlap the Operation
retry, recovery or unknown-resolution workflows. Unknown Operations, Operation
retry, failed-Operation recovery, already executed Operations and unknown
resolution keep their existing endpoints and semantics.

**link** is permitted only through immutable `automation_operation_origins`.
Provenance must prove `automation_run_id`, `operation_id`, site, organization,
operation type, target, requester and idempotency key against the immutable
`automation_run_intents` snapshot; otherwise the attempt is blocked. Provenance
is never created or repaired during a link, and a candidate Operation matched by
idempotency key alone is never adopted. The link never dispatches, retries or
approves. After linking, `AutomationRunReconciliationService` mirrors the
authoritative Operation state, and `unknown` stays `unknown`.

**re_evaluate** is permitted only with positive proof that nothing was submitted:
no `AutomationOperationOrigin`, no Operation holding the original idempotency
key, no attempts, results, approval requests, or Operation lifecycle audit. Any
such evidence fails closed. If provenance proves an existing Operation belongs to
the run, the correct action is `link`, never re-evaluation. Re-evaluation reads
the original operation type, target, requester, idempotency key, rule identity,
tenant scope and occurrence only from the intent snapshot; the current
`AutomationRule` target is never substituted for the original intent. Immediately
before submission it rechecks the original requester's status and authorization,
organization and site state, connector connection, required capability, current
policy and current approval requirement. It then calls OperationService exactly
once as the original requester with the original snapshotted idempotency key. The
recovery operator is never the Operation requester. The Operation, its origin,
`automation_runs.operation_id` and the recovery state are created atomically, no
successor run is created, and when approval is currently required the normal
OperationService approval flow applies.

**abandon** is permitted only for a stranded run with no Operation, no origin and
no execution evidence. It requires an authorized actor and a non-empty reason. It
never modifies an Operation and never asserts that a remote WordPress operation
failed; it records only that the automation intent was not submitted.

Recovery is authorized by a dedicated `AutomationRunRecoveryAuthorization`
service rather than by silently reusing `site.retry`. It requires an active
authenticated actor, an active organization membership with the owner/admin role
in the run's own organization, an active organization, and the run's own site.
Cross-tenant access returns HTTP 404 and an inactive organization or site blocks
mutation. This is the MVP role contract because `site.recover_automation` is not
defined in the executable permission schema.

Recovery is idempotent per request key and exclusive per run. The lock order is
AutomationRun → `automation_run_recoveries` → origin/Operation, and every attempt
locks and rereads the run before mutating it. A duplicate `Idempotency-Key` and
action replays the stored recovery result without a second Operation; a different
concurrent request for a run that is already claimed or already recovered
conflicts. A blocked attempt releases the run so a later legitimate attempt stays
possible, and both blocked outcomes and conflicts are audited durably outside the
rolled-back attempt. A new Operation is invisible until its transaction commits,
and no database lock is held while waiting for connector execution.

Recovery writes `automation_run_recovery_requested`,
`automation_run_recovery_authorized`, `automation_run_recovery_blocked`,
`automation_run_recovery_operation_linked`,
`automation_run_recovery_reevaluation_started`,
`automation_run_recovery_submitted`, `automation_run_recovery_abandoned` and
`automation_run_recovery_conflict`. It does not duplicate normal Operation
lifecycle audit events.

## Rule management API

Rule persistence is reachable through `GET/POST /api/v1/automation/rules` and
`GET/PATCH/DELETE /api/v1/automation/rules/{rule}`. Reads require active
membership in the active organization owning the active site; mutations require
an active organization owner or admin, the same MVP role fallback as approval
and recovery.

Creating a rule is configuration only and never submits an Operation, so the
policy, approval and operation boundaries stay at evaluation time.
`trigger_type`, `action_type` and `conditions_json` are server-controlled,
`enabled` defaults to `false`, and only `name`, `enabled` and `schedule_json` are
mutable. `schedule_json` must contain exactly `every_minutes` and `starts_at_utc`
as defined by `ScheduledOccurrenceResolver`, so a rule the scheduler would reject
can never be persisted. A rule that already has runs cannot be deleted, because
run, intent and provenance history is preserved; it is retired by disabling it.

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
