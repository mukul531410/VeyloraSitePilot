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
GET /connector/jobs

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
