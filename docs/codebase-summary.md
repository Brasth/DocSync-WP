# Brasth Document Sync Codebase Summary

Last updated: 2026-07-12

## Snapshot

Brasth Document Sync for Google Docs is a WordPress plugin for one-way Google Docs -> WordPress sync. This checkout includes Google OAuth, document inspection, post/page linking and sync, list-table actions, role-aware Setup/Sources/Logs admin pages, a shared authenticated feedback form for public GitHub issues, a least-privilege workspace bootstrap route, first-source activation, health-first source operations, HTML ZIP media import, Gutenberg block conversion, diagnostic sync events, WP-Cron scheduling, optional anonymous active-install telemetry, and first-pass WordPress.org release packaging.

Summary reflects the current source tree after the Radix plus WordPress-native admin frontend refactor, Drive modal polish, OAuth JSON import, admin UI fixes, Gutenberg sync conversion, layout preset foundation, Elementor preset release, 1.1.3 Elementor usability polish, standalone image block fixes, layout reliability fixture coverage, background sync progress, large-doc fallback, stale sync recovery, bounded sync logging, privacy disclosure, optional telemetry, screen-specific admin asset split, and local WordPress devcontainer setup.

- Total files tracked by `rg --files`: 268
- Main languages: PHP, TypeScript, CSS

## Top-Level Structure

- `src/` - PHP plugin runtime
- `resources/js/admin/` - React admin app and post-level controls
- `resources/css/setup-entry.css`, `sources-entry.css`, and `logs-entry.css` - screen-specific admin styles
- `resources/css/post-sync-entry.css` - post edit/list-table initial styles
- `resources/css/doc-source-modal-entry.css` and `drive-browser-entry.css` - lazy source modal and Drive browser styles
- `resources/css/shared/` and `resources/css/components/` - reusable CSS partials
- `docs/` - project documentation and research
- `plans/` - implementation plans and phase notes
- `tests/fixtures/` - golden fixtures for Gutenberg layout presets, Elementor presets, and large-doc fallback behavior
- `.devcontainer/` - Docker Compose WordPress/MySQL development runtime with WP-CLI verification scripts
- `build/` - Vite output used by WordPress admin screens
- `assets/` - WordPress.org listing banner and icon assets
- `cloudflare/telemetry-worker/` - isolated Cloudflare Worker package for optional anonymous active-install telemetry; excluded from installable WordPress ZIPs
- `cloudflare/feedback-worker/` - isolated Cloudflare Worker package for GitHub issue relay; GitHub token stays in Wrangler secrets and the package is excluded from installable WordPress ZIPs

## Primary Runtime Flow

1. WordPress loads `brasth-document-sync-for-google-docs.php`.
2. `DocSyncWP\Plugin::boot()` wires settings, OAuth, Drive client, source repository, sync service, cron, telemetry, REST, and admin UI.
3. Setup, Sources, and Logs mount through separate React entries under `resources/js/admin/entries/`; Setup stays administrator-only while capability-qualified operators use Sources and Logs.
4. Post/page edit and list-table screens mount through `resources/js/admin/entries/post-sync-entry.tsx`, with source modal and Drive browser assets loaded lazily.
5. REST controllers handle settings, the safe `/workspace` readiness/health response, OAuth, My Drive/shared drive folder browsing, document inspection, source management, sync logs, and sync triggers.
6. `SyncService` reads Google metadata, exports an HTML ZIP package or Docs API fallback HTML, imports local images into Media Library, converts sanitized HTML through the effective Gutenberg or Elementor preset path, updates the target post, and persists sync state plus diagnostic events.

## Key Backend Modules

