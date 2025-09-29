# Copilot Instructions for SPARXSTAR User Environment Check

## Project Overview

WordPress plugin for high-fidelity, client-side user environment diagnostics. Data is collected via JS libraries (e.g., device-detector.js, Network Information API), sent to a REST endpoint, sanitized, optionally enriched with GeoIP, and stored in a custom database table. The client is always the source of truth.

## Architecture & Key Components

-   **Main Loader:** `sparxstar-user-environment-check.php` (defines constants, registers hooks, initializes orchestrator)
-   **Orchestrator:** `src/SparxstarUserEnvironmentCheck.php` (singleton, wires services)
-   **REST API:** `src/api/SparxstarUECAPI.php` & `src/core/SparxstarUECRESTController.php` (handles `/star-sparxstar-user-environment-check/v1/log`, rate-limits, sanitizes, stores)
-   **Database:** `src/core/SparxstarUECDatabase.php` (all SQL isolated here, uses prepared statements)
-   **Public Facade:** `src/StarUserEnv.php` (static API for all external access; cache-backed)
-   **Asset Manager:** `src/AssetManager.php` (enqueues JS/CSS)
-   **Caching:** Defaults to PHP session, can switch to object cache via `sparxstar_env_cache_handler` filter

## Coding & Review Principles

-   **Single Responsibility:** Each class does one job (DB, REST, cache, admin, etc.)
-   **No Global Functions:** All logic is class-based; no direct superglobal access outside dedicated classes
-   **Stable Facade:** Only expose public API via `StarUserEnv`; never call internal classes from themes/plugins
-   **Strict Typing:** All PHP files start with `declare(strict_types=1);`
-   **Security:** Sanitize all input, prepare all DB queries, use nonces for admin actions
-   **Branching:** No direct commits to `main`; use feature branches and PRs

## Developer Workflows

-   **Build/Test:** Use Composer scripts:
    -   `composer run lint` (PHPCS)
    -   `composer run analyze` (PHPStan)
    -   `composer run test:unit` (PHPUnit)
    -   `composer test` (full lint/analyze/test)
-   **JS:** `device-detector-js` is the only dependency; no build pipeline by default
-   **Release:** Follow semantic versioning; update version, changelog, tag releases

## Integration & Filters

-   **Filters:**
    -   `sparxstar_env_cache_handler` (switch cache backend)
    -   `sparxstar_env_cache_ttl` (set cache duration)
    -   `sparxstar_env_geolocation_ttl` (set geolocation cache duration)
    -   `sparxstar_env_geolocation_lookup` (custom geolocation provider)
    -   `sparxstar_env_retention_days` (snapshot retention)
-   **REST Endpoint:** `/wp-json/star-sparxstar-user-environment-check/v1/log` (POST, nonce required, rate-limited)

## Client-Side API

-   Global `SPARXSTAR` JS object after DOMContentLoaded
-   Utility: `SPARXSTAR.State.device.type`, `SPARXSTAR.Utils.getNetworkBandwidth()`

## Prohibited Patterns

-   No global functions or variables
-   No direct $_POST/$\_GET/$\_SERVER access outside dedicated classes
-   No mixed responsibilities in classes
-   No misplaced hooks (must be inside classes)

## References

See `AGENTS.md`, `INSTRUCTIONS.md`, and `README.md` for full conventions. Always work within the existing architecture; do not introduce new patterns.
