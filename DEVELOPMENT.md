# Development Guide

## Local Setup

```bash
composer install
corepack enable
corepack prepare pnpm@8.6.0 --activate
pnpm install
```

## Build and Validation

### PHP checks

```bash
composer run lint
composer run analyze
composer run test:unit
```

### Frontend checks

```bash
pnpm run lint
pnpm run build
```

## Important Development Constraints

- Keep `sparxstar-user-environment-check.php` as a lean bootstrap file.
- Keep database logic in `src/core/SparxstarUECDatabase.php` (and snapshot repository class).
- Keep REST logic in `src/api/SparxstarUECRESTController.php`.
- Expose public consumption via `src/StarUserEnv.php`.
- Use `$wpdb->prefix`; never hardcode `wp_`.

## Documentation Expectations

When changing behavior:

- update docblocks in touched source
- update `README.md` and architecture/security docs where relevant
- record release-note entry in `CHANGELOG.md`