- `src/Settings/SettingsRepository.php` - site settings, enabled post types, encrypted client secret storage.
- `src/Auth/GoogleOAuthService.php` - OAuth URL generation, callback handling, token refresh.
- `src/Auth/TokenStore.php` - per-user encrypted Google token storage.
- `src/Google/DriveClient.php` - My Drive/shared drive folder and Doc listing, Docs metadata, and export requests.
- `src/Sync/SourceRepository.php` - post meta source records, capability checks, and bounded diagnostic sync events.
- `src/Rest/WorkspaceController.php` - allowlisted operational readiness, safe publishing defaults, and permission-filtered source health/activation summary.
- `src/Sync/SyncService.php` - attach, create draft, sync, large-doc fallback writes, skip-on-unchanged, output-path diagnostics, error handling.
- `src/Sync/HtmlZipImporter.php` - coordinates HTML ZIP import and sanitization.
- `src/Sync/HtmlZipPackageExtractor.php` - extracts Google Docs ZIP exports and locates HTML.
- `src/Sync/HtmlDocumentImageRewriter.php` - rewrites local image refs to attachment URLs.
- `src/Sync/HtmlToBlockContentConverter.php` - legacy plain-block converter for current output compatibility.
- `src/Sync/Layout/LayoutConversionService.php` - resolves the effective layout preset, fingerprints output policy, and converts Gutenberg sync content.
- `src/Sync/Layout/LayoutPresetRegistry.php` - built-in presets: Clean Article, Documentation, and Plain Blocks.
- `src/Sync/Layout/LayoutBlueprint.php` - immutable preset metadata and behavior switches.
- `src/Sync/Elementor/Preset/` - Elementor Hero Page and Elementor Feature Block registry, fingerprinting, and conversion services.
- `src/Sync/Layout/ContentRoleClassifier.php` - detects headings, images, lists, tables, code, callouts, and containers.
- `src/Sync/HtmlBlockFactory.php` - creates common core block arrays from DOM elements, including native `core/image` blocks for standalone images.
- `src/Sync/HtmlStandaloneImageDetector.php` and `src/Sync/HtmlStandaloneImage.php` - detect image-only wrappers, links, and captions before block serialization.
- `src/Sync/HtmlBlockMarkupSanitizer.php` - strips export-specific markup from block inner HTML.
- `src/Sync/MediaAssetImporter.php` - uploads and dedupes Media Library images.
- `src/Cron/SyncCron.php` - scheduled sync registration and batch execution.
- `src/Telemetry/TelemetryService.php` - optional anonymous weekly check-in payload and endpoint filter.
- `src/Telemetry/TelemetryCron.php` - opt-in weekly telemetry schedule and cleanup hook.
- `src/Feedback/FeedbackService.php` - validated Worker relay that never calls GitHub directly.
- `src/Rest/FeedbackController.php` - authenticated feedback payload validation and route handler.
- `src/Rest/*Controller.php` - admin REST surface.
- `src/Rest/RestPermissions.php` - shared REST login, nonce, and settings permission checks.

## Key Frontend Modules

- `resources/js/admin/entries/` - Vite entrypoints for Setup, Sources, Logs, post-sync, source modal styles, and Drive browser.
- `resources/js/admin/app/` - screen-specific Setup and Sources shells plus app state hooks.
- `resources/js/admin/api/` - REST client, typed API modules, and shared wire types.
- `resources/js/admin/features/drive-browser/` - Google Drive browser hook, toolbar, breadcrumb, table, and panel.
- `resources/js/admin/features/doc-source-modal/` - Radix-backed source modal, mode tabs, advanced input, and modal hook.
- `resources/js/admin/features/google-setup/` - setup wizard, OAuth JSON import, account panel, target settings, and setup checks.
- `resources/js/admin/features/post-sync/` - post edit meta box, list-table action mount, row DOM helpers, and sync action hook.
- `resources/js/admin/features/sources/` - filterable linked source table and bulk sync action.
- `resources/js/admin/features/activation/` - pure capability-aware advisor plus first-source progress, success, and recovery presentation shared by Setup and Sources.
- `resources/js/admin/features/sync-logs/` - diagnostic event table, filters, and pagination.
- `resources/js/admin/features/feedback/` - public-issue disclosure, feedback dialog, validation, and submission state.
- `resources/js/admin/shared/ui/` - small WordPress-backed sync UI atoms such as buttons, notices, skeletons, loading states, empty states, and status pills.
- `resources/js/admin/components/` - thin compatibility re-exports for older local imports.

