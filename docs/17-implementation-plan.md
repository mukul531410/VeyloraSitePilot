# Veylora SitePilot — Implementation Plan

## Phase 0 — Specification
- product vision
- architecture
- module boundaries
- database design
- API contract
- security model
- automation model
- AI boundaries
- testing strategy

## Phase 1 — Platform Foundation
### Phase 1A — Repository and environment
1. Clone/use D:\VeyloraSitePilot.
2. Initialize Laravel backend.
3. Initialize Next.js frontend.
4. Add root-level Git workflow and documentation.
5. Create environment templates.
6. Confirm PHP, Composer, Node and npm versions.
7. Configure local MySQL database.
8. Configure Redis.
9. Add basic health endpoints.
10. Commit foundation.

## Phase 1B — Backend foundation
- Laravel configuration
- database connection
- Redis connection
- API routing/versioning
- authentication
- organization/user models
- authorization foundation
- logging
- exception/error format
- request IDs
- queue configuration
- scheduler configuration

## Phase 1C — Frontend foundation
- Next.js app
- TypeScript
- API client
- authentication flow
- layout/navigation
- dashboard shell
- error/loading states

## Phase 1D — First vertical slice
Build one complete path:

Create organization → create site → view site → site status.

Do not build every module simultaneously.

## Phase 2 — Site Management
- sites
- site metadata
- connection intents
- site state
- dashboard foundation

## Phase 3 — Connector Contract
- finalize connector protocol
- capability model
- heartbeat
- inventory contract
- operation contract
- verification contract

Only now create the local WordPress test environment.

## Phase 4 — Monitoring
- backend monitoring foundation: health checks, uptime, incidents, metrics, scheduler
- server-side HTTPS certificate inspection
- inventory, notifications, and security findings remain pending

## Phase 5 — Maintenance Operations
- update checks
- controlled plugin/theme/core operations
- backups
- cache operations
- verification

## Phase 6 — Automation
- scheduler
- rule engine
- policy engine
- approval flow
- retries
- locking

## Phase 7 — AI
- summaries
- diagnosis assistance
- recommendations
- plans
- approved execution assistance

## Phase 8 — Production Hardening
- security review
- load testing
- backup/restore
- observability
- rate limits
- deployment
- documentation

## Phase 9 — SaaS
- plans
- billing
- tenant limits
- usage
- onboarding
- white-label capabilities

## Git workflow
- main = stable
- feature branches for implementation
- small focused commits
- pull requests for meaningful changes
- no secrets in commits
- documentation updated with architectural changes
