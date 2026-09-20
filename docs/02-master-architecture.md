# Veylora SitePilot — Master Architecture

## 1. High-level architecture

```
                    Veylora SitePilot
                           |
             +-------------+-------------+
             |                           |
       Next.js / React              Laravel API
             |                           |
             |                 +---------+---------+
             |                 |                   |
             |               MySQL               Redis
             |                 |                   |
             |                 +---------+---------+
             |                           |
             |                     Queue / Workers
             |                           |
             +------------- Secure API -+
                                         |
                              Site Connector
                                         |
                                  WordPress Site
```

## 2. Responsibilities

### Next.js / React
Owns presentation and user interaction.

It must not contain authoritative business rules that belong to the backend.

### Laravel
Owns:

- authentication and authorization;
- organizations and users;
- site records;
- connection state;
- business rules;
- automation orchestration;
- task lifecycle;
- policy enforcement;
- API contracts;
- audit logging;
- notifications;
- AI orchestration;
- background job dispatching.

### MySQL
Owns durable application state.

### Redis
Owns transient/cache/queue-oriented workloads such as:

- queues;
- locks;
- rate-limit counters;
- short-lived cache;
- job coordination.

Redis is not the system of record.

### Connector
The future WordPress connector owns WordPress-specific communication and capability execution.

It should expose narrowly scoped capabilities instead of exposing arbitrary remote code execution.

## 3. Request categories

### Synchronous
Used for fast operations such as:

- authentication;
- dashboard reads;
- configuration;
- small metadata operations.

### Asynchronous
Used for:

- site scans;
- inventory collection;
- monitoring;
- update checks;
- backups;
- performance tests;
- security scans;
- remote maintenance;
- AI analysis;
- notifications.

## 4. Execution model

```
User / Scheduler
      ↓
Create Intent
      ↓
Policy Check
      ↓
Permission Check
      ↓
Queue Job
      ↓
Worker
      ↓
Connector/API Capability
      ↓
Verification
      ↓
Persist Result
      ↓
Audit + Notification
```

## 5. Environment separation

Development, staging and production must have separate credentials, databases, queues and secrets.

No production credentials belong in source control.

## 6. Repository structure

```
VeyloraSitePilot/
├── backend/
├── frontend/
├── docs/
├── tests/
├── scripts/
├── .env.example
├── .gitignore
└── README.md
```
