# Veylora SitePilot — Product Vision

## 1. Purpose

Veylora SitePilot is intended to turn WordPress maintenance from a manual checklist into a controlled site-operations system.

It should help an operator or agency:

- connect and inventory WordPress sites;
- continuously observe site health;
- detect maintenance and operational issues;
- explain what needs attention and why;
- create and prioritize work;
- automate approved low-risk operations;
- require approval for sensitive operations;
- verify actions after execution;
- keep an auditable history of every important action.

## 2. Product boundary

SitePilot is not initially a WordPress plugin product.

The product is the central web application. A separate WordPress connector will later act as a secure integration layer.

## 3. Core operating loop

1. Observe
2. Normalize
3. Analyze
4. Decide
5. Authorize
6. Execute
7. Verify
8. Record
9. Notify

Every automated capability should fit this loop.

## 4. Product principles

- Safety before automation.
- Least privilege by default.
- Human approval for high-impact actions.
- Every important action is traceable.
- AI recommends and reasons; policy controls execution.
- Failed actions must be visible and recoverable where possible.
- Connector and central business logic remain separate.
- APIs are explicit contracts.
- Background work is queue-based.
- Observability is a first-class feature.

## 5. Initial scope

The first product scope includes:

- authentication and organizations;
- website records;
- secure site connections;
- site inventory;
- health monitoring;
- WordPress/plugin/theme/core update visibility;
- maintenance task management;
- automation rules;
- notifications;
- audit logs;
- performance and security checks;
- controlled remote actions;
- dashboard and reporting.

AI-assisted maintenance will be introduced after the deterministic foundation is stable.

## 6. Non-goals for the first milestone

- Building the connector before the central architecture is stable.
- Unrestricted AI access to customer websites.
- Silent destructive automation.
- Hard-coding customer-specific business logic into the connector.
- Treating a WordPress plugin as the primary application.
