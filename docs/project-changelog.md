# Project Changelog

Last updated: 2026-06-28

## 2026-06-28 - Setup wizard and logs UX

- added Setup next-action panel for save credentials, connect, reconnect, or create first synced draft
- added setup step state badges: Manual, Complete, Needs action, Ready
- removed managed connector promotion from setup flow; kept self-managed OAuth only
- expanded `GET /sync-log` filters to `search`, `status`, and `step`
- added `DELETE /sync-log` with optional `post_id`, returning `{ cleared: number }`
- kept log clearing scoped to `_docsync_wp_sync_events`; source links, status, progress, credentials, and synced content stay intact
- reworked Logs with search-first filters, quick chips, summary metrics, recovery hints, and confirmed clear actions

## 2026-06-27 - 1.0.9 security hardening

- added shared DocSync use permission for administrators or users who can edit/create at least one enabled target post type
- required `X-WP-Nonce` for mutating REST requests and kept `_wpnonce` as a read-only `GET` fallback
- tightened source, Drive browser, document inspection, settings, and sync-log request validation
- limited post/list-table assets, notices, columns, and source metadata output to capable users
- added defensive sync diagnostic redaction for token-like values
- bumped plugin header version, `DOCSYNC_WP_VERSION`, `readme.txt` stable tag, package metadata, admin fallback config, and POT metadata to `1.0.9`
- documented local security audit results in `plans/reports/1.0.9-security-audit.md`

Unresolved:

- staging role matrix still needs execution before tag
- final manual Google OAuth and first-sync QA still needs a configured WordPress site and connected Google test account
- WordPress.org screenshot slot 6 still needs a real Sources-table capture from local/staging WordPress admin

## 2026-06-27 - 1.0.8 first successful sync onboarding

- reworked Setup into a checklist for Google APIs, exact redirect URI, OAuth credentials, account connection, and first synced draft handoff
- added connected-state Create synced draft links to the existing Posts list Add Sync Doc flow
- extended shared empty states with variants, actions, and decorative illustrations for Sources, Logs, and Drive browser screens
- polished admin UI alignment for setup loading, setup step badges, source log actions, and the Google Drive source modal
- added `languages/brasth-document-sync-for-google-docs.pot`, repeatable i18n scripts, script translation loading, and translated OAuth JSON parser errors
- bumped plugin header version, `DOCSYNC_WP_VERSION`, `readme.txt` stable tag, and package metadata to `1.0.8`

Unresolved:

- WordPress.org screenshot slot 6 still needs a real Sources-table capture from local/staging WordPress admin
- final manual Google OAuth and first-sync QA still needs a configured WordPress site and connected Google test account

## 2026-06-23 - 1.0.6 combined hardening/performance scope

- realigned the roadmap so `1.0.5` remains the released Elementor scope and `1.0.6` carries the combined hardening and performance patch
- bumped plugin header version, `DOCSYNC_WP_VERSION`, `readme.txt` stable tag, and package metadata to `1.0.6`
- split Setup, Sources, and Logs admin bundles into screen-specific Vite entries and manifests
- moved the Drive browser into a lazy post-sync modal bundle and removed the unused post-sync `wp-data` dependency
- aligned ZIP and deploy validation with the screen-specific Vite manifests
- limited the PR lint workflow `GITHUB_TOKEN` permissions to read-only contents access

## 2026-06-20 - 1.0.3 Release Hardening

Status: completed in codebase

Prepared the `1.0.3` patch release for GitHub ZIP and WordPress.org/SVN distribution:

- bumped the plugin header version, `DOCSYNC_WP_VERSION`, `readme.txt` stable tag, package metadata, and admin fallback config to `1.0.3`
- restored WordPress.org source/build instructions in `readme.txt`
- reduced WordPress.org readme tags to the documented 5-tag limit
- added `1.0.3` and `1.0.2` readme changelog entries for release continuity
- added CI checks for readme tag/source-instruction invariants
- added `pnpm typecheck` and `pnpm build` to PR JS/TS checks
- completed a focused admin i18n pass for setup, Sources, Drive browser, OAuth JSON import, and post-list sync status strings
- added shared skeleton loading primitives plus first-load skeleton states for Sources, Logs, and the Drive browser
- clarified Logs empty states for no events, source-specific misses, level-specific misses, and possibly unlinked source IDs
- created `plans/20260620-2307-1-0-3-release-hardening/plan.md`

Verification status:

- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- `git diff --check` passes
- workflow YAML parse check passes
- local `1.0.3` release metadata validation passes
- local readme tag/source-instruction validation passes
- PHP and Composer checks are blocked in this shell because `php` and `composer` are unavailable
- release still needs CI PHP checks, official Plugin Check, readme validator, and manual staging Google sync QA before SVN publish

## 2026-06-13 - 1.0.1 Release Metadata Alignment

Status: completed in codebase

Fixed the tagged GitHub release packaging failure for `1.0.1`:

- bumped the plugin header version, `DOCSYNC_WP_VERSION`, `readme.txt` stable tag, and package metadata to `1.0.1`
- added a `1.0.1` WordPress.org readme changelog entry
- kept the release workflow validation unchanged so future tags still fail when tag and plugin metadata drift

Verification status:

- local release metadata validation passes for `RELEASE_TAG_NAME=1.0.1`
- local release metadata validation passes for `RELEASE_TAG_NAME=v1.0.1`
- `php -l brasth-document-sync-for-google-docs.php` passes
- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- `git diff --check` passes

## 2026-06-12 - WordPress.org Pre-Review Rename And ZIP Scope

Status: completed in codebase

Resolved WordPress.org pre-review naming and packaging issues:

- renamed the installable plugin entry file to `brasth-document-sync-for-google-docs.php`
- set the display name to `Brasth Document Sync for Google Docs`
- changed the public slug, text domain, admin slugs, and REST namespace to `brasth-document-sync-for-google-docs`
- reviewer response slug: `brasth-document-sync-for-google-docs`
- excluded WordPress.org listing assets under `assets/` from installable ZIPs
- added ZIP/package validation so release artifacts fail if `assets/` is present
- scoped missing Composer autoload and missing build notices to plugin-relevant admin screens

Verification status:

- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- stale public slug/name searches pass for runtime, docs, workflows, package metadata, PHPCS config, license, and listing SVGs
- package dry run confirms one top-level `brasth-document-sync-for-google-docs/` folder, required main/build/readme/resources files, no listing assets, no local agent/tool directories, no excluded development paths, and no nested ZIP files
- PHP and Composer checks are blocked in this shell because `php`, `composer`, and `vendor/` are unavailable

## 2026-06-06 - GitHub Release ZIP Asset Packaging

Status: completed in codebase

Changed GitHub Release packaging so published releases and manual backfills produce a WordPress-installable ZIP asset:

- changed the tagged release workflow to run on `release.published` and manual `workflow_dispatch` with a required tag input
- made the workflow check out the target release tag before building package contents
- validated the release tag, plugin header version, `DOCSYNC_WP_VERSION`, and `readme.txt` stable tag before packaging
- created `brasth-document-sync-for-google-docs-v<version>.zip` with a single top-level `brasth-document-sync-for-google-docs/` folder
- uploaded the ZIP both as a GitHub Actions artifact and as a GitHub Release asset through `gh release upload --clobber`
- added ZIP content checks for required plugin files, required `build/` and `resources/` directories, forbidden development paths, and nested ZIP files
- added `*.zip` to `.distignore`
- updated README release instructions with the `1.0.0` manual backfill workflow

Verification status:

- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- package dry run confirms `brasth-document-sync-for-google-docs-v1.0.0.zip` has one top-level `brasth-document-sync-for-google-docs/` folder, required runtime/build/source files, no excluded development paths, and no nested ZIP files
- `git diff --check` passes

## 2026-05-31 - WordPress.org Readiness First Pass

Status: completed in codebase

Prepared the first public release surface for WordPress.org review:

- added WordPress.org `readme.txt` with stable tag, install steps, FAQ, changelog, privacy notes, external-service disclosure, and source/build instructions
- added root `LICENSE` and WordPress.org listing assets under `assets/`
- set the public plugin author to Brasth and refreshed the listing logo/banner artwork with a centered document mark
- bumped plugin and package metadata to `1.0.0`
- updated `.distignore` so release packages include human-readable frontend source, package metadata, lockfile, Vite config, and built assets while excluding local/development artifacts
- added suggested privacy policy content for Google OAuth, Drive API, Docs API, stored credentials/tokens, linked source metadata, imported media, retention, and uninstall behavior
- removed legacy Google Picker settings from PHP settings, REST responses, localized admin config, and TypeScript API types
- removed unused Markdown/CommonMark conversion code and dependency
- centralized common REST login/nonce/settings permission checks
- added Google account disconnect confirmation and pre-connect disclosure in the setup UI

Verification status:

- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- targeted PHP syntax checks pass for modified bootstrap and REST permission files
- `git diff --check` passes
- package dry run confirms required release files are included and local docs/plans/node modules are excluded

## 2026-05-30 - Sync Diagnostic Logs

Status: completed in codebase

Added bounded diagnostic event logging for source syncs:

- stored the latest 50 diagnostic-safe sync events per linked source in `_docsync_wp_sync_events`
- appended events from queued syncs, progress milestones, large-doc fallback switches, partial fallback imports, terminal states, and errors
- included stale recovery context with last heartbeat, last step, lock state, and cron-event state
- changed `GET /sync-log` to return real events with `post_id`, `level`, `page`, and `per_page` filters
- added `Brasth Document Sync > Logs` with level/source filters, pagination, and a `View logs` action from Sources

Verification status:

- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- PHP syntax checks pass for modified PHP files

## 2026-05-30 - Large Doc Sync Stuck Recovery

Status: completed in codebase

Improved long-running Google Doc sync recovery and modal sizing:

- added sync start/update heartbeat metadata and error codes to source state
- converted abandoned `syncing` states into a clear retryable error when no lock or cron event remains
- kept background sync polling alive after long-running thresholds with slower polling
- retried transient source-status polling failures instead of stopping after one failed request
- displayed the latest sync heartbeat in post editor progress details
- removed the admin-bar offset from the post type source modal so it fills the viewport from the top

Verification status:

- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- PHP syntax checks pass for modified PHP files
- `git diff --check` passes

## 2026-05-30 - Dense Doc Picker and Inline Editor Apply

Status: completed in codebase

Improved the Google Doc linking modal and post editor sync completion flow:

- moved source mode tabs into the modal header to reclaim vertical space
- replaced the separate current-folder background row with a compact breadcrumb beside the Drive browser title
- tightened Drive browser panel, table header, row, and load sentinel spacing so more items fit onscreen
- added a permission-checked source content REST response for editor screens
- changed post editor sync completion to apply synced content through WordPress editor APIs instead of reloading clean editors
- changed dirty-editor completion to offer `Apply synced content` instead of `Reload editor`, preserving explicit overwrite control

Verification status:

- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- PHP syntax check passes for `src/Rest/SourceController.php`
- `git diff --check` passes

## 2026-05-30 - Sync Completion UX and Progressive Large-Doc Drafts

Status: completed in codebase

Fixed stale sync feedback and empty long-running draft imports:

- hid sync progress bars for terminal `synced`, `skipped`, and `error` states while keeping status labels, last sync time, and errors visible
- added post editor sync-completion handling that reloads automatically when Gutenberg reports no unsaved edits
- added a `Reload editor` notice action and dirty-editor warning when auto-reload would risk losing local edits
- added `wp-data` only to the post-sync bundle so the editor dirty-state selector is available
- added progressive Docs API fallback writes for empty drafts, saving sanitized block content during large-doc import
- kept non-empty existing posts atomic until full import and final block conversion succeed
- left partial draft content in place if a progressive fallback fails, then stores the normal `error` source state

Verification status:

- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- PHP syntax checks pass for modified sync files
- `git diff --check` passes

## 2026-05-30 - Live Sync Progress Percent

Status: completed in codebase

Added truthful milestone progress for Google Doc syncs:

- stored `syncProgress`, `syncStep`, and `syncMessage` with existing source post meta
- exposed progress fields on existing source REST responses without changing route shapes or persisted status names
- moved admin manual syncs and sync-all to background queueing so screens can poll progress
- showed progress percent and messages in the post edit meta box, list-table status column, post-list toasts, and Sources table
- preserved last reached progress on errors instead of marking failed syncs complete
- prevented stale background sync work from overwriting a source after the linked Doc changes or detaches
- batched sync-all queueing and spawns WP-Cron once per batch
- allowed repeated manual sync to recover a stale `syncing` source when no matching cron event or lock remains
- refreshed active sync locks on milestone saves and constrained sync-all scanning by user edit capabilities where possible

Verification status:

- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- PHP syntax checks pass for modified PHP files
- `git diff --check` passes

## 2026-05-30 - Oversized Google Doc Export Fallback

Status: completed in codebase

Prevented large Google Docs from dead-ending when Drive HTML ZIP export exceeds Google's Workspace export limit:

