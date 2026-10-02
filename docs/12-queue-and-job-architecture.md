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

The classifier distinguishes safe replay of an unclaimed `dispatched` attempt, verification-only reprocessing of existing pending evidence, non-retryable verification outcomes, and unknown outcomes after a claim or possible execution. Checkpoint 2D-2B uses this classification to retry an expired unclaimed timeout on the same operation when stored attempts remain and current policy, original approval, active connection, and capability preconditions still pass. It creates the next attempt through the existing unique dispatch job and records `attempt_retry_scheduled`. Accepted/executing timeouts and unknown outcomes are never retried.

### Per-attempt timeout detection (Checkpoint 2D-2A)

The scheduler queues an expired-attempt detector every minute. It rechecks `timeout_at` and lifecycle state under attempt-then-operation row locks. An expired unclaimed `dispatched` attempt becomes `timeout` with `retryable: true`, while the operation remains running. An expired `accepted` or `executing` attempt becomes `timeout` with `retryable: false`, and the running operation becomes `unknown`. `result_received` and terminal attempts are ignored. Each transition records `attempt_timeout`; uncertain accepted/executing timeouts also record `operation_unknown`. Unknown operations are not selected by dispatch or retry paths and cannot be returned to queued/running state.

For a safe dispatched timeout, the detector queues the same operation for `DispatchOperationJob`; the job rechecks retry preconditions and stored `max_attempts` while holding the operation lock. Queue dispatch failures leave the operation queued for the periodic dispatcher to recover. If the attempt cap is exhausted, the operation becomes dead-lettered; if a precondition fails, no new attempt is created. Accepted/executing timeouts become `unknown` and are never automatically retried. SQLite tests cover deterministic transitions but do not verify production MySQL locking behavior.

### Automation run reconciliation (Checkpoint G2)

The manually invokable `sitepilot:automation-reconcile` command reads linked
Operation rows and mirrors their authoritative statuses into guarded
AutomationRun transitions. It uses a transaction and locks/re-reads the run
and Operation; it performs no remote work and is not registered for recurring
scheduling. It never calls OperationService, OperationRecoveryService,
connector APIs, or operation dispatch jobs. Repeated unchanged reconciliation
does not write duplicate transition audits. Runs in `evaluating` without an
Operation are reported as stranded and left untouched; G2 does not retry or
recover them. The standard PHPUnit configuration uses SQLite and does not
exercise simultaneous MySQL workers. The opt-in MySQL concurrency feature test
verified that two command processes wait on the same run lock and produce one
transition audit after release.

### Unknown operator resolution (Phase 2E)

An `unknown` operation means the connector may have executed a remote mutation but SitePilot lacks conclusive outcome evidence. Automated dispatch and recovery must not retry it. An authenticated organization owner or admin can submit `POST /api/v1/operations/{operation}/resolve-unknown` with a required reason and `success`, `failed`, or `cancelled` resolution. The service locks and rechecks the operation, preserves attempts and results, and audits `operation_unknown_resolved`. Operator `success` leaves operation status `unknown` and records the disposition separately; only authoritative remote verification sets status `succeeded`. Operator `failed` and `cancelled` use their corresponding terminal statuses. Resolution creates no connector verification evidence, attempt, job, or recovery successor. All other transitions from unknown are rejected.

## Locking

Use distributed/site-level locks for conflicting operations.

## Dead-letter handling

Repeated failures move to a reviewable failure state rather than retrying forever.

## Worker isolation

Heavy operations should be isolated from latency-sensitive API work.

## Redis role

Redis is used for queues, locks, short-lived cache and coordination. Durable results remain in MySQL.
