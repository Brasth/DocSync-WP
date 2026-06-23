# Brasth Document Sync Codebase Summary

Last updated: 2026-06-23

## Snapshot

Brasth Document Sync for Google Docs is a WordPress plugin for one-way Google Docs -> WordPress sync. This checkout includes Google OAuth, document inspection, post/page linking and sync, list-table actions, setup/Sources/Logs admin pages, HTML ZIP media import, Gutenberg block conversion, diagnostic sync events, WP-Cron scheduling, and first-pass WordPress.org release packaging.

Summary reflects the current source tree after the Radix plus WordPress-native admin frontend refactor, Drive modal polish, OAuth JSON import, admin UI fixes, Gutenberg sync conversion, background sync progress, large-doc fallback, stale sync recovery, bounded sync logging, privacy disclosure, Elementor sync support, and the `1.0.6` admin asset split.

- Total files tracked by `rg --files`: 131
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
- `build/` - Vite output used by WordPress admin screens
- `assets/` - WordPress.org listing banner and icon assets

## Primary Runtime Flow

1. WordPress loads `brasth-document-sync-for-google-docs.php`.
2. `DocSyncWP\Plugin::boot()` wires settings, OAuth, Drive client, source repository, sync service, cron, REST, and admin UI.
3. Setup, Sources, and Logs mount through separate React entries under `resources/js/admin/entries/`.
4. Post/page edit and list-table screens mount through `resources/js/admin/entries/post-sync-entry.tsx`, with source modal and Drive browser assets loaded lazily.
5. REST controllers handle settings, OAuth, My Drive/shared drive folder browsing, document inspection, source management, sync logs, and sync triggers.
6. `SyncService` reads Google metadata, exports an HTML ZIP package, imports local images into Media Library, converts sanitized HTML to block content, updates the target post, and persists sync state plus diagnostic events.

## Key Backend Modules

- `src/Settings/SettingsRepository.php` - site settings, enabled post types, encrypted client secret storage.
- `src/Auth/GoogleOAuthService.php` - OAuth URL generation, callback handling, token refresh.
- `src/Auth/TokenStore.php` - per-user encrypted Google token storage.
- `src/Google/DriveClient.php` - My Drive/shared drive folder and Doc listing, Docs metadata, and export requests.
- `src/Sync/SourceRepository.php` - post meta source records, capability checks, and bounded diagnostic sync events.
- `src/Sync/SyncService.php` - attach, create draft, sync, skip-on-unchanged, error handling.
- `src/Sync/HtmlZipImporter.php` - coordinates HTML ZIP import and sanitization.
- `src/Sync/HtmlZipPackageExtractor.php` - extracts Google Docs ZIP exports and locates HTML.
- `src/Sync/HtmlDocumentImageRewriter.php` - rewrites local image refs to attachment URLs.
- `src/Sync/HtmlToBlockContentConverter.php` - maps sanitized HTML fragments to serialized Gutenberg blocks.
- `src/Sync/HtmlBlockFactory.php` - creates common core block arrays from DOM elements.
- `src/Sync/HtmlBlockMarkupSanitizer.php` - strips export-specific markup from block inner HTML.
- `src/Sync/MediaAssetImporter.php` - uploads and dedupes Media Library images.
- `src/Cron/SyncCron.php` - scheduled sync registration and batch execution.
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
- `resources/js/admin/features/sync-logs/` - diagnostic event table, filters, and pagination.
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
- Current local validation uses `composer validate --no-check-publish`, `composer lint`, `vendor/bin/phpcs -i`, `pnpm lint`, `pnpm typecheck`, and `pnpm build`.

## Upcoming Work

The 1.1.0 release introduces a **Layout Preset** layer to make synced Google Docs publishable without developer layout edits. Planned additions include:

- `src/Sync/Layout/` — preset registry, blueprint interface, and content-role classifier.
- `resources/js/admin/features/layout-preset/` — wizard step and preset gallery.
- New REST routes for listing presets and previewing their output.
- Per-post `_docsync_wp_layout_preset` meta and site-level default setting.
- 4 built-in presets covering Gutenberg and Elementor output.

Later releases add bulk Drive folder import, a Pro tier with a custom preset builder, a Google Docs Workspace Add-on, and optional managed OAuth. See `docs/project-roadmap.md` for the full phased plan and success metrics.

## Notes

- The repo uses `brasth-document-sync-for-google-docs` as the plugin slug and text domain.
- REST namespace: `brasth-document-sync-for-google-docs/v1`.
- Google tokens and the OAuth client secret are encrypted with WordPress salts.
- WordPress privacy policy suggested text discloses Google OAuth, Drive API, Docs API, stored credentials/tokens, linked metadata, imported media, and uninstall retention.
- Release packaging includes `resources/`, `package.json`, `pnpm-lock.yaml`, `vite.config.ts`, `composer.json`, `build/`, and WordPress.org `readme.txt` so reviewers can inspect human-readable source for built assets. Installable ZIP files exclude `assets/`; listing assets stay in the repository for WordPress.org SVN root upload. The release metadata currently targets `1.0.6`.
- The admin app depends on WordPress packages for REST, i18n, a11y, URL helpers, components, and element runtime; Radix Dialog/Tabs remain the complex interaction primitives.
- Frontend lint blocks inline PHPCS suppression comments in plugin source.
