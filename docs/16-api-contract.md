# Veylora SitePilot — API Contract v1

## Base
/api/v1

## Authentication
Protected application endpoints use authenticated sessions/tokens. Connector authentication is isolated from dashboard authentication.

## Standard response
Success:
{
  "data": {},
  "meta": {},
  "request_id": "..."
}

Collection:
{
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 0
  },
  "request_id": "..."
}

Error:
{
  "error": {
    "code": "stable_machine_code",
    "message": "Human-readable message",
    "details": {}
  },
  "request_id": "..."
}

## Application endpoints

### Auth
POST /auth/login
POST /auth/logout
GET /auth/me

### Organizations
GET /organizations
POST /organizations
GET /organizations/{organization}
PATCH /organizations/{organization}

### Sites
GET /sites
POST /sites
GET /sites/{site}
PATCH /sites/{site}
DELETE /sites/{site}

### Site Connections
GET /sites/{site}/connections
POST /sites/{site}/connections
GET /sites/{site}/connections/{connection}
DELETE /sites/{site}/connections/{connection}

### Site Monitoring
GET /sites/{site}/health
GET /sites/{site}/inventory
GET /sites/{site}/metrics
GET /sites/{site}/incidents
GET /sites/{site}/tasks

`GET /sites/{site}/inventory` is implemented and reports the site's newest
**completed** inventory snapshot: `snapshot` (id, type, status, checksum,
timestamps), `core` (WordPress/PHP version and core update availability),
`plugins`, `themes`, and a `summary` with counts and `updates_available`. A site
that has never synced is HTTP 200 with `snapshot`/`core` `null` and empty arrays,
so the dashboard can tell "not synced yet" from a failed request. It requires
membership in the site's organization.
POST /sites/{site}/tasks

### Operations
GET /operations
POST /operations
GET /operations/{operation}
POST /operations/{operation}/cancel
POST /operations/{operation}/retry
POST /operations/{operation}/resolve-unknown

`POST /operations/{operation}/resolve-unknown` requires an authenticated organization owner or admin and accepts `{ "resolution": "success|failed|cancelled", "reason": "..." }`. It is valid only for an operation in `unknown` with evidence of an accepted/executing timeout. The response includes both `status` and `resolution`: `success` records the operator disposition while status remains `unknown`; `failed` sets status `failed`; and `cancelled` sets status `cancelled`. Only authoritative remote verification can set status `succeeded`. The endpoint records an audit event without fabricating connector verification, attempts, results, or jobs. Invalid state and insufficient evidence return HTTP 409; validation errors return HTTP 422.

`GET /operations`, `GET /operations/{operation}` and `POST /operations/{operation}/cancel` are implemented. Reads require an active membership in the active organization owning the active site, and an operation outside that scope is HTTP 404 so a ULID cannot be used to probe another tenant. The list is ordered by `created_at` then `id`, supports optional `site_id` and `status` filters, and defaults to 25 per page with a maximum of 100. The detail response is the standard operation shape and includes `latest_attempt`, `result` and `approval` when those relations exist.

Cancellation requires an authenticated organization **owner or admin** and a non-empty `reason`. It reuses the existing terminal `cancelled` state and is audited as `operation_cancelled` with before/after status, the actor, and `connector_dispatch_claimed: false`. Cancelling never dispatches connector work: an operation still in `queued` is safe because `DispatchOperationJob` re-reads the operation under lock and no-ops once it is terminal.

Cancellation is **only legal before remote work starts** — from `requested`, `approved` or `queued`. A `running` or `verification_pending` operation returns HTTP 409 `operation_in_flight`, because a connector may already have claimed the job and there is no connector-side cancellation capability; cancelling it would assert an unknown outcome and cause the connector's later result submission to be rejected as `already_terminal`, discarding real evidence. Those operations must instead move through the `unknown` and `resolve-unknown` workflow. An operation with a pending approval request returns HTTP 409 `operation_approval_pending`, because rejecting the approval already cancels the operation. A terminal operation returns `409 operation_terminal`, an already-cancelled one `409 already_cancelled`, and a missing reason HTTP 422.

### Automation

Rule and run persistence is defined for Phase 3 Foundation. The run recovery
endpoints below are implemented. `GET /automation/runs` remains planned.

GET /automation/rules
POST /automation/rules
GET /automation/rules/{rule}
PATCH /automation/rules/{rule}
DELETE /automation/rules/{rule}

GET /automation/runs
GET /automation/runs/{run}
POST /automation/runs/{run}/recover

#### Rule management

`GET/POST /automation/rules`, `GET/PATCH/DELETE /automation/rules/{rule}` are
implemented. Reads require an active membership in the active organization that
owns the active site; a rule that is not visible this way is HTTP 404, so the
endpoints cannot be used to probe another tenant. Mutating a rule additionally
requires an active organization **owner or admin**, the same executable MVP role
fallback used by approvals and recovery. `GET /automation/rules` supports an
optional `site_id` filter and `per_page` (default 25, maximum 100).

`POST /automation/rules` body:

