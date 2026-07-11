# Development Roadmap

Last updated: 2026-07-10

## Status

The Google Docs Sync Admin Actions implementation is complete in code. The self-managed Google onboarding, Drive-like My Drive/shared drive document browser, dedicated Sources submenu, Logs submenu, page target support, source filters, HTML ZIP media import, Gutenberg block conversion, default Gutenberg layout presets, layout reliability fixtures, Elementor presets, explicit output choice, legacy Elementor upgrade path, standalone native image block rendering, admin frontend structure refactor, Drive modal polish, OAuth JSON import, fullscreen picker, dense picker layout, infinite Drive loading, non-blocking background draft sync, oversized Google Doc fallback, inline editor sync-completion apply, progressive empty-draft large-doc writes, large-doc stuck-state recovery, bounded sync diagnostic logging, optional telemetry, devcontainer runtime, first-pass WordPress.org release packaging, GitHub Release ZIP asset packaging, WordPress.org pre-review rename/package scoping, listing artwork refresh, and release hardening with admin loading/empty-state polish are also complete in code.

## Phase Summary

| Phase | Title | Status |
| --- | --- | --- |
| 1 | Foundation and Data Model | Complete |
| 2 | Google OAuth and Drive Client | Complete |
| 3 | Sync Service and Import Pipeline | Complete |
| 4 | Post Edit and List Table Entry Points | Complete |
| 5 | Central Admin App, Scheduling, Logs | Complete |
| 6 | Verification and Release Hardening | Complete in code |
| 7 | Sources Submenu and Media Sync | Complete in code |
| 8 | Custom Drive Document Browser | Complete in code |
| 9 | Drive-Like Browser Navigation | Complete in code |
| 10 | Shared Drive Browser and List Refresh | Complete in code |
| 11 | Radix and WordPress-Native Frontend Refactor | Complete in code |
| 12 | Drive Modal Polish and OAuth JSON Import | Complete in code |
| 13 | Admin UI Fixes and Gutenberg Sync Content | Complete in code |
| 14 | Non-Blocking Picker and Background Sync Feedback | Complete in code |
| 15 | Oversized Google Doc Export Fallback | Complete in code |
| 16 | WordPress.org Readiness First Pass | Complete in code, final validation pending |
| 17 | 1.0.3 Release Hardening | Complete in code, external validation pending |

## What Is Shipped

- Google settings and token storage
- document inspection and source linking
- source table and bulk sync
- dedicated `Brasth Document Sync > Sources` submenu with search, post type, status, and pagination filters
- dedicated `Brasth Document Sync > Logs` submenu with search, source, level, status, step, quick chips, safe clear actions, and pagination filters
- skeleton first-load states for Sources, Logs, and the Drive browser, plus clearer log empty states for filtered and source-specific edge cases
- self-managed Google setup wizard with redirect URI copy, setup checks, next-action guidance, step states, and Google Cloud links
- post/page edit and list-table entry points
- optional `page` target support while keeping `post` always enabled
- Radix-backed source modal with Drive-like My Drive/shared drive folder navigation, current-folder search, pagination, explicit selection controls, selection preview, and advanced URL/file ID entry
- compact source modal close control and table sizing that avoids desktop horizontal overflow
- feature-first admin frontend structure with split API modules, feature hooks, shared UI atoms, and WordPress-native REST/i18n/a11y/component integration
- browser-only Google OAuth client JSON import that fills setup credentials and warns on redirect URI mismatch
- synced Google Docs content is saved as Gutenberg block markup for common document structures, with native `core/image` blocks for standalone images and `core/html` fallback for unsupported nodes
- post list-table actions update visible source status after link/sync and auto-refresh after new draft creation
- post list-table draft creation queues background sync, closes the picker quickly, polls source status, shows admin toasts, and refreshes visible list-table content when the sync reaches a terminal state
- Drive browser pagination now uses infinite loading with 50-result pages
- source REST endpoints support optional `syncMode` while preserving inline behavior by default
- single-source background sync uses WordPress native single-event cron and existing per-post sync locking
- oversized Google Docs warn from Drive metadata and automatically retry through Docs API fallback only after Drive export-size failures
- empty draft oversized-doc fallback imports progressively save sanitized block content before final sync completion
- long-running syncs expose heartbeat metadata, tolerate transient polling failures, and recover abandoned `syncing` states into retryable errors
- per-source sync events retain the latest 50 diagnostic-safe entries for queued, progress, fallback, terminal, error, and stale-recovery states
- Setup sync defaults include a default synced layout dropdown for Gutenberg imports, backed by Clean Article, Documentation, and Plain Blocks presets
- layout fingerprints force Gutenberg re-conversion when the effective preset changes even if Google metadata is unchanged
- post editor background sync completion applies synced content directly when no unsaved edits are present and otherwise offers an explicit apply action
- terminal sync states no longer show stale 100 percent progress bars after reload
- WordPress.org `readme.txt`, `LICENSE`, listing assets, source-inclusive distribution rules, and current `1.1.3` release metadata
- GitHub Release workflow that builds `brasth-document-sync-for-google-docs-v<version>.zip`, uploads it as an Actions artifact, and attaches it to the release asset list
- privacy policy suggested content for Google OAuth, Drive API, Docs API, stored credentials/tokens, linked metadata, imported media, retention, and uninstall behavior
- legacy Google Picker settings and unused Markdown/CommonMark runtime path removed before public release
- common REST login/nonce/settings permission checks centralized
- Google account connection disclosure and disconnect confirmation added to setup UI
- setup wizard and README now require Drive API and Docs API in the same Google Cloud project
- entry-specific CSS files with shared and component partials for Setup, Sources, Logs, post sync, source modal, and Drive browser UI maintenance
- HTML ZIP import that sideloads exported images into Media Library and rewrites content URLs
- attachment dedupe by Google file ID, asset path, and image hash
- per-post sync state and error tracking
- scheduled sync hooks
- uninstall cleanup for settings, tokens, and cron events
- installable GitHub workflow artifact layout validation

## Remaining Work

The implementation is usable, but a few follow-ups remain open for future iterations:

- add automated JS tests and broaden PHP tests beyond fixture verifiers
- run official Plugin Check and readme validator in a release test site
- add final WordPress.org screenshots before SVN submission if product screenshots are available
- consider a custom sync-log table if support needs search-heavy or long-retention diagnostics
- improve block-perfect conversion for complex Google Docs layouts if needed
- add preview/gallery UI in the 1.2.x line
- broaden fixtures around Docs API fallback conversion beyond current large-doc fallback cases
- decide whether to add a separate audit log table
- decide whether to add a managed Google connector service for true nontechnical one-click onboarding

## Release Readiness Notes

- repository snapshot and docs are aligned with the current code
- GitHub workflow JavaScript actions use Node 24-compatible major versions
- current one-click improvement is plugin-only and still requires a self-managed Google Cloud app
- frontend lint blocks inline PHPCS suppression comments in plugin source
- workflow packaging now uploads installer-ready GitHub Release ZIP assets instead of a staged folder artifact
- local PHP/frontend verification and devcontainer runtime verification are available for release candidates
- production validation still needs CI PHP checks, Plugin Check, the official readme validator, and manual Google sync scenarios before SVN submission
