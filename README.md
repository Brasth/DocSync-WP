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

The Vite build writes hashed assets plus screen-specific manifests for Setup, Sources, Logs, Post Sync, and the lazy Drive browser. WordPress reads those manifests and enqueues only the bundle needed by the current plugin admin screen, plus the post sync bundle on enabled post/page edit and list screens.

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

In WordPress admin, administrators open **Brasth Document Sync > Setup** to configure the site connection. The role-aware workspace separates the site-wide OAuth client from the current user's personal Google connection, shows one primary next action, and treats setup readiness as an intermediate state. Activation requires at least one source accessible to the current user to finish as `synced` or unchanged-complete (`skipped`) with a successful sync timestamp.

When the site and personal connections are ready, **Choose Google Doc** opens the existing source workflow directly on Setup or Sources. It creates a WordPress draft, queues the first sync, shows background progress and safe recovery copy, then offers **Open draft** after successful completion.

Save:

- OAuth client ID and OAuth client secret. The wizard can import the downloaded Web application OAuth JSON to fill these fields locally in the browser.
- Enabled post types. `post` is always enabled; `page` and public custom post types are optional.
- Default synced layout for block editor imports. New installs start with `Clean Article`; upgraded installs without this setting keep `Plain Blocks`.
- Optional WP-Cron sync interval

Each WordPress user must connect their own Google account before inspecting or syncing documents.

## Admin Experience

Setup, Sources, and Sync Activity share a branded Brasth admin shell with a compact product masthead, contained notices, consistent button sizing, and the runtime Brasth mark from `resources/images/`. Setup remains restricted to administrators with `manage_options`; users who can edit or create an enabled target type can use the operational Sources and Sync Activity surfaces. Direct submenu URLs remain stable. The top-level plugin entry opens Setup for administrators who still need site configuration or an initial source, and Sources for operational users. Destructive admin actions use Radix confirmation dialogs instead of native browser prompts.

The Sources screen is the daily operational home. It shows a permission-filtered health summary and orders sources needing attention before active syncs, then healthy sources, while preserving URL-backed search/status/type filters and pagination. It also keeps compact row actions and background sync polling. The Sync Activity screen keeps URL-backed filters and auto-refresh, shows useful summaries only when events exist, and manages source or all-log clearing through the shared confirmation dialog. Sync events include the safe output path used for a run: Gutenberg preset, Elementor preset, or legacy Elementor converter. When Elementor is enabled, the Google Doc link modal asks whether the source should sync as WordPress Blocks or an Elementor Layout before saving the source.

Scheduled syncs continue to use the source's recorded sync owner. Relinking a source from another operator's Google connection returns an explicit transfer requirement; the confirmation changes scheduled-sync responsibility without removing WordPress content, revisions, or source settings.

## Sync Behavior

- Google Docs is the source of truth. Manual sync overwrites WordPress post content while preserving normal WordPress revisions.
- Sync exports Google Docs as an HTML ZIP package, imports local images into the WordPress Media Library, rewrites image URLs, sanitizes HTML, converts common elements to Gutenberg blocks, renders standalone images as native `core/image` blocks, then updates the target post.
- Site admins can choose the default Gutenberg sync layout from `Clean Article`, `Documentation`, and `Plain Blocks`. Individual linked sources can override that preset before sync; `Use site default` stores no per-source override.
- Elementor sync uses separate Elementor presets: `Elementor Hero Page` and `Elementor Feature Block`. Existing Elementor sources without an explicit Elementor preset keep the legacy Elementor conversion path until a preset is selected, and the post sync metabox shows upgrade actions for Feature Block or Hero Page.
- The `Documentation` layout renders semantic `pre`/`code` HTML, fenced snippets, and Google Docs styled code-like paragraphs as `core/code` blocks. It uses balanced heuristics for shell commands, XML/JSON snippets, Java/PHP/JavaScript-like statements, Gherkin steps, paths, and file trees; it is not a full programming-language parser.
- Explicit `Note:`, `Tip:`, `Warning:`, `Important:`, and `Caution:` paragraphs render as quote-style callouts in the `Documentation` layout.
- If Google blocks an HTML ZIP export because the exported Workspace document exceeds its 10 MB export limit, Brasth Document Sync automatically retries through the Google Docs API large-doc fallback before changing WordPress content.
- Manual admin syncs run in the background through WP-Cron and show milestone-based progress. Percent values reflect sync steps, not byte-level Google export progress.
- Default Google scope is `https://www.googleapis.com/auth/drive.readonly`.
- Source selection uses Brasth Document Sync's custom Google Drive document browser. Pasted Google Doc URLs or raw file IDs remain available under advanced linking.
- Existing Google connections created with the old `drive.file` scope must reconnect before browsing or syncing Docs.
- Supported targets are `post`, optional `page`, plus enabled public custom post types that the current WordPress user can edit/create.

