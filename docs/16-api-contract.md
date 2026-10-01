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
GET /automation/rules
POST /automation/rules
GET /automation/rules/{rule}
PATCH /automation/rules/{rule}
DELETE /automation/rules/{rule}

GET /automation/runs
GET /automation/runs/{run}

### Approvals
GET /approvals
POST /approvals/{approval}/approve
POST /approvals/{approval}/reject

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
