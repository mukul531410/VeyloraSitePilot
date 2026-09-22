# Veylora SitePilot

Veylora SitePilot is a standalone WordPress site operations and maintenance platform.

## Product principle

**Observe → Understand → Decide → Execute → Verify → Record**

SitePilot is a central application. WordPress is integrated through a separate connector plugin; the connector is not the product itself.

## Planned stack

- Backend: Laravel
- Database: MySQL
- Cache / Queue: Redis
- API: Laravel REST API
- Frontend: Next.js + React
- WordPress integration: Veylora SitePilot Connector
- Local development: Windows + D: drive; WordPress test environment will be added later

## Architecture rule

The central SitePilot application owns product logic, orchestration, automation, permissions, audit trails and AI-assisted decision making. The WordPress connector provides a secure capability bridge to an individual WordPress installation.

## Development status

Phase 4 — Monitoring backend foundation in progress.

- Phase 0: Specification
- Phase 1: Platform foundation (Laravel, Next.js, auth, organizations, sites, API)
- Phase 2: Site management (sites, metadata, connection intents, dashboard)
- Phase 3: Connector contract (connection protocol, heartbeat, capabilities, registration)
- Phase 4: Monitoring backend foundation (health checks, uptime, incidents, metrics, scheduler)
- Phase 5: Maintenance Operations (planned)
- Phase 6: Automation (planned)
- Phase 7: AI (planned)
- Phase 8: Production Hardening (planned)
- Phase 9: SaaS (planned)

No production connector or WordPress test site is being built yet.
