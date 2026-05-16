# Security Policy

## Reporting a Vulnerability

Do **not** disclose vulnerabilities publicly.

Report privately to:

- security@starisian.com

Please include:

- affected component/file
- impact and exploit scenario
- reproduction steps
- suggested remediation (if available)

## Security Model Summary

### Trust Boundaries

- **Untrusted:** browser payloads, request headers, query/body input
- **Trusted with verification:** authenticated WordPress context and nonce-protected REST requests
- **Internal-only:** database schema internals, cache/session keys, logging internals

### Validation Expectations

- sanitize all external input
- validate types and required identity fields
- escape output in admin/UI contexts
- use prepared statements for all SQL

### Sensitive Flows

- REST payload ingestion (`/star-uec/v1/log`)
- IP and geolocation enrichment
- session and cache key derivation
- snapshot storage and retrieval APIs

## Disclosure Handling Targets

- acknowledgement within 5 business days
- remediation timeline based on severity and exploitability

## Out of Scope

- social engineering without code exploit
- dependency-only findings without practical exploit path in this repository
