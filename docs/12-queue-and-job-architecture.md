# Veylora SitePilot — Queue and Job Architecture

## Why queues

Remote site operations and monitoring can be slow, unreliable and retryable. They must not depend on long-lived browser requests.

## Flow

API/Scheduler → Dispatch → Redis Queue → Worker → Service → Connector → Verification → Persistence

## Job categories

- site inventory
- health check
- uptime check
- update check
- performance measurement
- security scan
- remote operation
- verification
- notification
- AI analysis

## Job rules

Every job should have:
- unique job ID
- correlation ID
- target site
- timeout
- retry policy
- attempt count
- started/finished timestamps
- result state

## Idempotency

Retryable jobs must carry an idempotency key.

If a worker cannot determine whether a remote mutation succeeded, it must not blindly repeat the mutation. It should enter an unknown/verification state.

## Locking

Use distributed/site-level locks for conflicting operations.

## Dead-letter handling

Repeated failures move to a reviewable failure state rather than retrying forever.

## Worker isolation

Heavy operations should be isolated from latency-sensitive API work.

## Redis role

Redis is used for queues, locks, short-lived cache and coordination. Durable results remain in MySQL.
