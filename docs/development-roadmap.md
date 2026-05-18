# Development Roadmap

Last updated: 2026-05-18

## Status

The Google Docs Sync Admin Actions implementation is complete in code.

## Phase Summary

| Phase | Title | Status |
| --- | --- | --- |
| 1 | Foundation and Data Model | Complete |
| 2 | Google OAuth and Drive Client | Complete |
| 3 | Sync Service and Import Pipeline | Complete |
| 4 | Post Edit and List Table Entry Points | Complete |
| 5 | Central Admin App, Scheduling, Logs | Complete |
| 6 | Verification and Release Hardening | Complete in code, local PHP validation blocked |

## What Is Shipped

- Google settings and token storage
- document inspection and source linking
- central admin source table and bulk sync
- post edit and list-table entry points
- Radix-backed source modal and source mode tabs
- per-post sync state and error tracking
- scheduled sync hooks
- uninstall cleanup for settings, tokens, and cron events

## Remaining Work

The implementation is usable, but a few follow-ups remain open for future iterations:

- add automated PHP and JS tests
- add richer sync history if support needs increase
- decide whether to support broader Drive scopes
- decide whether to add block-perfect conversion or richer media import
- decide whether to add a separate audit log table

## Release Readiness Notes

- repository snapshot and docs are aligned with the current code
- frontend lint blocks inline PHPCS suppression comments in plugin source
- local verification is blocked here because PHP and Composer are missing
- production validation still needs the normal PHP toolchain before release packaging
