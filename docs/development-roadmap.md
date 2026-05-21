# Development Roadmap

Last updated: 2026-05-21

## Status

The Google Docs Sync Admin Actions implementation is complete in code. The self-managed Google onboarding, Drive-like My Drive/shared drive document browser, dedicated Sources submenu, page target support, source filters, HTML ZIP media import, admin frontend structure refactor, Drive modal polish, and OAuth JSON import are also complete in code.

## Phase Summary

| Phase | Title | Status |
| --- | --- | --- |
| 1 | Foundation and Data Model | Complete |
| 2 | Google OAuth and Drive Client | Complete |
| 3 | Sync Service and Import Pipeline | Complete |
| 4 | Post Edit and List Table Entry Points | Complete |
| 5 | Central Admin App, Scheduling, Logs | Complete |
| 6 | Verification and Release Hardening | Complete in code, local PHP validation blocked |
| 7 | Sources Submenu and Media Sync | Complete in code, local PHP validation blocked |
| 8 | Custom Drive Document Browser | Complete in code, local PHP validation blocked |
| 9 | Drive-Like Browser Navigation | Complete in code, local PHP validation blocked |
| 10 | Shared Drive Browser and List Refresh | Complete in code, local PHP validation blocked |
| 11 | Radix and WordPress-Native Frontend Refactor | Complete in code, local PHP validation blocked |
| 12 | Drive Modal Polish and OAuth JSON Import | Complete in code, local PHP validation blocked |

## What Is Shipped

- Google settings and token storage
- document inspection and source linking
- source table and bulk sync
- dedicated `DocSync WP > Sources` submenu with search, post type, status, and pagination filters
- self-managed Google setup wizard with redirect URI copy, setup checks, and Google Cloud links
- post/page edit and list-table entry points
- optional `page` target support while keeping `post` always enabled
- Radix-backed source modal with Drive-like My Drive/shared drive folder navigation, current-folder search, pagination, explicit selection controls, selection preview, and advanced URL/file ID entry
- compact source modal close control and table sizing that avoids desktop horizontal overflow
- feature-first admin frontend structure with split API modules, feature hooks, shared UI atoms, and WordPress-native REST/i18n/a11y/component integration
- browser-only Google OAuth client JSON import that fills setup credentials and warns on redirect URI mismatch
- post list-table actions update visible source status after link/sync and auto-refresh after new draft creation
- entry-specific CSS files with shared and component partials for admin setup, Sources, and post sync UI maintenance
- HTML ZIP import that sideloads exported images into Media Library and rewrites content URLs
- attachment dedupe by Google file ID, asset path, and image hash
- per-post sync state and error tracking
- scheduled sync hooks
- uninstall cleanup for settings, tokens, and cron events
- installable GitHub workflow artifact layout validation

## Remaining Work

The implementation is usable, but a few follow-ups remain open for future iterations:

- add automated PHP and JS tests
- add richer sync history if support needs increase
- decide whether to add block-perfect conversion beyond sanitized HTML import
- decide whether to add a separate audit log table
- decide whether to add a managed Google connector service for true nontechnical one-click onboarding

## Release Readiness Notes

- repository snapshot and docs are aligned with the current code
- current one-click improvement is plugin-only and still requires a self-managed Google Cloud app
- frontend lint blocks inline PHPCS suppression comments in plugin source
- workflow packaging now uploads installer-ready artifact contents instead of a nested ZIP
- local verification is blocked here because PHP and Composer are missing
- production validation still needs the normal PHP toolchain before release packaging
