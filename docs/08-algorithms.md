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

### Implemented foundation (Checkpoint 2D-1)

- `operations.max_attempts` is the stored attempt cap (default 3); dispatch reads that value.
- Dispatch locks the operation row while checking queued/terminal state, counting attempts, allocating the next number, creating the attempt, and changing operation state.
- `(operation_id, attempt_number)` is unique. This protects allocation at the database boundary as well.
- Retry classification is deterministic only; this checkpoint does not schedule retries.
- An unclaimed `dispatched` attempt is safe to replay under the claim-before-execute connector contract. An `accepted`, `executing`, or `result_received` attempt without conclusive evidence is unknown and must not repeat the mutation.
- Existing pending authoritative evidence may be reprocessed as verification only; it does not create a new mutation attempt.
- `cache_error` is deterministic evidence that cache clearing did not complete; malformed, stale, future, missing, or conflicting evidence is uncertainty, not proof of mutation failure.

### Planned later

Retry only when:
- failure is classified retryable;
- operation is idempotent or has a safe idempotency key;
- retry limit is not exceeded;
- policy allows retry.

Use exponential backoff with jitter.

Never blindly retry destructive or unknown-state operations.

Timeout scheduling, automatic retry, operator retry, unknown resolution, and dead-letter transitions are not implemented by Checkpoint 2D-1.

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
