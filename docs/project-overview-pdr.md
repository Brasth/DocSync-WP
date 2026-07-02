# Project Overview and PDR

Last updated: 2026-06-29

## Purpose

Brasth Document Sync for Google Docs syncs Google Docs into WordPress posts, pages, and enabled public custom post types. The completed implementation centers on admin-driven linking and sync, not bidirectional collaboration.

## Delivered Scope

- Google OAuth connection per WordPress user
- site-level Google OAuth client settings
- document browsing through Drive API plus advanced URL or raw file ID inspection
- attach source to an existing WordPress target
- create a synced draft from a Google Doc
- post/page edit screen actions: link, change, sync now, detach
- list-table actions: add sync doc, inline link/sync, status column
- setup admin page for settings and account state
- dedicated Sources submenu for source list, filters, and bulk sync
- HTML ZIP import with Media Library image sideloading and URL rewriting
- scheduled sync with WP-Cron
- sync status and error persistence in post meta

## Product Decisions Reflected In Code

- Google Docs is the source of truth.
- OAuth client credentials are configured per site.
- Tokens are stored per WordPress user.
- Default export format is `html_zip`.
- Brasth Document Sync uses `drive.readonly` and a custom Drive browser so users can search and select visible Google Docs without Google Picker.
- Supported targets are `post`, optional `page`, plus enabled public custom post types the current user can edit.
- Uninstall keeps synced posts by default.

## Functional Requirements

| Requirement | Status |
| --- | --- |
| Connect Google account | Implemented |
| Disconnect Google account | Implemented |
| Browse Docs via Drive API, inspect URL or file ID | Implemented |
| Link Doc to existing post | Implemented |
| Create synced draft from Doc | Implemented |
| Sync linked post now | Implemented |
| Sync all changed sources | Implemented |
| Scheduled sync | Implemented |
| Source status visibility in admin | Implemented |
| Capability checks on post actions | Implemented |
| Imported Google Docs images in Media Library | Implemented |

## Non-Functional Requirements

| Requirement | Status |
| --- | --- |
| Encrypt secrets and tokens | Implemented |
| Validate REST nonce and login state | Implemented |
| Prevent duplicate sync runs | Implemented |
| Skip unchanged Docs | Implemented |
| Sanitize imported content | Implemented |
| Keep admin UI within WordPress patterns | Implemented |

## Completion Criteria

- admin can configure Google OAuth credentials
- user can connect their Google account
- user can link or create a synced post from Doc source selection
- post/page edit and list-table entry points work
- manual sync updates post content and sync state
- synced images use local WordPress attachment URLs
- scheduled sync is registered only when enabled
- local verification blockers are documented

## Known Environment Blocker

Local verification in this checkout is blocked because:

- `php` is unavailable
- `composer` is unavailable
- `vendor/autoload.php` is not present until `composer install` runs

## Open Follow-Ups

- Decide whether to add automated PHP and JS tests.
- Decide whether to add richer sync history beyond last status and last error.
- Decide whether to add a managed Google connector service for simpler setup.
- Validate Elementor preset edge cases from real agency documents after the 1.1.2 preset release.
- Choose the primary target niche (Elementor agencies vs. news publishers) before Phase 2 case studies.
- Lock the Free/Pro tier boundary before writing Phase 2 feature-gating code.
- Validate whether to register built-in presets as global block patterns in a later release.

## Roadmap and Release Process

See `docs/project-roadmap.md` for the phased development roadmap, success metrics, and open decisions. See `docs/deployment-guide.md` for the release cadence, pre-release checklist, and WordPress.org release procedure.