## Frontend Style Modules

- `resources/css/admin.css` remains a compatibility wrapper that imports the legacy `admin-entry.css`.
- Screen entries import only their needed shared primitives and component partials.
- `resources/css/post-sync-entry.css` imports the initial post sync box styles; source modal and Drive browser CSS load only when needed.
- Component-level CSS lives under `resources/css/components/`; cross-entry primitives and responsive rules live under `resources/css/shared/`.

## Local Toolchain Status

- `pnpm` and installed Node dependencies are available in this checkout environment.
- Composer, PHPCS, PHP syntax linting, pnpm, and Node dependencies are available in this checkout environment.
- The devcontainer provides WordPress, MySQL, Composer, WP-CLI, Node 24, pnpm 9.15.0, plugin activation, and runtime route verification at `http://localhost:8890`.
- Runtime route verification includes `GET /workspace` registration.
- Current local validation uses `composer validate --no-check-publish`, `composer lint`, `composer test:layout-fixtures`, `composer test:elementor-fixtures`, `composer test:large-doc-fallback-fixtures`, `composer test:telemetry-settings`, `vendor/bin/phpcs -i`, `pnpm lint`, `pnpm typecheck`, `pnpm build`, and the telemetry and feedback Worker package tests.

## Upcoming Work

The role-aware activation workspace now derives completion from an accessible `synced`/`skipped` source with a successful timestamp, opens the shared Doc modal directly, retains created drafts on queue failure, requires explicit scheduled-sync owner transfer, and orders accessible Sources by attention, syncing, then healthy state. No onboarding table or runtime dependency was added.

The 1.1.x line introduces layout presets to make synced Google Docs publishable without developer layout edits. Shipped additions include:

- `src/Sync/Layout/` — preset registry, blueprint, conversion service, and content-role classifier.
- `src/Sync/Elementor/Preset/` — separate Elementor preset registry and conversion service.
- Setup sync defaults dropdown backed by `GET/POST /settings`.
- Site-level `default_layout_preset`, per-source `Layout preset` selectors backed by optional `_docsync_wp_layout_preset`, and `_docsync_wp_last_layout_fingerprint`.
- Built-in Gutenberg presets covering Clean Article, Documentation, and legacy Plain Blocks.
- Built-in Elementor presets covering Elementor Hero Page and Elementor Feature Block.
- Explicit output type choice in the linking modal when Elementor is available.
- Legacy Elementor upgrade actions in post-sync surfaces.
- Tracked Gutenberg, Elementor, image-block, and large-doc fallback fixtures, wired into Composer and PR CI.

Preview/gallery UI remains future work.

Later releases add bulk Drive folder import, a Pro tier with a custom preset builder, a Google Docs Workspace Add-on, and optional managed OAuth. See `docs/project-roadmap.md` for the full phased plan and success metrics.

## Notes

- The repo uses `brasth-document-sync-for-google-docs` as the plugin slug and text domain.
- REST namespace: `brasth-document-sync-for-google-docs/v1`.
- Google tokens and the OAuth client secret are encrypted with WordPress salts.
- WordPress privacy policy suggested text discloses Google OAuth, Drive API, Docs API, stored credentials/tokens, linked metadata, imported media, optional Brasth telemetry, and uninstall retention.
- Release packaging includes `resources/`, `package.json`, `pnpm-lock.yaml`, `vite.config.ts`, `composer.json`, `build/`, and WordPress.org `readme.txt` so reviewers can inspect human-readable source for built assets. Installable ZIP files exclude `assets/`, `.devcontainer/`, and `cloudflare/`; listing assets stay in the repository for WordPress.org SVN root upload. The release metadata currently targets `1.1.5`, including Drive folder automation, multi-select draft creation, and the admin feedback workflow.
- The admin app depends on WordPress packages for REST, i18n, a11y, URL helpers, components, and element runtime; Radix Dialog/Tabs remain the complex interaction primitives.
- Frontend lint blocks inline PHPCS suppression comments in plugin source.
