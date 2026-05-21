# DocSync WP Codebase Summary

Last updated: 2026-05-21

## Snapshot

DocSync WP is a WordPress plugin for one-way Google Docs -> WordPress sync. This checkout includes Google OAuth, document inspection, post/page linking and sync, list-table actions, a setup admin page, a dedicated Sources submenu, HTML ZIP media import, and WP-Cron scheduling.

Summary reflects the current source tree after the Radix plus WordPress-native admin frontend refactor, Drive modal polish, and OAuth JSON import.

- Total files tracked by `rg --files`: 128
- Main languages: PHP, TypeScript, CSS

## Top-Level Structure

- `src/` - PHP plugin runtime
- `resources/js/admin/` - React admin app and post-level controls
- `resources/css/admin-entry.css` - setup and Sources admin styles
- `resources/css/post-sync-entry.css` - post edit/list-table source modal styles
- `resources/css/shared/` and `resources/css/components/` - reusable CSS partials
- `docs/` - project documentation and research
- `plans/` - implementation plans and phase notes
- `build/` - Vite output used by WordPress admin screens

## Primary Runtime Flow

1. WordPress loads `docsync-wp.php`.
2. `DocSyncWP\Plugin::boot()` wires settings, OAuth, Drive client, source repository, sync service, cron, REST, and admin UI.
3. The central admin screen mounts the React app through `resources/js/admin/entries/admin-entry.tsx`.
4. Post/page edit and list-table screens mount through `resources/js/admin/entries/post-sync-entry.tsx`.
5. REST controllers handle settings, OAuth, My Drive/shared drive folder browsing, document inspection, source management, sync logs, and sync triggers.
6. `SyncService` reads Google metadata, exports an HTML ZIP package, imports local images into Media Library, updates the target post, and persists sync state.

## Key Backend Modules

- `src/Settings/SettingsRepository.php` - site settings, enabled post types, encrypted client secret storage.
- `src/Auth/GoogleOAuthService.php` - OAuth URL generation, callback handling, token refresh.
- `src/Auth/TokenStore.php` - per-user encrypted Google token storage.
- `src/Google/DriveClient.php` - My Drive/shared drive folder and Doc listing, Docs metadata, and export requests.
- `src/Sync/SourceRepository.php` - post meta source records and capability checks.
- `src/Sync/SyncService.php` - attach, create draft, sync, skip-on-unchanged, error handling.
- `src/Sync/HtmlZipImporter.php` - coordinates HTML ZIP import and sanitization.
- `src/Sync/HtmlZipPackageExtractor.php` - extracts Google Docs ZIP exports and locates HTML.
- `src/Sync/HtmlDocumentImageRewriter.php` - rewrites local image refs to attachment URLs.
- `src/Sync/MediaAssetImporter.php` - uploads and dedupes Media Library images.
- `src/Cron/SyncCron.php` - scheduled sync registration and batch execution.
- `src/Rest/*Controller.php` - admin REST surface.

## Key Frontend Modules

- `resources/js/admin/entries/` - Vite entrypoints for admin and post-sync bundles.
- `resources/js/admin/app/` - central setup/Sources shell and app state hook.
- `resources/js/admin/api/` - REST client, typed API modules, and shared wire types.
- `resources/js/admin/features/drive-browser/` - Google Drive browser hook, toolbar, breadcrumb, table, and panel.
- `resources/js/admin/features/doc-source-modal/` - Radix-backed source modal, mode tabs, advanced input, and modal hook.
- `resources/js/admin/features/google-setup/` - setup wizard, OAuth JSON import, account panel, target settings, and setup checks.
- `resources/js/admin/features/post-sync/` - post edit meta box, list-table action mount, row DOM helpers, and sync action hook.
- `resources/js/admin/features/sources/` - filterable linked source table and bulk sync action.
- `resources/js/admin/shared/ui/` - small WordPress-backed DocSync UI atoms such as buttons, notices, loading states, and status pills.
- `resources/js/admin/components/` - thin compatibility re-exports for older local imports.

## Frontend Style Modules

- `resources/css/admin.css` remains a compatibility wrapper that imports `admin-entry.css`.
- `resources/css/admin-entry.css` imports shared primitives plus setup, admin shell, and Sources table partials.
- `resources/css/post-sync-entry.css` imports shared primitives plus post sync box, modal, tabs, Drive browser layout/table, and advanced source partials.
- Component-level CSS lives under `resources/css/components/`; cross-entry primitives and responsive rules live under `resources/css/shared/`.

## Local Toolchain Status

- `php` is not installed in this checkout environment.
- `composer` is not installed in this checkout environment.
- `vendor/autoload.php` is absent here until `composer install` is run.

## Notes

- The repo uses `docsync-wp` as the plugin slug and text domain.
- REST namespace: `docsync-wp/v1`.
- Google tokens and the OAuth client secret are encrypted with WordPress salts.
- The admin app depends on WordPress packages for REST, i18n, a11y, URL helpers, components, and element runtime; Radix Dialog/Tabs remain the complex interaction primitives.
- Frontend lint blocks inline PHPCS suppression comments in plugin source.
