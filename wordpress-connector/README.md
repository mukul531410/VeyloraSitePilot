# Veylora SitePilot Connector

This first-party WordPress plugin is a transport and read-only capability bridge to the central SitePilot application. It does not make policy decisions or execute remote operations.

## Installation and connection

Install `wordpress-connector/` as a normal plugin, activate it, then open **Settings → SitePilot Connector**. Enter the SitePilot origin (production URLs must use HTTPS) and the one-time connection intent created for the site in SitePilot. The plugin exchanges that intent at `POST /api/v1/connector/register` and stores the returned bearer token and HMAC credential. The connection page does not display either secret.

WordPress options store ordinary connection metadata separately from credentials. Credentials are encrypted with AES-256-GCM using a key derived from the WordPress `AUTH` salt. Keep WordPress salts stable and protect database backups; changing those salts makes the saved connector credentials unreadable. Secrets and signatures are never written to logs or returned from the plugin's admin page.

## Signing and API use

Capability reports use the issued HMAC credential. The plugin generates a 16-byte cryptographic nonce, a Unix UTC timestamp, and the exact JSON body before signing. Its canonical input matches Laravel's `ConnectorRequestCanonicalizer`: the `SP-HMAC-SHA256` version line, followed by uppercase method, request path, timestamp, nonce, and the lowercase hexadecimal SHA-256 body digest, joined by newlines. The signature is HMAC-SHA256 encoded as unpadded Base64URL. Requests include `X-SP-Credential-Id`, `X-SP-Timestamp`, `X-SP-Nonce`, `X-SP-Signature`, and `Authorization: SP-HMAC <credential-id>`.

The existing heartbeat and inventory endpoints still use the bearer token returned during registration. The plugin uses the WordPress HTTP API, verifies TLS, disables redirects, and returns only generic transport errors.

## Capability and data model

The plugin reports support for `read.wordpress`, `read.plugins`, and `read.themes`. These are support claims only; SitePilot controls the separate `enabled` grant. Inventory reads the existing capability endpoint and sends only categories SitePilot reports as effective. Core inventory contains WordPress/PHP versions. Plugin and theme inventory contains their contract fields and completeness declarations. No capability is granted by the plugin.

Use the plugin's **Sync** action to send a capability report, heartbeat, and available inventory. Heartbeat uses only the backend's current fields: connector version, WordPress version, PHP version, and `online` status. The backend assigns the heartbeat timestamp.

## Intentionally not implemented

There is no cache clearing, update execution, backup/restore, arbitrary PHP execution, job worker, operation orchestration, approval handling, policy engine, automation engine, AI, or dashboard. SitePilot remains the source of truth for grants, authorization, approvals, and operation lifecycles.

## Tests

The focused suite uses the repository's existing PHPUnit installation and a small WordPress API stub; it does not require WordPress core, WP-CLI, Composer dependencies, or a database:

```sh
php ../backend/vendor/bin/phpunit -c phpunit.xml.dist
```
