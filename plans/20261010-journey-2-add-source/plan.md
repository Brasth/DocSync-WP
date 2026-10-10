# Journey 2: add source

Date: 2026-10-10 · Status: implemented · Final acceptance: pending · Report: [reports/implementation-verification.md](reports/implementation-verification.md) · Contracts: [contracts.md](contracts.md)

**Outcome.** Implement all six accepted Claude Journey 2 artboards together, with real WordPress and Google behavior. The user accepted all six, including Later bulk linking, PPTX, and PDF:
- add several Google Docs at once (URL paste or browse with location, global search, owner, and linked filters)
- upload DOCX, PPTX, and PDF files with per-file options and a preview
- review and commit the files, with per-file results
- link existing posts to Docs in bulk (Later), with compare and create-doc

The artboards decide every visual detail and must be reproduced exactly. Wire shapes, service signatures, and wiring come only from `contracts.md`. The rejected `REST_SCHEMA_CONTRACTS.md` is not an authority.

**Constraints.** All the invariants in contracts §1 apply. The ones this plan most depends on:
- Folder watches, the legacy sources REST contract, source meta, and permissions stay as they are.
- A session holds at most 20 private uploads of `min(25 MiB, host limit)` each and expires after 24 hours. It belongs to its owner, storage is outside the web root or encrypted, and it fails closed.
- Nothing creates posts, drafts, or media before commit. Nothing uses `preview_post_id`. Upload commits create drafts only.
- Every upload commit, including Keep-synced DOCX, inserts one draft from `CanonicalRenderer` output that equals the accepted preview and preset. Keep-synced DOCX then calls `SyncService::attachSource` (metadata only). The commit never calls `createDraftFromSource` or `syncPost`.
- Provenance is saved on success for both `syncedWord` and `oneTime` imports, and both count toward activation.
- Linking is attach-only and never overwrites content immediately.
- PDF is processed locally (smalot plus PDF.js). PPTX is a one-time Google Slides import. DOCX keeps a synced Google Doc by default, retained in My Drive / Imported from WordPress.
- Every app-created Google conversion, including the Keep-synced Doc, is persisted in the cleanup set through the converter's `onCreatedFile` callback as soon as Drive returns its ID, before any Docs or Slides read, and stays there until its commit fully succeeds. A failed trash keeps the ID and its session or job record for retry. The plugin trashes only files it created and never user originals.
- Matching inventories folder scopes recursively (all descendants, all pages) in bounded ticks. When the inventory is truncated, nothing is preselected and the UI shows the truncation warnings; manual choices stay available.
- PDF.js is network-lazy: it lives only in the dedicated `pdf-renderer` bundle, loaded by URL when a PDF preview needs it.
- Missing PHP `ZipArchive` rejects only DOCX and PPTX uploads, with a clear per-file error. It does not disable Journey 2, PDF, or Google Docs routes.
- `drive.file` is requested only after the user opts in, and continuations are persistent and allowlisted.
- One-time imports never get a fake Google ID or a scheduled job.
- No `Plugin.php` or `SourceRepository` edits. Getters are added only to `SourceController`, `DocumentController`, and `OAuthController`. Provider-injected setters are added to `SourceController` (`setSourceBatch`), `DocumentController` (`setSourceRepository`), and `WorkspaceController` (`setImportProvenance`). No existing constructor changes.
- PPTX slides with unsupported visuals (chart, animation, video, WordArt, group) always get a private rendered thumbnail fallback and numbered warnings, even with slide images off.

**Non-goals.** Elementor output for uploads. Formats other than DOCX, PPTX, and PDF. OCR for scanned PDFs. Hosted conversion services. Changes to Setup Journey 1 beyond opening the modal from its first-source step and using it as a return target.

## Phases

