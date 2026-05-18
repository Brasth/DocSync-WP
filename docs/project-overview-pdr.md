# Project Overview and PDR

Last updated: 2026-05-18

## Purpose

DocSync WP syncs Google Docs into WordPress posts. The completed implementation centers on admin-driven linking and sync, not bidirectional collaboration.

## Delivered Scope

- Google OAuth connection per WordPress user
- site-level Google client settings and Picker settings
- document inspection by Picker, URL, or raw file ID
- attach source to an existing post
- create a synced draft from a Google Doc
- post edit screen actions: link, change, sync now, detach
- list-table actions: add sync doc, inline link/sync, status column
- central admin dashboard for settings, account state, source list, and bulk sync
- scheduled sync with WP-Cron
- sync status and error persistence in post meta

## Product Decisions Reflected In Code

- Google Docs is the source of truth.
- OAuth client credentials are configured per site.
- Tokens are stored per WordPress user.
- Default export format is Markdown.
- Google Picker is preferred because it grants app access to the selected file.
- Supported targets are `post` plus enabled public custom post types the current user can edit.
- Uninstall keeps synced posts by default.

## Functional Requirements

| Requirement | Status |
| --- | --- |
| Connect Google account | Implemented |
| Disconnect Google account | Implemented |
| Inspect Docs via Picker, URL, or file ID | Implemented |
| Link Doc to existing post | Implemented |
| Create synced draft from Doc | Implemented |
| Sync linked post now | Implemented |
| Sync all changed sources | Implemented |
| Scheduled sync | Implemented |
| Source status visibility in admin | Implemented |
| Capability checks on post actions | Implemented |

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

- admin can configure Google credentials and Picker settings
- user can connect their Google account
- user can link or create a synced post from Doc source selection
- post edit and list-table entry points work
- manual sync updates post content and sync state
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
- Decide whether to add broader Drive scope as an optional future setting.