## Scheduling

Brasth Document Sync uses WP-Cron for scheduled sync and manual background sync. WP-Cron runs only when WordPress receives traffic, so low-traffic sites or sites with `DISABLE_WP_CRON` should use a real server cron hitting `wp-cron.php` for reliable sync completion. The supplied local dev stack includes an internal WP-CLI cron worker because its browser-facing port is intentionally not available to container loopback requests.

## Runtime Notes

- The PHP namespace is `DocSyncWP\`.
- The plugin slug and text domain are `brasth-document-sync-for-google-docs`.
- React is provided by WordPress through the `wp-element` script handle.
- Admin app source imports WordPress packages for element runtime, REST fetch, i18n, URL helpers, a11y, and simple admin UI controls.
- Radix UI primitives remain the modal/tab interaction layer. React and React DOM are build-time peer dependencies only; Vite maps their runtime imports and JSX runtime helpers back to `wp.element`.
- The REST namespace reserved for future features is `brasth-document-sync-for-google-docs/v1`.
- `GET /workspace` is the nonce-protected, least-privilege operational bootstrap route. It returns capability-filtered target types, safe publishing defaults, Elementor availability, and accessible-source health counts; it never returns OAuth credentials, Google account identity, telemetry choices, schedules, source IDs, owner IDs, or raw errors.
- Google OAuth client secrets and user tokens are encrypted with WordPress salts. Rotating those salts invalidates stored Brasth Document Sync credentials and tokens, so users must reconnect Google accounts afterward.
- Clearing the saved site OAuth configuration is administrator-only. It removes the client credentials, invalidates in-flight OAuth state, deletes locally stored Google connections for all plugin users, and unschedules sync jobs while retaining linked sources and WordPress content.
- Optional anonymous active-install telemetry is default off. Setup includes a dismissible inline opt-in prompt plus the permanent Sync defaults checkbox. When enabled, telemetry sends one weekly install-level check-in to `https://telemetry.brasth.com/v1/check-in` through `src/Telemetry/`; the Cloudflare Worker lives under `cloudflare/telemetry-worker/` and is excluded from installable plugin ZIPs.
- Uninstall removes plugin settings, encrypted user Google tokens, and scheduled cron events. Linked post metadata is kept by default; define `DOCSYNC_WP_FULL_UNINSTALL` or return true from `docsync_wp_full_uninstall` to remove linked post meta. Synced posts are never deleted.
- Inline PHPCS suppression comments are prohibited in plugin source. Use code changes first; if a WordPress standards exception is unavoidable, keep it narrow in `phpcs.xml.dist`.

## Verification

```sh
composer install
vendor/bin/phpcs -i
composer validate --no-check-publish
composer lint
composer test:layout-fixtures
composer test:elementor-fixtures
composer test:large-doc-fallback-fixtures
composer test:telemetry-settings
pnpm install --frozen-lockfile
pnpm lint
pnpm typecheck
pnpm build
```

Use `composer lint:fix` only for safe automatic PHPCS fixes. Keep unavoidable WordPress coding standards exceptions narrow and centralized in `phpcs.xml.dist`.

A ready-to-use WordPress dev container is available under `.devcontainer/`. It runs WordPress at `http://localhost:8890`, activates the plugin, and verifies core runtime routes after startup, including the role-aware `/workspace` route.

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