1. **Foundation and wiring** (contracts §10).
   - Add `getDependencies()` to `SourceController`, `DocumentController`, and `OAuthController`. Add the setters `SourceController::setSourceBatch()`, `DocumentController::setSourceRepository()`, and `WorkspaceController::setImportProvenance()`. No constructor changes, and `SourceController` never names a Journey 2 class.
   - Add `RestServiceProvider::getDependencies()`. `register()` builds `DocSyncWP\Journey2ServiceProvider` (guarded by `class_exists`), registers it, and injects the setters only when it is ready.
   - Add `src/Journey2ServiceProvider.php`, which builds every Journey 2 service once, including the new stateless `SettingsRepository` with the same registries as `Plugin::boot()`.
   - Register the four cron hooks. Clear them on deactivation and remove all Journey 2 data on uninstall.
   - Add `smalot/pdfparser` and `pdfjs-dist`.
   - Build integration for the network-lazy PDF renderer (contracts §11.1): the `pdf-renderer` Vite mode with entry `resources/js/admin/features/add-content/pdf-renderer.ts`, its build script, and the `pdfRendererScriptUrl` key in `AssetRegistry::adminConfig()`.

   Files: `src/Rest/SourceController.php`, `src/Rest/DocumentController.php`, `src/Rest/OAuthController.php`, `src/Rest/WorkspaceController.php` (getters, setters, and the listed additions only), `src/Rest/RestServiceProvider.php`, `src/Journey2ServiceProvider.php`, `brasth-document-sync-for-google-docs.php` (`docsync_wp_deactivate()` only), `uninstall.php`, `composer.json`, `package.json`, `vite.config.ts` (the `pdf-renderer` mode only), `src/Assets/AssetRegistry.php` (`pdfRendererScriptUrl` only).
2. **Google auth and clients** (contracts §6, §9 Google and Auth).
   - `src/Auth/OAuthContinuationStore.php`.
   - `GoogleOAuthService` additions: `drive.file` opt-in, `userHasDriveFileScope`, continuation URLs, and the consumed action.
   - Extend `/oauth/google/url` and `/oauth/google/account` in `OAuthController`.
   - `src/Google/DriveWriteClient.php` (with `ensureImportFolder`), `src/Google/SlidesClient.php`, and `DriveClient::searchDriveItems`.
   - The `/drive/items` filters (location, globalSearch, owner, linked) in `DocumentController`.
3. **Sources, content, and activation** (contracts §7).
   - `src/Sync/SourceBatchService.php` and `POST /sources/batch`, using the injected batch service.
   - `syncMode: attach_only` on `POST /sources` for existing targets.
   - `src/Import/ImportProvenanceRepository.php`, which writes the provenance and `_docsync_wp_import_kind` meta and provides `listAccessibleContent`, `formatContentItem`, and `hasAccessibleSuccess`.
   - `src/Rest/ContentController.php` with the combined `GET /content` listing (`google` with `importedFrom`, or `oneTime`), including paging, sort, kind, post type, search, and `postId` lookup. The legacy `GET /sources/{postId}/content` stays unchanged.
   - Activation: `WorkspaceController::formatSourceSummary` ORs in `hasAccessibleSuccess`. `SourceRepository` is not edited.
   - The post-sync panel reads `GET /content?postId=` and shows one-time provenance with no Sync action, and the imported-from note for `syncedWord` posts.
4. **Import pipeline** (contracts §3, §4, §8, §9 Import).
   - Storage and validation: `PrivateAssetStore`, `UploadValidator`, and `ImportSessionRepository`.
   - Canonical model and renderer: `CanonicalDocument` (with `applyOptions` and `detectPptx`) and `CanonicalRenderer`, which goes through `LayoutConversionService::convert` and computes the preview fingerprint.
   - Converters: `DocxConverter`, `DeckConverter`, and `PdfConverter`.
     - `DeckConverter` PPTX defaults: notes included when detected, slide images off, merge titles off, a title-only first slide and a thank-you or Q&A last slide skipped by default, all overridable.
     - `DeckConverter` also caches a real thumbnail for every slide (at most 20 per tick, with backoff) for the navigator.
     - `DeckConverter` detects charts, animations, video, WordArt, and unsupported groups from the Slides API plus the original slide XML. Each gets a private thumbnail fallback and numbered warnings.
     - `PdfConverter` rejects scanned or encrypted files and declares pending renders.
     - `DocxConverter::convert` and `DeckConverter::convert` take `?callable $on_created_file` and call it right after `uploadForConversion`, before any Docs or Slides read. `ImportService` passes a closure over `ImportSessionRepository::addGoogleTemporary`. Every converter error carries the IDs created so far.
     - `UploadValidator` rejects DOCX and PPTX with `docsync_wp_import_zip_unavailable` when `ZipArchive` is missing; PDF is unaffected.
   - Orchestration: `ImportService`, the async `ImportCommitter` (idempotency, per-file fingerprints, resumable `commitState`, drafts only, canonical draft then `attachSource` for Keep-synced DOCX, provenance, temporaries removed or trashed), `ImportCleanup` (expiry plus `listCleanupDue` retries with backoff; records with remaining IDs are never deleted), and `ImportController`.
