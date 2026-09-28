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

### Operation attempt integrity (implemented foundation)

Operation dispatch locks the queued operation row while checking terminal state and the stored `max_attempts`, allocating the next attempt number, inserting the attempt, and moving the operation to `running`. The database uniquely constrains `(operation_id, attempt_number)`. `max_attempts` is stored per operation (current database default: 3). Exhaustion blocks creation of another attempt; dead-letter orchestration is not part of this foundation.

The classifier distinguishes safe replay of an unclaimed `dispatched` attempt, verification-only reprocessing of existing pending evidence, non-retryable verification outcomes, and unknown outcomes after a claim or possible execution. Classification does not enqueue retries. Automatic retry, timeout workers, unknown resolution, and dead-letter processing remain planned.

## Locking

Use distributed/site-level locks for conflicting operations.

## Dead-letter handling

Repeated failures move to a reviewable failure state rather than retrying forever.

## Worker isolation

Heavy operations should be isolated from latency-sensitive API work.

## Redis role

Redis is used for queues, locks, short-lived cache and coordination. Durable results remain in MySQL.
