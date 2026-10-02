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
POST /sites/{site}/tasks

### Operations
GET /operations
POST /operations
GET /operations/{operation}
POST /operations/{operation}/cancel
POST /operations/{operation}/retry
POST /operations/{operation}/resolve-unknown

`POST /operations/{operation}/resolve-unknown` requires an authenticated organization owner or admin and accepts `{ "resolution": "success|failed|cancelled", "reason": "..." }`. It is valid only for an operation in `unknown` with evidence of an accepted/executing timeout. The response includes both `status` and `resolution`: `success` records the operator disposition while status remains `unknown`; `failed` sets status `failed`; and `cancelled` sets status `cancelled`. Only authoritative remote verification can set status `succeeded`. The endpoint records an audit event without fabricating connector verification, attempts, results, or jobs. Invalid state and insufficient evidence return HTTP 409; validation errors return HTTP 422.

### Automation

Rule and run persistence is defined for Phase 3 Foundation. The endpoints
below remain planned and are not implemented by the schema/models checkpoint.

GET /automation/rules
POST /automation/rules
GET /automation/rules/{rule}
PATCH /automation/rules/{rule}
DELETE /automation/rules/{rule}

GET /automation/runs
GET /automation/runs/{run}
POST /automation/runs/{run}/recover

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
