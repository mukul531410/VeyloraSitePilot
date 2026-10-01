# Veylora SitePilot — Core Algorithms

## 1. Site health algorithm

Input:
- reachability
- HTTP response
- SSL
- connector heartbeat
- WordPress state
- critical findings
- recent incidents

Process:
1. Validate freshness of each signal.
2. Normalize signals.
3. Assign deterministic statuses.
4. Apply severity rules.
5. Produce health state.
6. Store raw observations separately from derived health.
7. Create/update incidents when thresholds are crossed.

Health states:
- healthy
- attention
- degraded
- critical
- unknown

Unknown must not be treated as healthy.

## 2. Maintenance priority algorithm

Priority should be deterministic before AI enrichment.

Factors:
- severity
- exploit/security relevance
- production impact
- affected scope
- recurrence
- age
- business criticality

The result is a priority band and explanation, not an opaque AI score.

## 3. Automation decision algorithm

Input:
trigger + target + conditions + current state + policy + permissions

Process:
1. Confirm target exists.
2. Confirm target connection is active.
3. Confirm required capability.
4. Evaluate automation rule.
5. Evaluate organization/site policy.
6. Determine whether approval is required.
7. Create operation.
8. Queue only if authorized.
9. Execute through connector capability.
10. Verify expected result.
11. Record outcome.

## 4. Retry algorithm

### Implemented foundation and safe retry (Checkpoints 2D-1 through 2D-2B)

- `operations.max_attempts` is the stored attempt cap (default 3); dispatch reads that value.
- Dispatch locks the operation row while checking queued/terminal state, counting attempts, allocating the next number, creating the attempt, and changing operation state.
- `(operation_id, attempt_number)` is unique. This protects allocation at the database boundary as well.
- `OperationRetryClassifier` classifies attempt and verification outcomes; it does not enqueue work itself.
- An unclaimed `dispatched` attempt is safe to replay under the claim-before-execute connector contract. An `accepted`, `executing`, or `result_received` attempt without conclusive evidence is unknown and must not repeat the mutation.
- Checkpoint 2D-2B automatically retries an expired `dispatched` timeout only when the classifier confirms safety, stored `max_attempts` remains, and current policy, approval, active connection, and capability checks pass. It reuses the same operation and creates the next attempt through `DispatchOperationJob`.
- Retry scheduling is transactionally guarded by the attempt and operation locks, uses the unique attempt number constraint, and records `attempt_retry_scheduled`. Dispatch queue failure leaves the operation queued for the periodic dispatcher to recover; no attempt is counted until dispatch commits it.
- If attempts are exhausted, the detector moves the safe exhausted timeout to `dead_letter`; a failed retry precondition creates no new attempt.
- Existing pending authoritative evidence may be reprocessed as verification only; it does not create a new mutation attempt.
- `cache_error` is deterministic evidence that cache clearing did not complete; malformed, stale, future, missing, or conflicting evidence is uncertainty, not proof of mutation failure.
- Checkpoint 2D-2A detects expired per-attempt `timeout_at` values every minute. An unclaimed `dispatched` attempt becomes retryable `timeout` and may enter the safe retry flow above; an `accepted` or `executing` attempt becomes non-retryable `timeout` and moves the running operation to `unknown`.
- `result_received` and terminal attempts are excluded from execution-timeout detection. Timeout audits are persisted with the transition.
- An accepted/executing timeout moves the operation to `unknown`: SitePilot cannot prove whether the remote mutation executed. Unknown is terminal for automated execution and is never automatically retried or given a recovery successor.

### Unknown state resolution (Phase 2E)

An owner or administrator may resolve an `unknown` operation after reviewing external evidence. The required reason and resolution (`success`, `failed`, or `cancelled`) are recorded with actor and timestamp. This is an operator disposition, not connector verification: it creates no `OperationResult`, connector job, or attempt and does not rewrite prior evidence. `success` records the operator's conclusion but keeps `status: unknown`; only authoritative remote verification can set `status: succeeded`. `failed` and `cancelled` set their matching terminal statuses. Each resolution is audited as `operation_unknown_resolved`. No status transition out of unknown is allowed except the workflow's metadata-backed failed/cancelled dispositions; successful operator resolution remains status unknown with resolution metadata.

### Planned later

Use exponential backoff with jitter for later retry policy expansion. Never blindly retry destructive or unknown-state operations.

Unknown resolution is exposed through `POST /api/v1/operations/{operation}/resolve-unknown`; dead-letter recovery is exposed through the operation retry endpoint.

## 5. Incident algorithm

Create an incident when a monitored condition crosses a configured threshold.

Do not create a new incident for every repeated observation.

Use lifecycle:
detected → acknowledged → investigating → resolved

Reopening is allowed when the condition returns after resolution.

## 6. AI decision boundary

AI can:
- summarize;
- classify;
- explain;
- propose;
- generate a maintenance plan;
- identify likely causes.

AI cannot bypass:
- permissions;
- policy;
- approval requirements;
- capability scopes;
- verification;
- audit logging.

## 7. Verification algorithm

After an action:
1. collect expected post-state;
2. compare with intended state;
3. detect partial success;
4. record verification result;
5. trigger recovery/escalation if required.

A remote action is not considered successful merely because the connector accepted the command.
