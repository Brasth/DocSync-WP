# Brasth Document Sync for Google Docs

Brasth Document Sync for Google Docs is a WordPress plugin for syncing Google Docs into WordPress posts, pages, and enabled public custom post types. It uses self-managed Google OAuth, a server-side Drive browser, HTML ZIP import with media sideloading, a Google Docs API fallback for oversized exports, background sync, and Gutenberg block output.

## Requirements

- PHP 8.1 or newer
- WordPress 6.4 or newer
- Composer
- Node.js 20 or newer
- pnpm 9 or newer

## Development

Install PHP dependencies:

```sh
composer install
```

Install frontend dependencies:

```sh
pnpm install
```

Build the WordPress admin app:

```sh
pnpm build
```

Watch frontend assets during development:

```sh
pnpm dev
```

The Vite build writes hashed assets plus `build/manifest.json` and `build/manifest.post-sync.json`. WordPress reads those manifests and enqueues the central admin bundle on the Brasth Document Sync setup and sources pages, plus the post sync bundle on enabled post/page edit and list screens.

## Google Cloud Setup

1. Create or select a Google Cloud project.
2. Enable both the Google Drive API and Google Docs API in that project.
3. Configure the OAuth consent screen for the WordPress site users.
4. Create an OAuth 2.0 Web application client.
5. Add this authorized redirect URI:

```text
https://example.com/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback
```

Replace `https://example.com` with the WordPress site URL. The OAuth callback URL belongs in **Authorized redirect URIs** and must include `/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback`.

In WordPress admin, open **Brasth Document Sync** and follow the Google setup wizard. It shows setup progress, provides the exact redirect URI to copy, links to the required Google Cloud screens, and tests whether the saved plugin settings are complete.

Save:

- OAuth client ID and OAuth client secret. The wizard can import the downloaded Web application OAuth JSON to fill these fields locally in the browser.
- Enabled post types. `post` is always enabled; `page` and public custom post types are optional.
- Optional WP-Cron sync interval

Each WordPress user must connect their own Google account before inspecting or syncing documents.

## Sync Behavior

- Google Docs is the source of truth. Manual sync overwrites WordPress post content while preserving normal WordPress revisions.
- Sync exports Google Docs as an HTML ZIP package, imports local images into the WordPress Media Library, rewrites image URLs, sanitizes HTML, converts common elements to Gutenberg blocks, then updates the target post.
- If Google blocks an HTML ZIP export because the exported Workspace document exceeds its 10 MB export limit, Brasth Document Sync automatically retries through the Google Docs API large-doc fallback before changing WordPress content.
- Manual admin syncs run in the background through WP-Cron and show milestone-based progress. Percent values reflect sync steps, not byte-level Google export progress.
- Default Google scope is `https://www.googleapis.com/auth/drive.readonly`.
- Source selection uses Brasth Document Sync's custom Google Drive document browser. Pasted Google Doc URLs or raw file IDs remain available under advanced linking.
- Existing Google connections created with the old `drive.file` scope must reconnect before browsing or syncing Docs.
- Supported targets are `post`, optional `page`, plus enabled public custom post types that the current WordPress user can edit/create.

## Scheduling

Brasth Document Sync uses WP-Cron for scheduled sync and manual background sync. WP-Cron runs only when WordPress receives traffic, so low-traffic sites or sites with `DISABLE_WP_CRON` should use a real server cron hitting `wp-cron.php` for reliable sync completion.

## Runtime Notes

- The PHP namespace is `DocSyncWP\`.
- The plugin slug and text domain are `brasth-document-sync-for-google-docs`.
- React is provided by WordPress through the `wp-element` script handle.
- Admin app source imports WordPress packages for element runtime, REST fetch, i18n, URL helpers, a11y, and simple admin UI controls.
- Radix UI primitives remain the modal/tab interaction layer. React and React DOM are build-time peer dependencies only; Vite maps their runtime imports and JSX runtime helpers back to `wp.element`.
- The REST namespace reserved for future features is `brasth-document-sync-for-google-docs/v1`.
- Google OAuth client secrets and user tokens are encrypted with WordPress salts. Rotating those salts invalidates stored Brasth Document Sync credentials and tokens, so users must reconnect Google accounts afterward.
- Uninstall removes plugin settings, encrypted user Google tokens, and scheduled cron events. Linked post metadata is kept by default; define `DOCSYNC_WP_FULL_UNINSTALL` or return true from `docsync_wp_full_uninstall` to remove linked post meta. Synced posts are never deleted.
- Inline PHPCS suppression comments are prohibited in plugin source. Use code changes first; if a WordPress standards exception is unavoidable, keep it narrow in `phpcs.xml.dist`.

## Verification

```sh
composer install
vendor/bin/phpcs -i
composer validate --no-check-publish
composer lint
pnpm install --frozen-lockfile
pnpm lint
pnpm typecheck
pnpm build
```

Use `composer lint:fix` only for safe automatic PHPCS fixes. Keep unavoidable WordPress coding standards exceptions narrow and centralized in `phpcs.xml.dist`.

## Release Packaging

Build release artifacts from a clean checkout or by publishing a GitHub Release:

```sh
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile
pnpm build
```

The `Build Release ZIP (Tag)` workflow runs when a GitHub Release is published. It checks out the release tag, validates the tag version against `brasth-document-sync-for-google-docs.php` and `readme.txt`, installs production dependencies, builds frontend assets, stages files using `.distignore`, creates `brasth-document-sync-for-google-docs-v<version>.zip`, uploads that ZIP as a workflow artifact, and attaches it to the GitHub Release with `gh release upload --clobber`.

The release ZIP should include a single top-level `brasth-document-sync-for-google-docs/` directory containing `vendor/`, `build/`, `resources/`, `brasth-document-sync-for-google-docs.php`, `src/`, `uninstall.php`, `readme.txt`, `README.md`, `LICENSE`, `package.json`, `pnpm-lock.yaml`, `vite.config.ts`, and `composer.json`.

To backfill an existing release asset, run the same workflow manually from GitHub Actions with the release tag input. For the first public release, use:

```text
tag=1.0.0
```

This rebuilds the plugin from the existing `1.0.0` tag and replaces any existing `brasth-document-sync-for-google-docs-v1.0.0.zip` release asset.

WordPress.org/SVN submissions should keep the human-readable frontend source in `resources/` alongside the built assets in `build/`. Listing assets live in `assets/` for SVN root upload only and are excluded from installable ZIP files.

GitHub Release assets are installer-ready ZIP files for manual upload through WordPress admin. WordPress.org SVN deployment remains separate: commit plugin files directly under `trunk/`, copy releases to `tags/<version>/`, and do not commit ZIP files to SVN.
