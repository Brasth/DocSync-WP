# Brasth Document Sync for Google Docs

Documentation: [docsyncwp.com](https://docsyncwp.com/) · [How it works](https://docsyncwp.com/how-it-works/) · [User guide](https://docsyncwp.com/user-guide/)

Brasth Document Sync for Google Docs is a WordPress plugin for syncing Google Docs into WordPress posts, pages, and enabled public custom post types. It uses self-managed Google OAuth, a server-side Drive browser, HTML ZIP import with media sideloading, a Google Docs API fallback for oversized exports, background sync, and Gutenberg block output.

## Requirements

- PHP 8.1 or newer
- WordPress 6.4 or newer
- Composer
- Node.js 20.19 or newer
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

The Vite build writes hashed assets plus eight screen-specific manifests: Setup, Sources, Drive Folders, Logs, Post Sync, the source modal, the lazy Drive browser, and `pdf-renderer`. WordPress reads those manifests and enqueues only the bundle needed by the current plugin admin screen, plus the post sync bundle on enabled post/page edit and list screens. The `pdf-renderer` bundle stays out of those screens until a PDF preview needs it. PDF.js 5.6.205, its worker, and its cmap, font, and wasm files are local files under `build/`. The admin app does not load them from a CDN.

## Google Cloud Setup

1. Create or select a Google Cloud project.
2. Enable the Google Drive API, the Google Docs API, and the Google Slides API in that project.
3. Configure the OAuth consent screen for the WordPress site users.
4. Create an OAuth 2.0 Web application client.
5. Add this authorized redirect URI:

```text
https://example.com/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback
```

Replace `https://example.com` with the WordPress site URL. The OAuth callback URL belongs in **Authorized redirect URIs** and must include `/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback`.

In WordPress admin, administrators open **Brasth Document Sync > Setup** for a three-step journey: save the site Google OAuth client, connect their own Google account, then import a first Doc or watch a folder. Readiness alone does not complete onboarding. Activation derives from an accessible source with a retained successful `lastSyncedAt` timestamp, a folder watch that has imported at least one Doc, or a completed add-content import the same user can sync. A file preview that has not been committed does not count. A later sync failure changes source health but does not erase that prior success. Removing the qualifying source, watch, or completed import can make activation false again; no separate onboarding flag is stored.

The first-source screen offers **Add one Google Doc** and **Watch a Drive folder**. **Add one Google Doc** opens the add-content dialog. **Watch a Drive folder** stays on the folder-watch flow and creates drafts from Docs already in Drive. **Skip for now** opens Sources; **Change defaults** opens settings while keeping the first import pending. After activation, Setup shows Google connections and Sync defaults side by side. Credential and default edits prompt before unsaved changes are discarded. Administrators can inspect eligible operators’ local connection states; that view does not contact Google or expose their Google emails or tokens. Folder schedules govern new-Doc discovery and member re-sync. Maintenance settings now has **General** and **Sync health** tabs. Sync health reports seven local checks, bounded seven-day activity, and a report containing allowlisted event codes. Google quota and refresh-token expiry remain unavailable; no Google request is made by the health read. The cron action requests background execution and requires a refresh to observe a new tick. Clearing shared OAuth configuration requires typing `clear`. Notifications remains a future tab.

Save:

- OAuth client ID and OAuth client secret. The wizard can import the downloaded Web application OAuth JSON to fill these fields locally in the browser.
- Enabled post types. `post` is always enabled; `page` and public custom post types are optional.
- Default synced layout for block editor imports. New installs start with `Clean Article`; upgraded installs without this setting keep `Plain Blocks`.
- Optional WP-Cron sync interval

Each WordPress user must connect their own Google account before inspecting or syncing documents.

## Admin Experience

Setup, Sources, and Sync Activity share a branded Brasth admin shell with a compact product masthead, contained notices, consistent button sizing, and the runtime Brasth mark from `resources/images/`. Setup remains restricted to administrators with `manage_options`; users who can edit or create an enabled target type can use the operational Sources and Sync Activity surfaces. Direct submenu URLs remain stable. The top-level plugin entry opens Setup for administrators who still need site configuration or an initial source, and Sources for operational users. Destructive admin actions use Radix confirmation dialogs instead of native browser prompts.

The Sources screen is the daily operational home. It shows a permission-filtered health summary and orders sources needing attention before active syncs, then healthy sources, while preserving URL-backed search/status/type/`folderWatchId` filters and pagination. Drive Folders is the dedicated management surface for folder watches: operators can edit schedule, post status, presets, subfolders, and excludes without recreating a watch, see next scan time, and get a WP-Cron stall warning when the last plugin cron tick is older than twice the shortest active interval (minimum two hours). It also keeps compact row actions and background sync polling. The Sync Activity screen keeps URL-backed filters and auto-refresh, shows useful summaries only when events exist, and manages source or all-log clearing through the shared confirmation dialog. Sync events include the safe output path used for a run: Gutenberg preset, Elementor preset, or legacy Elementor converter. When Elementor is enabled, the Google Doc link modal asks whether the source should sync as WordPress Blocks or an Elementor Layout before saving the source.

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
- Creating a converted file or a new Google Doc asks for `https://www.googleapis.com/auth/drive.file` only after the user opts in. That request keeps `drive.readonly` and adds `drive.file`. The plugin uses it for the **Imported from WordPress** folder, Word and PowerPoint conversions, and Docs created from posts. It trashes only files it created. It does not permanently delete Google files, and it leaves the user's original Docs in place.
- Supported targets are `post`, optional `page`, plus enabled public custom post types that the current WordPress user can edit/create.

## Add Content

Sources can add several Google Docs, upload Word, PowerPoint, or PDF files, or link posts that already exist. The same dialog opens from Setup's **Add one Google Doc** card, from Sources, and from the post editor. Watching a folder stays on the folder-watch flow.

Six screens are implemented: connect a Google account, choose Google Docs, upload files, preview and commit uploads, review a PowerPoint deck, and link existing posts. They call the live routes. This README does not record a pixel-level match to the design artboards.

### Google Docs, Word, PowerPoint, And PDF

Pasting several Doc URLs or file IDs, or browsing Drive, adds up to 20 Docs in one batch. Each item keeps its own result. A new Google Doc target uses the existing sync rules, including background sync. An existing post in that batch attaches only.

Word, PowerPoint, and PDF uploads commit drafts only.

- **Word (DOCX), Keep synced on.** This is the default. The plugin creates a Google Doc under the connected account's **My Drive / Imported from WordPress** and keeps it. After commit, the draft is linked to that Doc with status `linked`. Later changes follow an explicit Sync or the normal schedule.
- **Word, Keep synced off.** The commit is one-time. The plugin then trashes the conversion it created. The draft has no Google file ID and no scheduled sync. Provenance records `googleDocsOneTime`.
- **PowerPoint (PPTX).** Always one-time. The plugin converts the file with the Google Slides API, then trashes that temporary presentation after a successful commit. Provenance records `googleSlidesOneTime`.
- **PDF.** The server reads text with the production Composer package `smalot/pdfparser` (`^2.12`, locked at 2.12.5). The admin browser draws pages with `pdfjs-dist` 5.6.205 from local `build/` files. PDF bytes stay on the site. A PDF with no selectable text is rejected as scanned. An encrypted PDF is rejected. The plugin does not run OCR. Provenance records `localPdf`.

DOCX and PPTX need PHP `ZipArchive`. Without it, those two formats fail per file with `docsync_wp_import_zip_unavailable`. PDF upload, Google Doc batches, matching, and the content list keep working.

The preview is lossy. Dropped styling, simplified tables, removed links, and unsupported PowerPoint objects become numbered warnings. The same numbers appear in the slide navigator, the preview, the caption, and the committed draft. An unsupported slide still shows a private thumbnail. Supported text, tables, and images on that slide stay in the draft.

Commit stores the canonical preview the user accepted. A changed preview fingerprint returns `409` `docsync_wp_import_preview_stale` until the user reviews the file again. The same idempotency key and body return the stored result for 24 hours. A different body for that key returns `409` `docsync_wp_idempotency_conflict`. Private preview image URLs become Media Library URLs in the draft. The commit does not queue a sync.

### Private Upload Storage

A user may have 3 open upload sessions. Each session holds at most 20 files for 24 hours from creation. The expiry time is fixed. Each file may be at most 25 MiB, or the host's `wp_max_upload_size()` when that limit is lower.

Only the session owner can read the session or its files. Another user gets a not-found response. Bytes go outside the web root when `DOCSYNC_WP_PRIVATE_STORAGE_DIR` is an absolute writable directory outside the WordPress root. Otherwise they are encrypted under `uploads/docsync-wp-private/`. If neither store can be created, upload storage refuses the session.

An upload session creates no post, revision, or Media Library file before commit. Cancel and the hourly cleanup remove the private bytes and trash Google files the plugin created for that session, including a Word conversion that would have been kept. After a successful Keep-synced commit, that Doc stays in **Imported from WordPress**. A failed trash stays queued for retry.

The admin bootstrap sets `canUploadFiles` from `current_user_can( 'upload_files' )`. Sources shows **Upload files** only when that flag is true and the user can create a target. On an existing post, the dialog attaches a Google Doc. Import routes and the commit worker require `upload_files` again and return `403` `docsync_wp_import_forbidden` when it is missing.

### Linking Existing Posts

**Link existing posts** compares WordPress posts with Docs the account can read. A row is preselected only for one exact normalized title, or one exact match of the first 100 normalized tokens when both sides have at least 20 tokens, and only when the folder inventory finished. A truncated inventory lists candidates for a manual choice and preselects none. Commit saves source metadata with status `linked`. The post body stays as it is. The first content update is a later explicit Sync or the normal schedule, and that update keeps normal WordPress revisions.

`POST /sources` accepts `syncMode` `attach_only` only with an existing target. `GET /sources` and `GET /sources/{postId}/content` keep their current responses. The mixed list is `GET /content`: Google-linked posts, including Keep-synced Word, and completed one-time imports the caller can sync. One-time rows have no Sync action. An uncommitted preview is absent from this list and does not activate the workspace.

Wire shapes for these routes are in `plans/20261010-journey-2-add-source/contracts.md`.

### Deactivation And Uninstall

Deactivation clears `docsync_wp_import_convert`, `docsync_wp_import_commit`, `docsync_wp_matching_run`, and `docsync_wp_import_cleanup`, along with the existing sync and telemetry schedules.

Uninstall removes add-content options, OAuth continuation user meta, the stored import-folder ID, and the plugin-owned `docsync-wp-private` directory. Provenance meta `_docsync_wp_import_provenance` and `_docsync_wp_import_kind` is removed only when `DOCSYNC_WP_FULL_UNINSTALL` is true or `docsync_wp_full_uninstall` returns true. Uninstall does not delete posts, Media Library files, or Google Drive files.

## Scheduling

Brasth Document Sync uses WP-Cron for scheduled sync and manual background sync. WP-Cron runs only when WordPress receives traffic, so low-traffic sites or sites with `DISABLE_WP_CRON` should use a real server cron hitting `wp-cron.php` for reliable sync completion. The supplied local dev stack includes an internal WP-CLI cron worker because its browser-facing port is intentionally not available to container loopback requests.

## Runtime Notes

- The PHP namespace is `DocSyncWP\`.
- The plugin slug and text domain are `brasth-document-sync-for-google-docs`.
- React is provided by WordPress through the `wp-element` script handle.
- Admin app source imports WordPress packages for element runtime, REST fetch, i18n, URL helpers, a11y, and simple admin UI controls.
- Radix UI primitives remain the modal/tab interaction layer. React and React DOM are build-time peer dependencies only; Vite maps their runtime imports and JSX runtime helpers back to `wp.element`.
- The REST namespace is `brasth-document-sync-for-google-docs/v1`.
- `GET /workspace` is the nonce-protected, least-privilege operational bootstrap route. It returns capability-filtered target types, safe publishing defaults, Elementor availability, accessible-source health counts, and `cronHealth`; it never returns OAuth credentials, Google account identity, telemetry choices, schedules, source IDs, owner IDs, or raw errors.
- Google OAuth client secrets and user tokens are encrypted with WordPress salts. Rotating those salts invalidates stored Brasth Document Sync credentials and tokens, so users must reconnect Google accounts afterward.
- Clearing the saved site OAuth configuration is administrator-only. It removes the client credentials, invalidates in-flight OAuth state, deletes locally stored Google connections for all plugin users, and unschedules sync jobs while retaining linked sources and WordPress content.
- Optional anonymous active-install telemetry is default off. Setup maintenance includes a dismissible inline opt-in prompt plus the permanent Sync defaults checkbox. When enabled, telemetry sends one weekly install-level check-in to `https://telemetry.brasth.com/v1/check-in` through `src/Telemetry/`; the Cloudflare Worker lives under `cloudflare/telemetry-worker/` and is excluded from installable plugin ZIPs.
- Admin users can open **Send feedback** from the shared admin shell. The authenticated WordPress REST route relays validated bug reports, feature requests, and questions to the Cloudflare feedback Worker, which creates public issues in `Brasth/DocSync-WP`. Reports are short-lived rate-limited to 5 per user and 20 per IP per hour. Do not include secrets or private data. The GitHub token exists only as the Worker secret `GITHUB_TOKEN`; configure the Worker separately under `cloudflare/feedback-worker/`.
- Uninstall removes plugin settings, encrypted user Google tokens, scheduled cron events, add-content session and job options, and the plugin-owned private upload directory. Linked post metadata, including import provenance, stays unless `DOCSYNC_WP_FULL_UNINSTALL` is true or `docsync_wp_full_uninstall` returns true. Uninstall leaves posts, Media Library files, and Google Drive files in place.
- Inline PHPCS suppression comments are prohibited in plugin source. Use code changes first; if a WordPress standards exception is unavoidable, keep it narrow in `phpcs.xml.dist`.

## Feedback Worker

Deploy the Worker outside the plugin ZIP and store its GitHub credential as a Cloudflare secret:

```sh
cd cloudflare/feedback-worker
wrangler secret put GITHUB_TOKEN
wrangler secret put WORKER_SHARED_SECRET
wrangler deploy
```

The Worker token should be a fine-grained token limited to `Brasth/DocSync-WP` with Issues read/write access only. The plugin uses `https://feedback.brasth.com/v1/issues` by default. Override it in `wp-config.php` when using another hostname:

```php
define( 'DOCSYNC_WP_FEEDBACK_ENDPOINT', 'https://feedback.example.com/v1/issues' );
define( 'DOCSYNC_WP_FEEDBACK_WORKER_SECRET', 'the-same-worker-secret' );
```

`cloudflare/` is excluded by `.distignore`, so Worker source and secrets never enter the installable plugin package.

## Verification

Local lint and typecheck remain available. GitHub Actions runs build, package, and deploy workflows only; it does not run tests or lint checks.

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

Use `composer lint:fix` only for safe automatic PHPCS fixes. Keep unavoidable WordPress coding standards exceptions narrow and centralized in `phpcs.xml.dist`. Plugin source does not use inline `phpcs:ignore` comments.

Run Journey 2 checks inside the WordPress container. Host `node_modules` is Linux-built (esbuild), so the macOS host cannot run the suite; use the container command:

```sh
docker exec -w /var/www/html/wp-content/plugins/brasth-document-sync-for-google-docs docsync-wp-devcontainer-wordpress-1 node scripts/test-journey-2.mjs
```

`--ui-only` runs the 22 UI checks and skips PHP. The default run covers 146 PHP import + 157 mock Google + matching 233/235 + 22 UI/crop 0. All 8 Vite build modes exit 0.

Add-content checks for this source were exercised on PHP 8.3 and WordPress 7.1. The declared minimum remains PHP 8.1 and WordPress 6.4, and that older pair is not yet re-proven for add content. Live reject paths covered encrypted PDF, plain-text invalid DOCX, macro DOCX, and scanned PDF. Google API behavior in the suite is mocked. A live Google account is not configured for an end-to-end Google pass, and 100% parity is not claimed. Independent review was unavailable because of provider quota. Google API quota is not a measured figure. A pixel-level artboard result is not recorded. Older UI/import/integration/contracts snapshots are stale after corrections, so main-workflow final acceptance remains pending.

A ready-to-use WordPress dev container is available under `.devcontainer/`. It runs WordPress at `http://localhost:8890` and activates the plugin after startup.

## Release Packaging

Build release artifacts from a clean checkout or by publishing a GitHub Release:

```sh
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile
pnpm build
```

The `Build Release ZIP (Tag)` workflow runs when a GitHub Release is published. It checks out the release tag, resolves release metadata from the tag name and plugin header version, installs production dependencies, builds frontend assets, stages files using `.distignore`, creates `brasth-document-sync-for-google-docs-v<version>.zip`, uploads that ZIP as a workflow artifact, and attaches it to the GitHub Release with `gh release upload --clobber`.

The release ZIP should include a single top-level `brasth-document-sync-for-google-docs/` directory containing `vendor/`, `build/` (eight manifests, the local PDF.js worker, and `build/assets/pdf/`), `resources/`, `brasth-document-sync-for-google-docs.php`, `src/`, `uninstall.php`, `readme.txt`, `README.md`, `LICENSE`, `package.json`, `pnpm-lock.yaml`, `vite.config.ts`, and `composer.json`. `.rig`, `docsync-wp-private/`, and `docsync-journey-fixtures/` stay out of the package. `smalot/pdfparser` ships in production `vendor/` because it is a Composer `require`, not a dev dependency.

To backfill an existing release asset, run the same workflow manually from GitHub Actions with the release tag input. For the first public release, use:

```text
tag=1.0.0
```

This rebuilds the plugin from the existing `1.0.0` tag and replaces any existing `brasth-document-sync-for-google-docs-v1.0.0.zip` release asset.

WordPress.org/SVN submissions should keep the human-readable frontend source in `resources/` alongside the built assets in `build/`. Listing assets live in `assets/` for SVN root upload only and are excluded from installable ZIP files.

GitHub Release assets are installer-ready ZIP files for manual upload through WordPress admin. WordPress.org SVN deployment remains separate: commit plugin files directly under `trunk/`, copy releases to `tags/<version>/`, and do not commit ZIP files to SVN.
