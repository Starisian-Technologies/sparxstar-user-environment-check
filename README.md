# SPARXSTAR User Environment Check

**Repository:** `Starisian-Technologies/sparxstar-user-environment-check`  
**Package:** `starisian/sparxstar-user-environment-check`  
**License:** Proprietary (see `LICENSE` and `LICENSE.md`)

SPARXSTAR User Environment Check (UEC) is a WordPress plugin that captures high-fidelity client environment diagnostics (device/network/browser/runtime capabilities), sends them to a secured REST endpoint, sanitizes and normalizes payloads, and stores snapshots in a site-scoped custom table.

## Repository Role

This repository contains the production plugin implementation for:

- client-side environment collection and profiling
- WordPress REST ingestion and persistence
- multisite-safe schema lifecycle and cleanup
- admin diagnostics and GeoIP configuration
- stable public consumer API (`StarUserEnv`)

## Key Capabilities

- Client-first diagnostics (client is source of truth)
- Consent-aware identifier collection path
- Snapshot persistence with cache and session integration
- Optional GeoIP enrichment (ipinfo or MaxMind)
- Multisite-aware activation/deactivation and uninstall

## Requirements

- PHP 8.2+
- WordPress 6.8+ (multisite supported)
- Composer
- Node.js 20+ and pnpm 8.6.0+

## Installation / Setup

```bash
composer install
corepack enable
corepack prepare pnpm@8.6.0 --activate
npm install
```

> Note: This repository currently contains pre-existing lint/static-analysis issues unrelated to this documentation hardening pass.

## Validation Commands

### PHP

```bash
composer run lint
composer run analyze
composer run test:unit
composer test
```

### Frontend

```bash
pnpm run lint
pnpm run build
```

## Usage

### Public PHP API

Use only the facade class:

`\Starisian\SparxstarUEC\StarUserEnv`

Examples:

- `StarUserEnv::get_snapshot()`
- `StarUserEnv::get_network_type()`
- `StarUserEnv::get_user_device()`
- `StarUserEnv::get_geolocation()`

### REST Endpoint

`POST /wp-json/star-uec/v1/log`

- requires `X-WP-Nonce` (`wp_rest`) for snapshot ingestion
- accepts JSON payload from client collector pipeline

Recorder telemetry endpoint:

`POST /wp-json/star-uec/v1/recorder-log`

## Development Workflow

1. Create feature branch.
2. Keep changes scoped and incremental.
3. Run lint/analyze/test/build commands.
4. Update docs for API/behavior changes.
5. Open PR with security and testing notes.

See also:

- `CONTRIBUTING.md`
- `DEVELOPMENT.md`
- `ARCHITECTURE.md`
- `SECURITY.md`
- `CHANGELOG.md`
