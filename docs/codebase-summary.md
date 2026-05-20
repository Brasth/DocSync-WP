# DocSync WP Codebase Summary

Last updated: 2026-05-20

## Snapshot

DocSync WP is a WordPress plugin for one-way Google Docs -> WordPress sync. This checkout includes Google OAuth, document inspection, post/page linking and sync, list-table actions, a setup admin page, a dedicated Sources submenu, HTML ZIP media import, and WP-Cron scheduling.

Summary reflects the current source tree after the Sources submenu and media sync implementation.

- Total files tracked by `rg --files`: 73
- Main languages: PHP, TypeScript, CSS

## Top-Level Structure

- `src/` - PHP plugin runtime
- `resources/js/admin/` - React admin app and post-level controls
- `resources/css/admin.css` - shared admin styles
- `docs/` - project documentation and research
- `plans/` - implementation plans and phase notes
- `build/` - Vite output used by WordPress admin screens

## Primary Runtime Flow

1. WordPress loads `docsync-wp.php`.
2. `DocSyncWP\Plugin::boot()` wires settings, OAuth, Drive client, source repository, sync service, cron, REST, and admin UI.
3. The central admin screen mounts the React app from `resources/js/admin/main.tsx`.
4. Post/page edit and list-table screens mount `resources/js/admin/post-sync-entry.tsx`.
5. REST controllers handle settings, OAuth, document inspection, source management, sync logs, and sync triggers.
6. `SyncService` reads Google metadata, exports an HTML ZIP package, imports local images into Media Library, updates the target post, and persists sync state.

## Key Backend Modules

- `src/Settings/SettingsRepository.php` - site settings, enabled post types, encrypted client secret storage.
- `src/Auth/GoogleOAuthService.php` - OAuth URL generation, callback handling, token refresh.
- `src/Auth/TokenStore.php` - per-user encrypted Google token storage.
- `src/Google/DriveClient.php` - Google metadata and export requests.
- `src/Sync/SourceRepository.php` - post meta source records and capability checks.
- `src/Sync/SyncService.php` - attach, create draft, sync, skip-on-unchanged, error handling.
- `src/Sync/HtmlZipImporter.php` - coordinates HTML ZIP import and sanitization.
- `src/Sync/HtmlZipPackageExtractor.php` - extracts Google Docs ZIP exports and locates HTML.
- `src/Sync/HtmlDocumentImageRewriter.php` - rewrites local image refs to attachment URLs.
- `src/Sync/MediaAssetImporter.php` - uploads and dedupes Media Library images.
- `src/Cron/SyncCron.php` - scheduled sync registration and batch execution.
- `src/Rest/*Controller.php` - admin REST surface.

## Key Frontend Modules

- `resources/js/admin/App.tsx` - setup page and Sources submenu shell.
- `resources/js/admin/components/SettingsPanel.tsx` - Google setup wizard and settings form.
- `resources/js/admin/components/AccountPanel.tsx` - current user Google account state.
- `resources/js/admin/components/SourcesTable.tsx` - filterable linked source table and bulk sync action.
- `resources/js/admin/components/DocSourceModal.tsx` - Radix-backed Picker-first attach flow with advanced URL and file ID inputs.
- `resources/js/admin/components/DocSourceTabs.tsx` - Radix-backed source mode tabs.
- `resources/js/admin/google-picker.ts` - Google Picker bootstrap.
- `resources/js/admin/post-sync-entry.tsx` - post/page edit meta box and list-table mount logic.

## Local Toolchain Status

- `php` is not installed in this checkout environment.
- `composer` is not installed in this checkout environment.
- `vendor/autoload.php` is absent here until `composer install` is run.

## Notes

- The repo uses `docsync-wp` as the plugin slug and text domain.
- REST namespace: `docsync-wp/v1`.
- Google tokens and the OAuth client secret are encrypted with WordPress salts.
- The admin app depends on `wp-api-fetch` and `wp-element`; Radix React peer and JSX runtime imports are mapped back to WordPress element APIs.
- Frontend lint blocks inline PHPCS suppression comments in plugin source.
