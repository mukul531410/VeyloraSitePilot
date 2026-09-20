# Veylora SitePilot — Module Architecture

## Core modules

### 1. Identity
- users
- organizations
- roles
- permissions
- sessions

### 2. Sites
- site records
- site metadata
- environment
- connection status
- tags
- ownership

### 3. Connector
- connection handshake
- credentials/tokens
- capability discovery
- connector version
- heartbeat
- connection health

### 4. Inventory
- WordPress core
- PHP
- plugins
- themes
- configuration metadata
- available updates

### 5. Monitoring
- uptime
- response status
- SSL
- health checks
- scheduled scans
- incident detection

### 6. Performance
- response timing
- TTFB
- page performance measurements
- resource/asset observations
- historical trends

### 7. Security
- SSL state
- vulnerable/outdated component signals
- configuration checks
- security events

### 8. Maintenance
- tasks
- maintenance plans
- update operations
- backup operations
- cleanup operations
- verification

### 9. Automation
- triggers
- conditions
- actions
- schedules
- approval requirements
- execution policies

### 10. Notifications
- in-app
- email later
- severity
- notification preferences

### 11. Audit
- actor
- action
- target
- timestamp
- policy decision
- result
- correlation/request ID

### 12. AI
AI is an orchestration and analysis layer, not an authorization layer.

Potential capabilities:

- issue summarization;
- root-cause hypotheses;
- maintenance recommendations;
- change impact analysis;
- task planning;
- natural-language site reports.

### 13. Reporting
- site health summaries
- maintenance history
- incidents
- performance trends
- operational reports

### 14. Billing
Reserved for SaaS commercialization and will remain isolated from core site-operation logic.

## Module dependency rule

Higher-level modules may consume lower-level services through explicit contracts. Modules must not bypass authorization, audit or policy services for convenience.
