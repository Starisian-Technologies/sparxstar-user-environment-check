# Architecture

## Responsibilities

SPARXSTAR User Environment Check is responsible for collecting, receiving, and storing client environment diagnostics for downstream product decisions and support workflows.

## Layer Boundaries

- **Bootstrap:** `sparxstar-user-environment-check.php`
  - constants, autoloader, lifecycle hook wiring, orchestrator bootstrap
- **Orchestration:** `src/SparxstarUserEnvironmentCheck.php`
  - WordPress hook registration and service startup
- **Dependency Wiring:** `src/core/SparxstarUECKernel.php`
  - service construction only (no side effects)
- **REST Layer:** `src/api/SparxstarUECRESTController.php`
  - route registration, request validation, payload normalization
- **Persistence Layer:**
  - `src/core/SparxstarUECDatabase.php`
  - `src/core/SparxstarUECSnapshotRepository.php`
- **Public Facade:** `src/StarUserEnv.php`
  - stable read API for external consumers
- **Admin:** `src/admin/SparxstarUECAdmin.php`
  - settings and support snapshot viewer
- **Session/Cache Helpers:** `src/includes/*`

## Namespace Conventions

Primary namespace: `Starisian\SparxstarUEC\*`

All plugin-defined global constants/functions are prefixed (`SPX_`, `spx_`, `sparxstar_`).

## Execution Flow

1. Plugin bootstrap defines constants and loads autoloader.
2. Orchestrator singleton initializes services.
3. Frontend scripts collect environment telemetry.
4. Client submits payload to REST endpoint.
5. REST controller validates nonce, enriches server-side data, normalizes payload.
6. Database layer upserts snapshot by fingerprint + device hash.
7. Facade methods read from runtime/session/cache/database in priority order.

## Dependency Expectations

- WordPress runtime APIs available.
- Optional Action Scheduler support.
- Optional GeoIP provider setup.
- Composer autoloader present for production use.

## Security Assumptions

- Nonce-protected snapshot ingestion endpoint.
- Client payload treated as untrusted until sanitized.
- IP/geolocation are sensitive operational data and must remain internal.

## Governance Assumptions

- Internal/private codebase with controlled contribution model.
- Production changes require PR review and validation command evidence.

## Architectural Invariants

- No direct SQL outside dedicated persistence classes.
- No direct superglobal use outside controlled helper/controller contexts.
- No bypass of public facade for external integrations.
- Multisite behavior must remain explicit and network-safe.
