# Veylora SitePilot — Development Rules

## Rule 1 — Safety first
Any action that can materially affect a customer website must pass an explicit authorization/policy layer.

## Rule 2 — No unrestricted remote execution
The platform and connector must never expose a generic arbitrary-command execution mechanism as a normal product capability.

## Rule 3 — Least privilege
Each connector capability receives only the permissions it needs.

## Rule 4 — AI does not authorize itself
AI may analyze, propose or prepare an action. The deterministic policy/permission layer decides whether execution is allowed.

## Rule 5 — Verify every mutation
After a remote mutation, SitePilot should verify the expected state whenever technically possible.

## Rule 6 — Audit important actions
Every remote mutation, policy decision, automation execution and privileged configuration change must produce an audit record.

## Rule 7 — Queue long work
Long-running or unreliable work belongs in background jobs, not ordinary HTTP requests.

## Rule 8 — Idempotency
Jobs that may be retried must be designed to avoid unintended duplicate effects.

## Rule 9 — Fail safely
A failed operation must not silently appear successful. Partial failures must be explicit.

## Rule 10 — Backend owns business rules
The frontend presents state; Laravel remains authoritative for permissions, workflows and business logic.

## Rule 11 — API contract first
Frontend and connector integrations use versioned, documented API contracts.

## Rule 12 — Secrets never enter Git
Tokens, passwords, private keys and production credentials must remain in environment/secret storage.

## Rule 13 — No premature connector development
The connector is built only after the central data model, API contract, capability model and security design are sufficiently stable.

## Rule 14 — Small reversible changes
Prefer small commits and changes that can be reviewed, tested and reverted independently.

## Rule 15 — Test before automation
An operation must first work deterministically and be verifiable before it is exposed to automation.

## Rule 16 — Documentation follows architecture
Important architectural decisions must be documented before implementation changes that depend on them.