5. **Matching** (contracts §5, §9 Matching). `MatchNormalizer`, `MatchSessionRepository`, `MatchingService` (bounded-tick background job: recursive folder inventory with a paged queue and limits, then matching; truncation warnings; no preselection on an incomplete inventory; compare, attach-only commit, create-doc into the import folder, expiry cleanup with retryable records), and `MatchingController`.
6. **Frontend: the six screens** (contracts §11).
   - Location: `resources/js/admin/features/add-content/` (root `add-content-dialog.tsx`). The existing `DocSourceModal` imports `AddContentDialog` directly and opens it from Sources and the Setup first-source step.
   - No new file under `entries/`, no global export from `doc-source-modal-entry.ts`, and no `add-source` folder. The only new build mode is `pdf-renderer` (phase 1).
   - Styles: `resources/css/components/journey-add-content.css`, imported by the existing `resources/css/doc-source-modal-entry.css`.
   - URL-backed `view`/`session`/`job` state lets OAuth resume land on the same screen.
   - PPTX: the slide navigator shows real thumbnails for all slides, with warning numbers.
   - Data: typed API clients in `resources/js/admin/api/` that mirror the contracts.
   - PDF: `pdf-renderer.ts` exposes `window.DocSyncWPPdfRenderer` (contracts §11.1). `pdf-renderer-loader.ts` injects `pdfRendererScriptUrl` only when a PDF panel mounts. Thumbnails, the page picker, and render uploads read the private original (`originalAssetId`), and the hashed local worker is served from `build/assets/js/`.
   - Matching: inventory progress and truncation warnings; rows with `preselectBlocked:"inventoryIncomplete"` show their exact candidates for manual choice.
   - Commit UX: polling for conversion and commit progress, the compare drawer, and per-file and per-pair results.
   - Checks: pixel-level layout checks against each artboard at desktop and the breakpoints shown in the artboards.
7. **Verification and docs.**
   - Run `composer lint`, `pnpm lint`, `pnpm typecheck`, and `pnpm build`.
   - Manual end-to-end runs on the dev container, covering each acceptance item below.
   - Update the README (Journey 2, scopes, limits, storage constant, import folder), the `readme.txt` privacy and policy text (`drive.file`, private uploads, app-created Google files), and `docs/system-architecture.md`.

Order: phases 1 → 2 → 3. Phases 4 and 5 run in parallel after 3. Phase 6 starts on contract types after 1 and integrates after 4 and 5. Phase 7 runs last.

## Acceptance

