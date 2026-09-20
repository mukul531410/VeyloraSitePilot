# Veylora SitePilot — Security Architecture

## Security objectives

Protect:
1. Site credentials
2. Customer data
3. Remote execution capabilities
4. Operational history
5. Platform accounts

## Trust boundaries

There are three primary trust zones:

1. Browser → SitePilot
2. SitePilot → WordPress Connector
3. SitePilot internal services

Each boundary must authenticate and authorize requests.

## Connector security

- per-site identity
- scoped credentials
- revocation
- rotation
- request authentication
- replay protection
- capability checks
- strict payload validation
- rate limits
- audit records

## Web application security

- secure password/session handling
- CSRF protection where applicable
- input validation
- output encoding
- authorization policies
- rate limiting
- secure headers
- dependency updates
- secret management

## Sensitive data

Never commit:
- passwords
- API tokens
- private keys
- encryption keys
- production .env files

Encrypt sensitive connection credentials at rest.

## Authorization

Use organization/site scoped permissions.

A user who can view Site A must not automatically gain access to Site B.

## Audit

Audit logs should be append-oriented and protected from ordinary user mutation.

## Failure policy

Security uncertainty fails closed:
- unknown authorization → deny
- unknown capability → deny
- invalid/expired connection → deny
- verification failure → do not report success

## Future hardening

Before production:
- threat model review
- dependency/security scanning
- penetration testing
- secret rotation procedure
- incident response procedure
- backup/restore testing
