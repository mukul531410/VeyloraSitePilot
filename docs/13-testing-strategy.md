# Veylora SitePilot — Testing Strategy

## Testing layers

### Unit
Business rules, policy evaluation, state transitions, parsers and algorithms.

### Feature/API
Authentication, authorization, CRUD, API contracts, queue dispatch.

### Integration
Laravel ↔ MySQL, Laravel ↔ Redis, service integrations.

### Connector integration
SitePilot ↔ connector using controlled local WordPress fixtures.

### End-to-end
User action → API → queue → operation → connector → verification → dashboard.

### Failure testing
- timeout
- invalid credentials
- expired connection
- connector offline
- permission denied
- partial operation
- verification mismatch
- duplicate job
- worker crash

## Automation gate

No automated remote mutation is enabled until:
1. deterministic operation works;
2. permission policy is tested;
3. failure modes are tested;
4. verification is implemented;
5. audit logging is implemented;
6. rollback/recovery behavior is understood.

## Regression rule

Every production bug should produce a regression test when practical.

## Connector test environment

A local WordPress installation will be introduced after the central connector contract is defined.