- All six artboards are reproduced exactly, with live data and no placeholder states or controls.
- Legacy `/sources` (including `/sources/{postId}/content`), `/folders`, `/drive/items` (snake_case parameters), and `/oauth` requests return the same responses as before. Folder watch scans and imports are unaffected. `Plugin.php`, `SourceRepository`, `SettingsController`, and `FolderWatchController` are unchanged. `WorkspaceController` changes only by its setter and the activation term, and the `sourceSummary` wire is unchanged.
- `GET /content` lists Google and one-time posts the caller can sync, with correct `kind`, paging (`hasMore`, `truncated`), sort, and filters. `postId` lookup drives the post-sync panel.
- With Journey 2 classes absent or not ready, every legacy route still works, `/sources/batch` returns 503, and no Journey 2 route is registered.
- Uploads: a 21st file is rejected. A file over `min(25 MiB, host limit)` is rejected. Encrypted, scanned, and macro files are rejected. A non-owner gets 404 on the session and its assets. The session is gone after 24 hours with its bytes purged. Storage refuses uploads when it has neither an outside-web-root directory nor encryption.
- Until commit, a session adds no new posts, revisions, or attachments. A stale preview fingerprint blocks commit. Replaying an idempotency key returns the same result. A retried commit worker never creates a second post for a file.
- Every committed upload is a draft whose content equals the accepted preview blocks except for attachment URLs.
- A Keep-synced DOCX commit produces one draft, a `linked` source with the real Drive ID of the Doc in My Drive / Imported from WordPress, `syncedWord` provenance, and no sync queued by the commit. `createDraftFromSource` and `syncPost` are never called by the import commit.
- A one-time post has `oneTime` provenance and no Google file ID, next sync, or cron event.
- A successful import of either kind sets `sourceSummary.activated`.
- Cancelling or letting a session expire before commit trashes every app-created conversion, including the would-be Keep-synced Doc. After a successful commit, the retained Doc is never trashed. User originals and the import folder are never trashed.
- PPTX defaults: notes on only when detected, slide images off, merge titles off, a title-only first slide and a thank-you or Q&A last slide unselected, and all of these overridable.
- PPTX: the navigator shows a real thumbnail for every slide. A slide with a chart, animation, video, WordArt, or unsupported group shows a private thumbnail fallback with numbered warnings in the preview and the committed draft, even with `addSlideImages` off. Numbers match across the navigator, preview, and caption. Changing a PPTX option never refetches thumbnails.
- Matching preselects only the unique exact title match, or the unique exact first-100-token match with at least 20 tokens, and only when the inventory is complete. Approximate candidates are never preselected. Commit enforces one-to-one pairs and attach-only. Content is unchanged until an explicit or scheduled sync.
- Matching a nested folder finds Docs in every descendant folder across result pages. A scope that hits a limit, an incomplete search, or a listing failure shows explicit warnings and preselects nothing, while manual choices still commit.
- A conversion's Google file ID is in the session record before any Docs or Slides read. Killing the worker or failing any later step leaves the ID for cleanup. A failed trash keeps the ID and its record and is retried until resolved.
- Setup, Sources, Folders, and post-sync pages download no PDF.js code until a PDF panel opens. The worker loads from the plugin's `build/` with no CDN.
- Without `ZipArchive`, DOCX and PPTX uploads are rejected with `docsync_wp_import_zip_unavailable`, while PDF uploads, `/sources/batch`, matching, and `/content` keep working.
- `drive.file` is requested only through opt-in. The continuation returns to the allowlisted page. Expired, mismatched-generation, and foreign continuations fail safely.

## Risks

- **Encrypted storage memory and CPU.** Chunked encryption limits memory. Hosts with low limits fail with a clear error.
- **Slides thumbnail quota.** `getThumbnail` counts as an expensive read, and every slide needs one, both for the navigator and for unsupported-visual fallbacks. This applies whatever `addSlideImages` is set to. Mitigations:
  - at most 200 slides per deck and 20 thumbnails per conversion tick
  - backoff on 429 or 5xx, retried on the next tick
  - a per-file cache keyed by revision and page, so option changes and retries never refetch
  - progress shown through `conversionProgress`

  A large deck takes several ticks to convert. It is never shown with placeholder tiles.
- **Add-content size in existing bundles.** Because `DocSourceModal` imports `AddContentDialog` directly, add-content ships in every bundle that includes the modal (Sources, Setup, Folders, post-sync). PDF.js does not: it is only in the `pdf-renderer` bundle. Phase 1 confirms that the main bundles contain no `pdfjs-dist` code, that the pinned `pdfjs-dist` builds as an IIFE, and that the hashed worker loads with a JavaScript MIME type on the dev container.
- **Large Drive folders.** Recursive inventory is capped (200 Docs, 500 folders, depth 10). Large scopes end truncated with warnings and need manual choices instead of preselection.

Rollback: every change is additive. Remove the `Journey2ServiceProvider` construction from `RestServiceProvider::register()` and the Journey 2 routes, hooks, and services are gone. Committed posts and sources remain ordinary WordPress content.