- added Drive metadata compatibility data for `canDownload`, `size`, `quotaBytesUsed`, and large-doc warnings
- blocked link/sync when Drive reports the connected account cannot download the Doc
- kept HTML ZIP as the primary sync path and only retries through Docs API after `docsync_wp_export_too_large`
- added Docs API fallback conversion for paragraphs, headings, links, text styling, lists, tables, horizontal rules, and inline images
- preserved post content until either primary import or fallback import fully succeeds
- stored `lastSyncMethod` diagnostics on successful sync
- updated setup copy and docs to require both Drive API and Docs API in the same Google Cloud project

Verification status:

- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes
- PHP syntax checks pass for all `src/**/*.php`
- `git diff --check` passes

## 2026-05-30 - GitHub Actions Node 24 Compatibility

Status: completed in codebase

Updated CI workflows before GitHub's Node.js 20 action runtime removal:

- upgraded checkout, setup-node, pnpm setup, and artifact upload actions to Node 24-compatible major versions
- changed frontend CI jobs to run with Node.js 24 while keeping the package `>=20` runtime floor unchanged
- kept existing PHP setup, package staging, and installer-ready artifact layout behavior unchanged

Verification status:

- GitHub workflow YAML parses successfully
- `pnpm lint` passes
- `pnpm typecheck` passes
- `pnpm build` passes

## 2026-05-30 - Admin UI Stability and Modal Polish

Status: completed in codebase

Fixed the WordPress admin list layout regression and refined the Google Doc linking modal:

- removed global Tailwind imports from the admin CSS entry bundles so Tailwind preflight no longer leaks into WordPress admin list screens
- kept Brasth Document Sync styles scoped through the existing admin, modal, Drive browser, and shared CSS partials
- replaced the full-width advanced linking toggle with a compact three-mode source switch for browsing, pasted Doc URLs, and raw file IDs
- enlarged the modal close control to a 44px hit target with stronger hover and keyboard focus states
- increased the Drive browser table region to a responsive internal scroll area with sticky headers
- aligned Drive browser toolbar controls to a shared 40px height while keeping WordPress `SelectControl`

Verification status:

- `pnpm typecheck` passes
- `pnpm build` passes

## 2026-05-30 - Non-Blocking Picker and Background Sync

Status: completed in codebase

Changed the post-list Google Doc creation flow so the picker no longer waits for the full import:

- made the Google Doc source modal fullscreen below the WordPress admin bar
- changed the Drive browser to 50-result pages with infinite loading
- added optional `syncMode` support to source create and sync REST requests while keeping inline mode as the default
- added `GET /sources/{postId}` for status polling
- added a single-source WP-Cron hook for background sync that still calls `SyncService::syncPost()`
- queued new synced drafts from the post list, persisted source state as `syncing`, and returned API status `queued`
- added fixed admin toast feedback with indeterminate progress and a11y announcements
- polls source status until `synced`, `skipped`, `error`, or timeout
- refreshes visible list-table content after a newly created draft finishes syncing, with a reload action fallback
- unschedules both recurring and single-source cron hooks on deactivation/uninstall

Verification status:

- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- `git diff --check` passes

## 2026-05-30 - WPCS Setup Validation

Status: completed in codebase

Validated and tightened the WordPress coding standards setup:

- confirmed Composer installs project-local PHPCS, WPCS, and PHPCompatibilityWP dependencies
- confirmed `vendor/bin/phpcs -i` discovers WordPress and PHPCompatibilityWP standards
- moved fixed PHP DOM extension mixed-case property names into a ruleset-level allow-list
- applied safe PHPCBF formatting fixes for array layout and assignment alignment
- added GitHub runner checks for Composer validation and required PHPCS standards discovery
- routed branch pushes to lint checks and kept snapshot packaging on `main`
- documented the reproducible WPCS workflow in README and code standards docs

Verification status:

- `vendor/bin/phpcs -i` passes and lists required standards
- `composer validate --no-check-publish` passes
- `composer lint` passes
- `pnpm lint` passes

## 2026-05-21 - Admin UI Fixes and Gutenberg Sync Content

Status: completed in codebase

Fixed reported admin UI regressions and changed synced content to block markup:

- changed Drive breadcrumbs from boxed WordPress buttons to semantic breadcrumb controls
- simplified OAuth JSON import into one compact picker with filename/status feedback
- stacked post edit metabox actions so `Change Doc`, `Sync now`, and `Detach` fit narrow sidebars
- added server-side HTML-to-Gutenberg conversion for synced Google Docs content
- maps common HTML export nodes to core paragraph, heading, list, image, table, quote, preformatted, and separator blocks
- keeps unsupported HTML in `core/html` fallback blocks instead of dropping content
- hashes and writes the final block content so skip logic matches the saved post body

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- `git diff --check` passes
- local PHP verification is blocked because `php` and `composer` are unavailable

