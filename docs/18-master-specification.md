# Veylora SitePilot — Master Specification v1.0

## Status
Architecture baseline approved for implementation.

## Product
A central web platform for controlled WordPress site operations and maintenance.

## Stack
- Laravel backend
- MySQL durable storage
- Redis queues/cache/locks
- Laravel REST API
- Next.js + React frontend
- Separate WordPress connector

## Core loop
Observe → Normalize → Analyze → Decide → Authorize → Execute → Verify → Record → Notify

## Core modules
Identity, Organizations, Sites, Connections, Inventory, Monitoring, Performance, Security, Maintenance, Operations, Automation, Approvals, Notifications, Audit, AI, Reporting, Billing.

## Non-negotiable rules
1. Central application owns business logic.
2. Connector is a capability bridge.
3. AI never bypasses policy or authorization.
4. High-impact actions require approval by default.
5. Automation fails closed.
6. Remote mutations require verification.
7. Important actions are audited.
8. Long work runs through queues/workers.
9. MySQL is the durable source of truth.
10. Secrets never enter Git.
11. APIs are versioned contracts.
12. The WordPress test site is introduced only after connector contracts are stable.

## Release strategy
Build one vertical slice first, then expand capability by capability.

## Phase 1 exit criteria
- backend boots;
- frontend boots;
- MySQL works;
- Redis works;
- API v1 works;
- authentication works;
- organization/site records work;
- basic dashboard can create and display a site;
- tests cover the first vertical slice;
- no connector dependency is required.

Once these criteria are met, the project moves to connector and monitoring phases.
