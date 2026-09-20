# Veylora SitePilot — API Architecture

## API principles

- REST API
- versioned namespace
- JSON responses
- Laravel remains authoritative
- authentication and authorization on every protected resource
- consistent error envelope
- pagination for collections
- idempotency for retryable mutations
- request/correlation IDs for tracing

## Versioning

Initial namespace:

/api/v1

Future incompatible contracts use a new major version.

## Resource groups

### Authentication
/api/v1/auth/login
/api/v1/auth/logout
/api/v1/auth/me

### Organizations
/api/v1/organizations
/api/v1/organizations/{organization}

### Sites
/api/v1/sites
/api/v1/sites/{site}
/api/v1/sites/{site}/health
/api/v1/sites/{site}/inventory
/api/v1/sites/{site}/metrics
/api/v1/sites/{site}/incidents
/api/v1/sites/{site}/tasks

### Operations
/api/v1/operations
/api/v1/operations/{operation}
/api/v1/operations/{operation}/cancel
/api/v1/operations/{operation}/retry

### Automation
/api/v1/automation/rules
/api/v1/automation/runs
/api/v1/automation/runs/{run}

### Approvals
/api/v1/approvals
/api/v1/approvals/{approval}
/api/v1/approvals/{approval}/approve
/api/v1/approvals/{approval}/reject

### Notifications
/api/v1/notifications
/api/v1/notifications/{notification}/read

## Connector-facing API

Connector endpoints should be isolated conceptually from dashboard endpoints.

Example:

/api/v1/connector/register
/api/v1/connector/heartbeat
/api/v1/connector/capabilities
/api/v1/connector/inventory
/api/v1/connector/telemetry
/api/v1/connector/jobs/{job}

Exact endpoint contracts will be frozen during connector specification.

## Response conventions

Success:
{
  "data": {},
  "meta": {},
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

## API security

- short-lived access tokens where appropriate
- refresh/session controls
- scoped connector credentials
- rate limiting
- replay protection for sensitive connector requests
- strict input validation
- authorization policies
- audit logging for privileged mutations

## API rule

The frontend must never depend on database structure. It consumes API contracts only.
