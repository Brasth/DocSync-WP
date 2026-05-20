# Project Changelog

Last updated: 2026-05-20

## 2026-05-20 - Custom Drive Document Browser

Status: completed in codebase

Replaced Google Picker with a DocSync WP Drive browser:

- added `GET /docsync-wp/v1/documents` for server-side Google Docs listing
- switched OAuth requests to `https://www.googleapis.com/auth/drive.readonly`
- added account reconnect state for users still connected with the old scope
- replaced the Picker modal action with search, pagination, selected-row state, and visible Doc preview
- kept pasted Google Docs URL and raw file ID under advanced linking
- removed the frontend Google Picker token helper
- removed Picker API key/app ID from setup requirements while keeping legacy settings fields in REST/config responses

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- local PHP verification is blocked because `php` and `composer` are unavailable

## 2026-05-20 - Google Picker Origin Troubleshooting

Status: completed in codebase

Improved the Picker origin mismatch UX:

- surfaced Google Identity Services token errors in the Picker modal
- added an explicit Authorized JavaScript origins hint for `invalid_client` origin mismatches
- clarified setup copy that the JavaScript origin and redirect URI must be saved in the same OAuth web client
- added README troubleshooting for `invalid_client` plus `no registered origin`

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes

## 2026-05-20 - Sources Submenu and Media Sync

Status: completed in codebase

Implemented the Sources and media sync slice:

- moved linked sources to `DocSync WP > Sources`
- kept `DocSync WP > Setup` focused on Google account and settings
- added source search, post type, sync status, and pagination filters
- added selectable `page` target support while keeping `post` required
- added page-specific list table column hooks
- switched source sync to Google Drive HTML ZIP export
- imported local exported images into WordPress Media Library
- rewrote synced content image URLs to local attachment URLs
- deduped attachments by Google file ID, asset path, and image hash
- normalized legacy `markdown` source metadata to `html_zip` on future source saves

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- local PHP verification is blocked because `php` and `composer` are unavailable

## 2026-05-19 - Google Setup Onboarding

Status: completed in codebase

Improved the standalone Google onboarding path:

- replaced the raw Google settings card with a setup wizard
- added setup progress, redirect URI copy, Google Cloud links, and saved-setting checks
- exposed saved setup readiness through settings REST responses and admin config
- added `connection_mode` with current `self_managed` support
- blocked `Connect Google` until OAuth client ID and client secret are saved
- linked Picker app ID help to Google Cloud IAM & Admin settings
- added Authorized JavaScript origin guidance for Google Picker sign-in
- cleaned up advanced source modal controls and list-table inline notices
- kept Picker as the default document linking path
- moved pasted Google Docs URL and raw file ID inputs into advanced linking

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- local PHP verification is blocked because `php` and `composer` are unavailable

## 2026-05-18 - Radix UI and PHPCS Hardening

Status: completed in codebase

Updated the admin UI and coding guardrails:

- added Radix Dialog and Tabs primitives for the Google Doc source modal
- kept React runtime externalized to WordPress `wp.element`
- added a frontend lint guard that fails on inline PHPCS suppression comments in plugin source
- removed existing inline PHPCS suppressions from PHP source
- moved unavoidable WordPress standards exceptions into narrow `phpcs.xml.dist` rules
- changed encrypted binary payload storage from base64 fields to hex fields while retaining legacy decode support when Sodium is available
- switched manifest loading to `wp_json_file_decode`

## 2026-05-18 - Installable ZIP Artifact Fix

Status: completed in codebase

Fixed GitHub packaging artifacts so downloaded workflow artifacts are directly installable by WordPress instead of containing a nested plugin ZIP. Added a workflow validation gate for `docsync-wp/docsync-wp.php`, `vendor/autoload.php`, and `build/manifest.json`.

## 2026-05-18 - PHPCS CI Fixes

Status: completed in codebase

Patched PHPCS findings from CI: missing REST request docblock, docblock spacing, assignment-in-condition warnings, assignment alignment warnings, and `count()` inside a loop condition.

## 2026-05-18 - Google Docs Sync Admin Actions

Status: completed in codebase

Implemented the admin actions slice for Google Docs sync:

- central DocSync admin dashboard
- Google OAuth connect/disconnect flow
- document inspection by Picker, URL, or file ID
- post edit meta box with link, change, sync now, and detach actions
- post list-table top action and inline row actions
- source status column in list tables
- `sync-all` support for changed sources
- WP-Cron registration and batch sync execution
- sync state and error persistence in post meta

Architecture notes:

- REST namespace is `docsync-wp/v1`
- Google tokens are stored per user and encrypted
- site settings are stored in `docsync_wp_settings`
- source state lives in post meta on the linked post
- Markdown was the only supported export format in this implementation. Superseded on 2026-05-20 by HTML ZIP import.

Verification status:

- code paths were reviewed against the current source tree
- local PHP verification could not be executed in this checkout because `php` and `composer` are unavailable
- `vendor/autoload.php` is absent until `composer install` is run

Operational note:

- WP-Cron is traffic-driven. Production sites still need a real server cron if sync timing matters.

## 2026-05-18 - Docs Refresh

Added practical docs for the shipped implementation:

- `docs/codebase-summary.md`
- `docs/system-architecture.md`
- `docs/project-overview-pdr.md`
- `docs/code-standards.md`
- `docs/development-roadmap.md`

These files replace the old research-only view with implementation-level notes.
