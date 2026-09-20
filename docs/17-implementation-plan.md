# Veylora SitePilot — Implementation Plan

## Phase 1A — Repository and environment
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

## Phase 2 — Site connection foundation
Implement connection intent and connector contract without production remote actions.

## Phase 3 — Monitoring
Implement read-only inventory, health and heartbeat flows.

## Phase 4 — Operations
Implement controlled operations and verification.

## Phase 5 — Automation
Implement scheduler, rules, policy and approvals.

## Phase 6 — AI
Add analysis/recommendation features on top of deterministic data.

## Phase 7 — Production hardening
Security, performance, observability, recovery and deployment.

## Git workflow
- main = stable
- feature branches for implementation
- small focused commits
- pull requests for meaningful changes
- no secrets in commits
- documentation updated with architectural changes
