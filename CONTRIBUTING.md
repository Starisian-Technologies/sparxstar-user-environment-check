# Contributing Guide

Thank you for contributing to SPARXSTAR User Environment Check.

## Access Model

This is a managed/private repository. Contributions are accepted from authorized team members and approved collaborators.

## Contribution Rules

- Keep architecture intact; no major redesign in maintenance changes.
- Use smallest safe change that fully solves the issue.
- Preserve multisite behavior and capability checks.
- Never add unprefixed WordPress globals.
- Sanitize → validate → escape for all external input paths.
- Prepare every SQL query.

## Branching

- Do not commit directly to `main`.
- Use descriptive branches (`fix/...`, `docs/...`, `chore/...`).

## Required Checks Before PR

Run:

```bash
composer run lint
composer run analyze
composer run test:unit
pnpm run lint
pnpm run build
```

If a command fails due an existing repository baseline issue, call it out in the PR and confirm your change did not introduce new failures.

## Pull Request Expectations

Include in every PR:

- summary of changes
- rationale (why)
- testing performed
- security impact (if any)
- docs updated (if behavior/public API changed)

## Security Reports

Do not open public issues for vulnerabilities. Follow `SECURITY.md`.
