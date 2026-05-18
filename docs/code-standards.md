# Code Standards

Last updated: 2026-05-18

## Purpose

Standards here match the current DocSync WP implementation. Keep new work aligned with the code already shipped.

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
- Use `docsync-wp` as slug and text domain.
- Use `docsync-wp/v1` for REST endpoints.
- Use WordPress HTTP APIs for Google requests.
- Use `wp-api-fetch` and `wp-element` in admin JS.
- Keep admin UI inside WordPress admin conventions.

## Storage Standards

- Site settings: `docsync_wp_settings`
- User tokens: `_docsync_wp_google_token`
- Post source state: `_docsync_wp_*` source meta keys

Rules:

- store only the minimum required sync state
- encrypt Google credentials and tokens
- keep post meta as the source record for sync state
- avoid adding custom tables unless audit history becomes necessary

## Frontend Standards

- Admin UI is React mounted from Vite entry points.
- Keep the central dashboard and post-level controls as separate bundles.
- Use WordPress element imports, not direct React runtime imports.
- Use Radix UI primitives through focused admin components when they improve accessibility. Do not adopt Radix Themes without an explicit design-system decision.
- Keep React and React DOM as build-time peers for Radix only; Vite must externalize runtime React imports to WordPress `wp.element`.
- Alias Radix automatic JSX runtime imports to the local WordPress JSX runtime shim so bundled primitives do not expect a separate React runtime.
- Prefer explicit user-facing errors over hidden failures.
- Keep modal and row-action behavior accessible and keyboard-safe.

## Sync Behavior Standards

- Google Docs is source of truth.
- Do not sync if metadata and content hash show no change.
- Use a per-post lock during sync.
- Record `linked`, `syncing`, `synced`, `skipped`, or `error`.
- Preserve user capability checks even when a source already exists.

## Security Standards

- Require OAuth state verification.
- Keep OAuth client secret server-side only.
- Encrypt access and refresh tokens with WordPress salts.
- Do not expose token values to the admin app.
- Treat exported Google content as untrusted until sanitized.
- Do not assume Picker or pasted file IDs are safe without access checks.

## Verification Standards

Current verification commands listed in the repo README:

- `composer validate`
- `composer dump-autoload -o`
- `vendor/bin/phpcs`
- `pnpm lint`
- `pnpm typecheck`
- `pnpm build`

Local note:

- PHP and Composer are missing in this checkout, so the PHP verification path cannot run here until the toolchain is installed.

## File Organization

- Keep file names descriptive and kebab-case when new files are created.
- Keep individual code files small where possible.
- Prefer `src/<Domain>/` for backend code and `resources/js/admin/` for admin UI code.
- Keep docs concise and split by topic when a file starts to drift into a second subject.