```
{
  "organization_id": "...",
  "site_id": "...",
  "name": "Nightly cache clear",
  "enabled": false,
  "schedule_json": { "every_minutes": 30, "starts_at_utc": "2026-10-01T00:00:00Z" },
  "target_json": { "cache_type": "wordpress" }
}
```

`trigger_type`, `action_type` and `conditions_json` are **server-controlled and
not accepted from the client**. The MVP stores `schedule` / `action.cache_clear`
/ `null` regardless of what is submitted, so a client can never widen what the
engine supports. `enabled` defaults to `false`: a new rule does not start firing
until it is explicitly enabled. `target_json.cache_type` defaults to `wordpress`
when `target_json` is omitted and rejects any other value.

`PATCH /automation/rules/{rule}` accepts only `name`, `enabled` and
`schedule_json`. Organization, site, trigger, action and conditions are
immutable, so a rule can never be repointed at another site or action while its
runs still reference it. An empty body is HTTP 422.

`schedule_json` must contain exactly `every_minutes` (positive integer) and
`starts_at_utc` (`YYYY-MM-DDTHH:MM:SSZ`) and nothing else; the same
`ScheduledOccurrenceResolver` contract the scheduler uses is the authority, so
the API never persists a rule the scheduler would later skip.

`DELETE /automation/rules/{rule}` **refuses with HTTP 409 `rule_has_runs` when
the rule has any run**, because run, intent and provenance history must not be
destroyed. Retire such a rule with `PATCH {"enabled": false}` instead. A rule
with no runs is deleted and audited. Rule responses include `runs_count` and
`deletable` so a client can tell which case applies without attempting a delete.

Rule management is configuration only: it submits no Operation, so PolicyEngine
and OperationService remain the authorization boundary at evaluation time.
Creating, updating and deleting a rule are audited as `automation_rule_created`,
`automation_rule_updated` and `automation_rule_deleted` against
`target_type: automation_rule`, with `before_json`/`after_json` snapshots and
`remote_execution_claimed: false`.

`GET /automation/runs/{run}` reports one run, its immutable intent snapshot, the
latest recovery record, the linked Operation, any Operation candidate found by
the original idempotency key, and an execution-evidence summary. It is read-only
and requires an active membership in the run's active organization with an active
site. Cross-tenant runs return HTTP 404.

`POST /automation/runs/{run}/recover` recovers a **stranded automation run** only:
`AutomationRun.status` is `evaluating` and `operation_id` is NULL. It requires
`auth:sanctum`, an authenticated organization owner or admin of the run's
organization with an active site, and a non-empty `Idempotency-Key` header. The
body is `{ "action": "link|re_evaluate|abandon", "reason": "..." }`; `abandon`
requires a non-empty reason. The server never accepts `operation_id` or any
tenant field from the client.

- `link` is allowed only when immutable `automation_operation_origins` already
  proves the Operation belongs to the run. It never creates or repairs
  provenance, and it never dispatches, retries or approves. After linking, the
  existing reconciliation service mirrors authoritative Operation state; unknown
  remains unknown.
- `re_evaluate` is allowed only when there is no Operation, provenance,
  execution evidence or Operation lifecycle audit for the original request. It
  re-checks the original requester, organization, site, connector connection,
  capability, current policy and current approval requirement live, then calls
  OperationService exactly once as the original requester using the original
  snapshotted idempotency key. No successor run and no new key are created. HTTP
  202 is returned because an Operation was submitted.
- `abandon` is allowed only when the automation intent was never submitted. It
  requires an authorized actor and a non-empty reason, sets the run to
  `abandoned`, and never modifies an Operation. Abandonment records that the
  intent was not submitted; it does not claim that a WordPress operation failed.
  `abandoned` is distinct from `cancelled`.

Responses use the standard envelope. HTTP 404 covers cross-tenant access, HTTP
403 an unauthorized actor or a current policy denial, HTTP 409 an unsatisfied
precondition, provenance conflict, or another active recovery for the same run,
HTTP 422 a validation failure, and HTTP 200 `link`/`abandon`. Recovery state is
separate from `AutomationRun.status`: the run stays `evaluating` while a recovery
is requested, authorized or blocked, and recovery states are `none`, `requested`,
`authorized`, `in_progress`, `linked`, `submitted`, `awaiting_approval`,
`blocked`, `conflict`, and `abandoned`.

There is no public reconciliation API in this checkpoint. Operators can
manually invoke the backend `sitepilot:automation-reconcile` command; it reads
Operation state and mirrors it to eligible linked runs without creating an
Operation or dispatching connector work.

### Approvals
POST /approvals/{approval}/approve
POST /approvals/{approval}/reject

Approval actions require `auth:sanctum`. The reviewer must be an active member
of the active organization with an active site and the owner/admin role; the
requester cannot review their own operation. Rejection requires a non-empty
reason. Both actions use OperationService for state changes and audit records.
Reviewing an expired pending request marks it expired and cancels its operation;
the API returns HTTP 409.
This is the MVP role contract because `site.approve` is not defined in the
executable permission schema. Approval endpoints do not cause automation to
approve requests.