## 2026-05-21 - Drive Modal Polish and OAuth JSON Import

Status: completed in codebase

Refined the Google Doc linking and setup UX:

- widened the source modal and changed the header close control to a compact accessible icon button
- adjusted the Drive browser table so desktop rows fit the modal without awkward horizontal scrolling
- kept small-screen table overflow available for narrow viewports
- added a local browser-only OAuth client JSON import control in the setup wizard
- parses Google Web application OAuth JSON into client ID and client secret fields
- warns when the imported OAuth client JSON does not contain the plugin redirect URI
- split Drive browser table CSS into a dedicated component partial for easier maintenance
- fixed Drive client alignment warnings reported by PHPCS

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- `git diff --check` passes
- local PHP verification is blocked because `php` and `composer` are unavailable

## 2026-05-21 - Admin Frontend Structure Refactor

Status: completed in codebase

Restructured the admin frontend for maintenance without changing sync behavior:

- kept Radix Dialog/Tabs for the Google Doc source modal
- added WordPress package integration for REST fetch, i18n, URL helpers, a11y announcements, and simple UI components
- moved Vite entrypoints, REST API modules, feature components, hooks, and shared UI atoms into focused folders
- kept old component import paths as thin re-exports while new code lives under `features/`, `app/`, `api/`, `entries/`, and `shared/ui/`
- split large admin, Drive browser, source modal, and post-sync files so each TypeScript/TSX file stays under 200 lines
- updated enqueue dependencies and Vite externals for the WordPress globals used by the admin bundles

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- local PHP verification is blocked because `php` and `composer` are unavailable

## 2026-05-21 - Drive-Like Document Browser

Status: completed in codebase

Improved the source selection browser into a My Drive file-manager view:

- added `GET /brasth-document-sync-for-google-docs/v1/drive/items` for folder-scoped Drive browsing
- added `GET /brasth-document-sync-for-google-docs/v1/drive/shared-drives` and a Drive location selector for shared drives
- listed Google Drive folders and Google Docs only; unsupported file types remain hidden
- kept `GET /documents` and `POST /documents/inspect` behavior unchanged for compatibility and advanced linking
- added folder navigation, breadcrumb backtracking, current-folder search, refresh, table rows, explicit row actions, empty/loading/error states, and pagination
- kept folders as `Open` actions and Google Docs as explicit `Select` actions for `Link source` / `Create synced draft`
- fixed sticky table headers so row content no longer bleeds under the header while scrolling
- updated post list-table row actions/status cells after link/sync and auto-refreshes after creating a new synced draft
- split the browser table and helpers into focused TypeScript modules to keep the main panel small
- split admin source CSS into entry-specific files and shared/component partials for easier maintenance

Verification status:

- `pnpm typecheck` passes
- `pnpm lint` passes
- `pnpm build` passes
- local PHP verification is blocked because `php` and `composer` are unavailable

## 2026-05-20 - Custom Drive Document Browser

Status: completed in codebase

Replaced Google Picker with a Brasth Document Sync Drive browser:

- added `GET /brasth-document-sync-for-google-docs/v1/documents` for server-side Google Docs listing
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

- moved linked sources to `Brasth Document Sync > Sources`
- kept `Brasth Document Sync > Setup` focused on Google account and settings
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

Fixed GitHub packaging artifacts so downloaded workflow artifacts are directly installable by WordPress instead of containing a nested plugin ZIP. Added a workflow validation gate for `brasth-document-sync-for-google-docs/brasth-document-sync-for-google-docs.php`, `vendor/autoload.php`, and the built Vite manifests.

## 2026-05-18 - PHPCS CI Fixes

Status: completed in codebase

Patched PHPCS findings from CI: missing REST request docblock, docblock spacing, assignment-in-condition warnings, assignment alignment warnings, and `count()` inside a loop condition.

## 2026-05-18 - Google Docs Sync Admin Actions

Status: completed in codebase

Implemented the admin actions slice for Google Docs sync:

- central sync admin dashboard
- Google OAuth connect/disconnect flow
- document inspection by Picker, URL, or file ID
- post edit meta box with link, change, sync now, and detach actions
- post list-table top action and inline row actions
- source status column in list tables
- `sync-all` support for changed sources
- WP-Cron registration and batch sync execution
- sync state and error persistence in post meta

Architecture notes:

- REST namespace is `brasth-document-sync-for-google-docs/v1`
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
