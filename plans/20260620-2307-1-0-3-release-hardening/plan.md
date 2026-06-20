# 1.0.3 Release Hardening Plan

Status: completed in codebase

## Context

- Repository tags: `1.0.0`, `1.0.1`.
- User confirmed `1.0.2` shipped externally; repository `main` contains listing asset/tag work after `1.0.1`.
- Release target: GitHub release ZIP and WordPress.org/SVN.
- Release type: patch hardening, not new product architecture.

## Acceptance Criteria

- Plugin header, `DOCSYNC_WP_VERSION`, package metadata, and `readme.txt` stable tag all use `1.0.3`.
- `readme.txt` keeps no more than 5 tags and includes source/build instructions for bundled assets.
- CI runs JS lint, TypeScript typecheck, and production build for PR/push checks.
- Admin-facing hardcoded setup/source strings are wrapped for i18n where touched.
- Sources, Logs, and Drive browser first-load states use table-shaped skeleton loading where practical.
- Logs empty states distinguish no events, source-filter misses, level-filter misses, and possibly unlinked source IDs.
- Project changelog and roadmap document the `1.0.3` update.
- Available local checks pass; unavailable PHP checks are clearly reported.

## Scope

Included:

- release metadata
- WordPress.org readme compliance
- CI gate tightening
- focused admin i18n cleanup
- admin skeleton loading polish
- sync log empty/not-found edge-state copy
- release documentation updates

Out of scope:

- managed Google connector service
- custom sync-log database table
- sync engine rewrite
- broad PHP modularization
- WordPress.org SVN commit or GitHub tag creation

## Validation

- `pnpm lint`
- `pnpm typecheck`
- `pnpm build`
- `git diff --check`
- workflow YAML parse check
- release metadata check for `1.0.3`
- readme tag/source-instruction check
- PHP/Composer checks via CI or a machine with Composer available:
  - `composer validate --no-check-publish`
  - `composer lint`
  - `vendor/bin/phpcs -i`
- Official WordPress.org readme validator and Plugin Check before SVN release.

## Risks

- Local shell lacks `php` and `composer`; PHP validation must be completed in CI or release environment.
- Manual Google sync QA requires the provided staging WordPress site and Google OAuth test app after an installable package is built.

## Unresolved Questions

- None.