### Notifications
GET /notifications
POST /notifications/{notification}/read

### Audit
GET /audit-logs

## Connector API

Connector endpoints use a separate authentication mechanism and scope.

POST /connector/register
POST /connector/heartbeat
GET /connector/capabilities
POST /connector/inventory
POST /connector/telemetry
POST /connector/jobs/{job}/result
POST /connector/jobs/{job}/state
GET /connector/jobs

`POST /connector/inventory` is implemented and closes connection lifecycle step
"initial inventory sync". It requires an authenticated active connector and the
documented read capability for each section it reports: `read.wordpress` always,
`read.plugins` when `plugins` is non-empty or explicitly complete, and
`read.themes` when `themes` is non-empty or explicitly complete. A missing or
disabled capability is HTTP 403 `capability_denied`.

```
{
  "started_at": "2026-10-01T10:00:00Z",
  "completed_at": "2026-10-01T10:00:04Z",
  "category_completeness": { "wordpress": true, "plugins": true, "themes": false },
  "wordpress": { "version": "6.5.2", "php_version": "8.2", "update_available": true, "status": "active" },
  "plugins": [ { "key": "akismet/akismet", "name": "Akismet", "version": "5.8", "active": true, "update_available": false, "metadata": {} } ],
  "themes":  [ { "key": "twentytwentyfour", "name": "Twenty Twenty-Four", "version": "1.1", "active": true } ]
}
```

`category_completeness` is optional and accepts only the boolean keys
`wordpress`, `plugins`, and `themes`. `true` declares a complete observation;
`false` declares an incomplete observation; an omitted key means unknown. A
complete plugins or themes declaration requires that category's array in the
request (an empty array is valid and means complete with no items). Completeness
declarations for plugins/themes require the corresponding read capability even
when the array is empty. Legacy requests without this object remain valid and
persist all completeness values as unknown. An omitted category array is unknown,
never implicitly complete. The values are persisted on `inventory_snapshots` as
nullable booleans (`wordpress_complete`, `plugins_complete`, `themes_complete`);
they are not inferred from `snapshot_type` or array presence. Future derived
findings may use absence to resolve only when that category's stored value is
true. Explicit item observations remain usable when a category is incomplete or
unknown.

The connector never chooses the site: the snapshot is always attributed to the
authenticated connection's site. Submission is serialized per site with a row
lock, and each accepted payload writes one immutable `inventory_snapshots` row
plus its `site_core_states` / `site_plugins` / `site_themes` children; earlier
snapshots are never overwritten. `completed_at` defaults to arrival time and must
not precede `started_at`; duplicate component keys inside one payload are HTTP
422 `invalid_inventory`. A deterministic SHA-256 checksum of the canonical
payload is stored on the snapshot, so a connector retrying an identical
submission replays the existing snapshot (HTTP 200, `created: false`) instead of
writing duplicate history; changed inventory returns HTTP 201 with
`created: true`.

Inventory is a Level 0 read-only observation. It creates no Operation, claim and
no remote execution, requires no approval, and is not audited as a privileged
mutation; the snapshot itself is the durable record.

`POST /connector/jobs/{job}/state` requires an authenticated active connector that owns the site and claimed the job, with the enabled `read.cache_state` capability. It accepts state only when the attempt is `result_received`, the operation is `verification_pending`, and verification is `pending`; the attempt, operation, and result rows are locked for the transition. The authoritative JSON body requires `read_at`, `cache_generation`, `cleared_types`, and `cache_state`; `wp_version` and `connector_version` are optional. The connector does not send `cache_type`; SitePilot uses `target_json.cache_type`, which defaults to `wordpress` for cache-clear operations and rejects other values. For WordPress, `cleared_types` is exactly `object_cache`, `page_cache`, `transient_cache`, `rewrite_cache`, `file_cache`, and `opcache`. `cache_state` must be an object with exactly those six keys, each mapped to `cleared`. The server verifies that `read_at` is no earlier than verification processing start and no later than 60 seconds after it (UTC).

Accepted state evidence is persisted in `operation_results.actual_state_json` with `verification_status: pending`. `verified_at` and `verification_error` record the eventual outcome. An action result alone never succeeds an operation; only authoritative verification can transition it to succeeded. Conflicting duplicate state is HTTP 409. An identical pending duplicate retries verification-job dispatch; the job is unique per attempt while queued. A queue failure returns HTTP 503 and the connector can retry the identical state.

The exact registration handshake and signed-request scheme are defined in the connector specification.

## Mutation rules

- Authorization before mutation.
- Validation before dispatch.
- Idempotency key for retryable operations.
- Mutations produce audit records where privileged.
- Long-running work returns an operation/job representation rather than blocking.

## Pagination
Default 25; maximum 100 unless an endpoint explicitly defines another limit.

## HTTP semantics
Use standard status codes. Do not encode business failures as HTTP 200.

## Contract stability
Response fields should be additive within v1. Breaking changes require a new API version.
