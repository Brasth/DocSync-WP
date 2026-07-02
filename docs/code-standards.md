# Code Standards

Last updated: 2026-06-30

## Purpose

Standards here match the current Brasth Document Sync implementation. Keep new work aligned with the code already shipped.

## PHP Standards

- Use the `DocSyncWP\` namespace.
- Keep services focused and constructor-injected.
- Prefer small service classes over large controllers.
- Guard every REST and admin action with login and capability checks.
- Sanitize on input, escape on output, and validate external IDs before use.
- Store sensitive values only through the encryption service.
- Keep meta keys and option keys prefixed with `docsync_wp_` or `_docsync_wp_`.
- Avoid deleting user content or synced posts on uninstall.
- Do not add inline PHPCS suppression comments. Prefer code changes; use narrow `phpcs.xml.dist` exceptions only when WordPress APIs cannot express the required behavior safely.

## WordPress Integration Standards

- Register plugin hooks from `src/Plugin.php`.
- Use `brasth-document-sync-for-google-docs` as slug and text domain.
- Use `brasth-document-sync-for-google-docs/v1` for REST endpoints.
- Scope admin notices to plugin screens or screens where plugin UI is loaded, except critical activation/runtime failures.
- Use WordPress HTTP APIs for Google requests.
- Use WordPress packages for admin JS platform APIs: `wp-api-fetch`, `wp-element`, `wp-i18n`, `wp-url`, `wp-a11y`, `wp-components`, and `wp-data` where editor state is required.
- Keep admin UI inside WordPress admin conventions.

## Storage Standards

- Site settings: `docsync_wp_settings`
- User tokens: `_docsync_wp_google_token`
- Post source state: `_docsync_wp_*` source meta keys
- Imported image dedupe: `_docsync_wp_google_asset_*` attachment meta keys

Rules:

- store only the minimum required sync state
- encrypt Google credentials and tokens
- keep post meta as the source record for sync state
- store source progress as milestone state in post meta, not as byte-level transfer telemetry
- keep optional Brasth telemetry limited to anonymous install-level metadata; do not include Google data, site URLs, user data, post data, document IDs, or content
- avoid adding custom tables unless audit history becomes necessary

## Frontend Standards

- Admin UI is React mounted from Vite entry points.
- Follow `docs/design-guidelines.md` for admin visual direction, tokens, density, motion, and accessibility states.
- Keep the central dashboard and post-level controls as separate bundles.
- Use WordPress element imports, not direct React runtime imports.
- Use Radix UI primitives for complex interaction behavior such as Dialog and Tabs. Do not replace those with WordPress Modal/TabPanel unless a future design decision changes this.
- Use WordPress components for simple admin controls and feedback where they fit: buttons, search controls, select controls, text controls, notices, spinners, and tooltips.
- Keep React and React DOM as build-time peers for Radix only; Vite must externalize runtime React imports and WordPress package imports to WordPress globals.
- Alias Radix automatic JSX runtime imports to the local WordPress JSX runtime shim so bundled primitives do not expect a separate React runtime.
- Organize admin frontend code by feature first, with shared UI atoms under `resources/js/admin/shared/ui/`.
- Keep old `resources/js/admin/components/*` paths as thin compatibility exports only when useful during refactors.
- Prefer feature hooks for stateful workflows; use `wp-data` only for WordPress editor state or state shared across independent mounts.
- Prefer explicit user-facing errors over hidden failures.
- Keep modal and row-action behavior accessible and keyboard-safe.

## Sync Behavior Standards

- Google Docs is source of truth.
- Current export format is `html_zip`; legacy `markdown` metadata should normalize on source save.
- Google Docs API fallback is automatic only after Drive returns the `docsync_wp_export_too_large` export-size error.
- Do not sync if metadata and content hash show no change.
- Use a per-post lock during sync.
- Record `linked`, `syncing`, `synced`, `skipped`, or `error`.
- Record `syncProgress`, `syncStep`, and `syncMessage` for live admin polling. Progress must be clamped to `0-100`, milestone-based, and honest about external Google API limits.
- Show progress UI only while a source is actively `syncing`; terminal states should use status labels, last sync time, and error messages.
- Record the last successful sync method for diagnostics.
- Keep non-empty existing posts atomic until final conversion succeeds. Empty drafts may receive progressive partial writes during Docs API fallback, and failed progressive imports may keep the partial draft content while marking the source `error`.
- Manual admin syncs should use background mode and poll source state; backend inline sync remains only for REST compatibility.
- Manual background sync depends on WP-Cron; admin docs must mention server cron for low-traffic or `DISABLE_WP_CRON` sites.
- Repeated manual sync should not reset active progress, should refresh locks during milestone saves, and should be able to requeue a stale `syncing` source when no matching cron event or lock remains.
- Do not allow source attach/detach operations to overwrite or remove a source while that source is actively syncing.
- Preserve user capability checks even when a source already exists.
- Upload exported local images through WordPress Media Library APIs and reuse matching attachments.

## Security Standards

- Require OAuth state verification.
- Keep OAuth client secret server-side only.
- Encrypt access and refresh tokens with WordPress salts.
- Do not expose token values to the admin app.
- Treat exported Google content as untrusted until sanitized.
- Do not assume Drive browser selections, pasted URLs, or pasted file IDs are safe without server-side access checks.

## Verification Standards

Use the project-local Composer and pnpm toolchains:

- `composer install`
- `vendor/bin/phpcs -i`
- `composer validate --no-check-publish`
- `composer lint`
- `composer test:layout-fixtures`
- `composer test:elementor-fixtures`
- `pnpm install --frozen-lockfile`
- `pnpm lint`
- `pnpm typecheck`
- `pnpm build`

Run `composer lint:fix` only for safe automatic PHPCS fixes. Confirm `vendor/bin/phpcs -i` includes `WordPress`, `WordPress-Core`, `WordPress-Docs`, `WordPress-Extra`, and `PHPCompatibilityWP` after dependency installation.

## File Organization

- Keep file names descriptive and kebab-case when new files are created.
- Keep individual code files small where possible.
- Prefer `src/<Domain>/` for backend code and `resources/js/admin/` for admin UI code.
- Keep docs concise and split by topic when a file starts to drift into a second subject.
