# Journey 2 Add Source contracts

Date: 2026-10-10 · Status: authoritative for implementation · Plan: [plan.md](plan.md)

`REST_SCHEMA_CONTRACTS.md` in this folder was rejected. Do not use it. This file replaces it, together with `plan.md`. No other document is an authority for Journey 2.

The six accepted Claude artboards decide all visual details, including Later bulk linking, PPTX, and PDF. This file defines wire shapes, storage, service signatures, wiring, and the invariants the screens rely on. Every field below exists so a screen can show real data. There are no placeholder states.

## 1. Fixed invariants

1. **Existing contracts stay the same.** `POST/GET/PATCH/DELETE /sources*`, `/folders*`, `/drive/*`, `/documents*`, `/oauth/google/*`, source post meta, folder watch storage and behavior, `RestPermissions`, and scheduled sync behavior keep their current requests, responses, and status codes. Every change below adds something. Legacy snake_case query parameters on `/drive/items` remain.
2. **Nothing permanent before commit.** Upload sessions create no posts, drafts, revisions, attachments, or Media Library files. Nothing uses `preview_post_id`. Posts and attachments are created only by the import commit worker, by `/sources/batch`, or by the existing `/sources` routes.
3. **Uploads commit drafts only.** Every post created from an upload is created and left with `post_status = draft`. The upload options have no status field.
4. **Private uploads.** A session holds at most 20 accepted files. Each file is at most `min(25 MiB, wp_max_upload_size())`. A session expires 24 hours after creation and the expiry is never extended. Only the owner can read a session or its assets; other users get 404. Bytes are stored outside the web root, or encrypted inside uploads. If neither works, storage fails closed. An hourly cron removes expired data.
5. **Matching.** A match is preselected only when it is the unique exact normalized-title match, or the unique exact match of the first 100 normalized tokens with at least 20 tokens on both sides, **and** the job's candidate inventory is complete. A folder scope is inventoried recursively (every descendant folder, every result page). When the inventory is truncated, nothing is preselected, because uniqueness cannot be proven; exact and approximate candidates are still listed for manual selection, with explicit truncation warnings. Links are one-to-one. Approximate matches are suggestions for manual selection and are never preselected. Every link made from matching is attach-only and never writes post content.
6. **Attach-only never overwrites immediately.** `syncMode: "attach_only"` saves source meta with status `linked` through `SyncService::attachSource` and does not queue a sync. Post content changes only on a later explicit sync or the normal schedule.
7. **Converters.** PDF is handled locally with `smalot/pdfparser` on the server and PDF.js in the browser. PDF.js is network-lazy: it ships only in the dedicated `pdf-renderer` bundle, which the add-content UI loads only when a PDF preview needs it (section 11). Scanned (no text layer) and encrypted PDFs are rejected. PPTX goes through Google Slides and is always a one-time import. DOCX goes through Google Docs, and **Keep synced** is on by default. Google conversion needs the optional `drive.file` scope. No third-party conversion service is used. DOCX and PPTX also need PHP `ZipArchive`; without it those two formats are rejected per file at upload (`docsync_wp_import_zip_unavailable`), while PDF uploads, Google Docs batches, matching, and every other Journey 2 route keep working.
8. **Initial content equals the preview.** Every upload commit, including DOCX with Keep synced, inserts exactly one draft whose content is `CanonicalRenderer::renderBlocks` output for the same canonical document, options, and resolved preset that produced the accepted `previewFingerprint`. Only the image URLs change, from private asset URLs to the new attachment URLs. The commit never calls `SyncService::createDraftFromSource` or `SyncService::syncPost`, and never queues an initial sync.
9. **Keep synced DOCX.** After the draft is written, the commit calls `SyncService::attachSource` with the real Drive file ID of the converted Google Doc. That call only saves source metadata (status `linked`). Later syncs follow the normal schedule or an explicit Sync.
10. **Provenance.** Every successful upload commit saves `_docsync_wp_import_provenance`: kind `syncedWord` for DOCX with Keep synced, kind `oneTime` for everything else. Provenance is written only after the post content and, for `syncedWord`, the source link succeed. One-time imports never store `_docsync_wp_google_file_id` (no fake Google ID), never get `_docsync_wp_next_sync_at`, and never schedule a sync event. Both kinds count toward activation (section 7.4).
11. **Google temporaries.** Every Google file created by a conversion, including a DOCX with Keep synced, is persisted in the file's `googleTemporaries` immediately after `DriveWriteClient::uploadForConversion` returns its ID, through the converter's `onCreatedFile` callback, before any Docs or Slides read (section 9). It stays there until that file's commit fully succeeds (draft written, source attached, provenance saved). Only then is the retained Keep-synced Doc ID removed from the set. Cancel, expiry, conversion retry, and failed commits trash every ID still in the set. An ID leaves the set only when its trash succeeds or is resolved (Drive 404, already trashed, not app-created, or linked to a post). A failed trash keeps the ID in the set and keeps its session or job record as a retryable cleanup record; the record is never deleted while it still holds an ID. The plugin trashes only files it created: such files carry `appProperties.docsyncWpCreated = "1"`, which is checked with a fresh `files.get` immediately before `files.update {trashed:true}`. A file ID linked to any post (`SourceRepository::findPostIdByGoogleFileId`) is never trashed. The plugin never deletes permanently and never trashes user originals or the import folder itself.
12. **Import folder.** Conversions are created in the app-created folder **My Drive / Imported from WordPress** (`appProperties.docsyncWpFolder = "imports"`). Retained Keep-synced Docs stay there. The folder is found or created by `DriveWriteClient::ensureImportFolder`.
13. **OAuth.** The base scope stays `drive.readonly`. `drive.file` is requested only after the user explicitly opts in. OAuth continuation records are persistent (user meta, not transients), bound to the owner and the OAuth configuration generation, expire after a TTL, and return only to an allowlisted same-site admin page.

## 2. Common wire rules

- Namespace `brasth-document-sync-for-google-docs/v1`. JSON keys are camelCase. Timestamps are ISO-8601 UTC (`2026-10-10T10:47:00Z`).
- Every route uses `permission_callback => [RestPermissions::class, 'canUseAuthenticatedRest']`, which checks the nonce, login, and DocSync access. Object checks run inside the callback.
- Import routes also require `upload_files`. If it is missing they return 403 `docsync_wp_import_forbidden`.
- Errors are standard `WP_Error` JSON: `{code, message, data:{status, ...}}`. All codes start with `docsync_wp_`. When a fix needs Google write access, `data.reconnect = {scopeSet:"driveFile"}`.
- An `idempotencyKey` matches `^[A-Za-z0-9-]{16,64}$`. Replaying a key with the same body returns the stored result. Replaying a key with a different body returns 409 `docsync_wp_idempotency_conflict`. Results are kept for 24 hours.
- ID formats: `sessionId` and `jobId` are UUIDv4 strings; `fileId` is `f_` + 16 hex characters; `assetId` is `a_` + 16 hex characters. Route regexes: `(?P<sessionId>[a-f0-9-]{36})`, `(?P<fileId>f_[a-f0-9]{16})`, `(?P<assetId>a_[a-f0-9]{16})`, `(?P<jobId>[a-f0-9-]{36})`.
- Unknown request fields return 400, following `SourceController::rejectUnknownFields`.

## 3. Import routes (`ImportController`)

| Method | Route | Purpose |
|---|---|---|
| POST | `/imports/sessions` | Create a session |
| GET | `/imports/sessions` | List the caller's open sessions so the UI can resume |
| GET | `/imports/sessions/{sessionId}` | Read one session |
| DELETE | `/imports/sessions/{sessionId}` | Cancel a session and purge its data |
| POST | `/imports/sessions/{sessionId}/files` | Upload files (multipart) |
| PATCH | `/imports/sessions/{sessionId}/files/{fileId}/options` | Update per-file options |
| GET | `/imports/sessions/{sessionId}/files/{fileId}/preview` | Read the canonical document and rendered preview |
| GET | `/imports/sessions/{sessionId}/files/{fileId}/assets/{assetId}` | Stream a private asset |
| POST | `/imports/sessions/{sessionId}/files/{fileId}/assets/{assetId}` | Upload a validated PNG rendered by PDF.js in the browser |
| POST | `/imports/sessions/{sessionId}/commit` | Commit the session; finishes asynchronously |

### 3.1 POST `/imports/sessions`
Body: `{}`. Returns 201 with an `ImportSession`.

| Status | Code | When |
|---|---|---|
| 503 | `docsync_wp_import_storage_unavailable` | Neither storage mode is available |
| 409 | `docsync_wp_import_session_limit` | The caller already has 3 open sessions |

### 3.2 GET `/imports/sessions`
Returns 200 `{sessions: ImportSessionSummary[]}`. Only the caller's sessions with status `open` or `committing` are listed, newest first.

### 3.3 GET `/imports/sessions/{sessionId}`
Returns 200 with an `ImportSession`. The UI polls this route while files convert and while the session commits.

| Status | Code | When |
|---|---|---|
| 404 | `docsync_wp_import_session_not_found` | The session is missing or owned by someone else |
| 410 | `docsync_wp_import_session_expired` | The session has expired |

### 3.4 DELETE `/imports/sessions/{sessionId}`
Returns 200 `{sessionId, status:"cancelled", cleanupPending: boolean}`.

The call deletes the session's private bytes and trashes every ID still listed in any file's `googleTemporaries` (invariant 11). When any trash fails, `cleanupPending` is `true`: the record stays stored as `cancelled` with those IDs, invisible to the session routes (404), and `ImportCleanup` retries it (section 8). If the session is `committing`, it returns 409 `docsync_wp_import_session_committing`.

### 3.5 POST `/imports/sessions/{sessionId}/files`
Request: `multipart/form-data` with field `files[]`, 1 to 20 parts. The session total may not exceed 20 accepted files.

Returns 201:
```json
{"session": ImportSession, "added": ["f_…"], "rejected": [{"originalName": "x.key", "code": "docsync_wp_import_unsupported_type", "message": "…"}]}
```

Accepted files start in status `converting`. Conversion runs on cron hook `docsync_wp_import_convert` with args `[sessionId, fileId]`, and the request spawns cron afterwards. A DOCX or PPTX file uploaded by an owner without the `drive.file` scope gets status `awaitingGoogleWrite` instead and converts after the opt-in continuation (section 6.2).

Per-file rejection codes (reported in `rejected[]`): `docsync_wp_import_unsupported_type`, `docsync_wp_import_file_too_large`, `docsync_wp_import_invalid_file`, `docsync_wp_import_pdf_encrypted`, `docsync_wp_import_macro_file`, `docsync_wp_import_archive_unsafe`, `docsync_wp_import_zip_unavailable`.

`docsync_wp_import_zip_unavailable` is returned for a DOCX or PPTX file when `class_exists( 'ZipArchive' )` is false; its message names the missing PHP `zip` extension. The check is per format, so PDFs in the same request are still accepted. If `ZipArchive` disappears between upload and conversion, the conversion fails that file with the same code.

Request-level errors:

| Status | Code | When |
|---|---|---|
| 409 | `docsync_wp_import_file_limit` | The upload would exceed 20 accepted files |
| 409 | `docsync_wp_import_session_not_open` | The session is not open |

### 3.6 PATCH `/imports/sessions/{sessionId}/files/{fileId}/options`
Body: a partial `ImportFileOptions`. Returns 200 `{file: ImportFile}`.

These changes return the file to `converting` and queue conversion again: `pdf.pages` and `pdf.renderMode`. Every other change, including every `pptx` option, is applied at render time by `CanonicalDocument::applyOptions` and only recomputes `previewFingerprint`. PPTX never reconverts for an option change, because conversion already cached a thumbnail for every slide (section 3.11).

| Status | Code | When |
|---|---|---|
| 400 | `docsync_wp_import_invalid_options` | Options are malformed, or a slide or page number is out of range |
| 403 | `docsync_wp_import_post_type_forbidden` | `SourceRepository::userCanCreateSyncedPost` fails for `target.postType` |
| 403 | `docsync_wp_google_write_scope_required` | `docx.keepSynced` is set without the `drive.file` scope |

### 3.7 GET `/imports/sessions/{sessionId}/files/{fileId}/preview`
Returns 200:
```json
{"fileId":"f_…","previewFingerprint":"sha256…","title":"…","layoutPreset":"clean_article","document": CanonicalDocument,"blockMarkup":"<!-- wp:… -->","html":"<h2>…</h2>","warnings":[Warning],"statistics": Statistics}
```

- `document` is the effective document after `applyOptions`.
- `layoutPreset` is the resolved preset ID (never `''`).
- `blockMarkup` is `CanonicalRenderer::renderBlocks` output. `html` is `do_blocks(blockMarkup)` passed through `wp_kses_post`.
- Image URLs point at the private asset route and include `_wpnonce`.

| Status | Code | When |
|---|---|---|
| 409 | `docsync_wp_import_file_not_ready` | Status is `converting`, `awaitingGoogleWrite`, or `failed` |

A file in `needsRender` can still be previewed. Each pending render appears as an empty figure with `data-docsync-pending-asset`.

### 3.8 GET `/imports/sessions/{sessionId}/files/{fileId}/assets/{assetId}`
Streams the asset bytes. Response headers:
- `Content-Type`: the stored MIME type
- `Content-Length`
- `Cache-Control: private, no-store`
- `X-Content-Type-Options: nosniff`
- `Content-Disposition: inline`

The asset must belong to that file and session, and the caller must own the session. Otherwise the route returns 404 `docsync_wp_import_asset_not_found`. The same route streams a PDF file's `originalAssetId` as `application/pdf`, so PDF.js can draw thumbnails, the page picker, and page renders after a reload or resume.

### 3.9 POST `/imports/sessions/{sessionId}/files/{fileId}/assets/{assetId}`
The body is raw `image/png` (`Content-Type: image/png`) of at most 8 MiB. It is accepted only for an asset with `kind:"pdfPageRender"` and `status:"pendingRender"`.

`UploadValidator::validatePng` checks:
- the PNG signature
- `getimagesizefromstring` reports `IMAGETYPE_PNG`
- width is 200–2400 px and height at most 3400 px
- the aspect ratio is within 2% of the page's `bounds`

The image is then re-encoded with `wp_get_image_editor` to strip metadata and stored in `PrivateAssetStore`.

Returns 200 `{asset: Asset, file: ImportFile}`. When the last pending render arrives, the file moves from `needsRender` to `ready`.

| Status | Code | When |
|---|---|---|
| 400 | `docsync_wp_import_invalid_png` | The PNG fails validation |
| 409 | `docsync_wp_import_asset_not_pending` | The asset is not waiting for a render |

### 3.10 POST `/imports/sessions/{sessionId}/commit`
Body:
```json
{"idempotencyKey":"…","files":[{"fileId":"f_…","previewFingerprint":"sha256…"}]}
```

Only the listed files are committed. Each must have status `ready` and its current fingerprint. Validation runs synchronously, then the session moves to `committing`. The work runs on cron hook `docsync_wp_import_commit` with args `[sessionId]`.

Returns 202 with an `ImportSession`. Replaying the same key returns the current session with 202 while committing and 200 once committed. Files not listed end as `skipped`.

| Status | Code | When |
|---|---|---|
| 409 | `docsync_wp_import_preview_stale` | A fingerprint is stale; `data.files` lists `[{fileId, previewFingerprint}]` |
| 409 | `docsync_wp_import_file_not_ready` | A listed file is not `ready` |
| 409 | `docsync_wp_import_session_not_open` | The session is not open |
| 409 | `docsync_wp_idempotency_conflict` | The key was used with a different body |

The commit worker holds the session lock and handles each file in order. Before step 1 it writes `commitState:{postId:null, attachmentIds:[]}` to the file and updates it after each step, so a retried worker can resume or roll back without creating a second post.

**Every file:**
1. Insert one post with `wp_insert_post`: `post_status = draft`, `post_type = options.target.postType`, `post_title = options.title`, empty content, `post_author` = session owner.
2. Sideload each ready private asset referenced by the effective document with `media_handle_sideload`, attached to the post.
3. Render `CanonicalRenderer::renderBlocks( $document->applyOptions( $options ), $attachment_urls, $resolved_preset )` and save it as post content with `wp_update_post`. The fingerprint is recomputed first; a mismatch fails the file with `docsync_wp_import_preview_stale`.

**DOCX with `keepSynced:true` (kind `syncedWord`):**

4. Call `SyncService::attachSource( $post_id, $user_id, $googleFileId, SyncService::EXPORT_FORMAT_HTML_ZIP, false, $resolved_preset, '' )`. This saves metadata only, with status `linked`. No `markSyncQueued`, no `syncPost`, no cron event from the commit.
5. Save provenance `{kind:"syncedWord", googleFileId, …}`.
6. Remove `googleFileId` from the file's `googleTemporaries`.

**One-time files (PDF, PPTX, and DOCX with `keepSynced:false`, kind `oneTime`):**

4. Save provenance `{kind:"oneTime", …}`.
5. Trash every ID in the file's `googleTemporaries` (invariant 11) and clear the set.

If a step fails, the attachments and the post created by that attempt are deleted with `force`, every ID still in `googleTemporaries` is trashed, and the file becomes `failed` with its error. Other files continue. In every trash step, IDs whose trash fails stay in `googleTemporaries` (invariant 11).

Private bytes are purged when the commit finishes. The session record and its `result` stay readable until `expiresAt`. After `expiresAt`, a record that still holds `googleTemporaries` is kept as a cleanup record until they are resolved.

### 3.11 Import schemas
```ts
type ImportSession = {
  sessionId: string; version: 1;
  status: 'open' | 'committing' | 'committed' | 'cancelled' | 'expired';
  createdAt: string; updatedAt: string; expiresAt: string;           // expiresAt = createdAt + 24h, never extended
  limits: { maxFiles: 20; maxFileBytes: number; remainingFiles: number };   // maxFileBytes = min(25 MiB, wp_max_upload_size())
  storageMode: 'outsideWebroot' | 'encrypted';
  googleWrite: { hasDriveFileScope: boolean; importFolderName: 'Imported from WordPress' };
  files: ImportFile[];
  result: ImportCommitResult | null;
};
type ImportSessionSummary = Pick<ImportSession,'sessionId'|'status'|'createdAt'|'expiresAt'> & { fileCount: number };
type ImportFile = {
  fileId: string; originalName: string; format: 'docx' | 'pptx' | 'pdf';
  byteSize: number; sha256: string;
  status: 'converting' | 'awaitingGoogleWrite' | 'needsRender' | 'ready' | 'failed' | 'committing' | 'committed' | 'skipped';
  error: { code: string; message: string } | null;
  options: ImportFileOptions;
  detection: PptxDetection | null;      // PPTX only
  statistics: Statistics | null; warnings: Warning[];
  pendingRenders: { assetId: string; page: number; widthPt: number; heightPt: number }[];
  previewFingerprint: string | null;   // sha256(effective canonical JSON + normalized options + resolved preset + LayoutConversionService::fingerprintForPreset + RENDERER_VERSION)
  sourceCount: { pages?: number; slides?: number };
  conversionProgress: { step: 'uploading' | 'converting' | 'thumbnails' | 'extracting'; done: number; total: number } | null;   // null unless status is converting
  slideThumbnails: { slide: number; assetId: string; title: string | null; warningNumbers: number[] }[];   // PPTX: every slide, for the navigator; [] for DOCX and PDF
  originalAssetId: string | null;      // PDF only: the private original, streamed as application/pdf by the asset route (3.8) for PDF.js; never part of the canonical document
};
type PptxDetection = {
  hasNotes: boolean;                    // any slide has non-empty speaker notes
  suggestedSkips: { slide: number; reason: 'titleOnly' | 'closing' }[];
};
type ImportFileOptions = {
  title: string;                                     // default: canonical title
  target: { postType: string };                      // uploads always create new draft posts
  layoutPreset: string;                              // '' = site default; Gutenberg presets only
  docx?: { keepSynced: boolean };                    // default true
  pptx?: { slides: number[]; includeNotes: boolean; addSlideImages: boolean; mergeConsecutiveTitles: boolean };
  pdf?:  { pages: number[]; renderMode: 'auto' | 'text' | 'image' };   // 1-based page numbers
};
type ImportCommitResult = {
  idempotencyKey: string; startedAt: string; finishedAt: string | null;
  files: {
    fileId: string; status: 'created' | 'failed' | 'skipped';
    postId: number | null; editUrl: string | null; postStatus: 'draft' | null;
    provenance: 'syncedWord' | 'oneTime' | null;
    googleFileId: string | null;                     // only when provenance = syncedWord; the real retained Doc ID
    googleDocUrl: string | null;                     // only when provenance = syncedWord
    sourceStatus: 'linked' | null;                   // only when provenance = syncedWord
    error: { code: string; message: string } | null;
  }[];
};
```

**PPTX defaults and rules:**
- `slides` defaults to every slide except `detection.suggestedSkips`. The user can add skipped slides back or remove any slide; the stored list is the explicit selection. `[]` is invalid.
- `suggestedSkips`: slide 1 with reason `titleOnly` when it has only title or subtitle placeholders and no other text, table, or image. The last slide with reason `closing` when its normalized text has at most 8 tokens and starts with `thank you`, `thanks`, `q&a`, `q & a`, `questions`, or `any questions`. Every other slide is included.
- `includeNotes` defaults to `detection.hasNotes`. When on, each included slide with notes gets a `notes` section right after its slide section.
- `addSlideImages` defaults to `false`. When on, each included slide gets a `slideThumbnail` image block at the top of its section.
- `mergeConsecutiveTitles` defaults to `false`. When on, a slide whose normalized title equals the previous included slide's title is merged into that section without repeating the heading.
- **Real slide thumbnails.** Conversion fetches one real rendered thumbnail per slide with `SlidesClient::getThumbnail` (`thumbnailProperties.thumbnailSize=MEDIUM`, PNG) for every slide, whatever the options, and stores it privately in `PrivateAssetStore` as an asset with `kind:"slideThumbnail"`. The slide navigator shows these real thumbnails for all slides through the asset route; there are no placeholder tiles. Fetching is bounded: at most 200 slides per deck, at most 20 thumbnails per conversion tick (the convert hook reschedules itself until all are cached, reported in `conversionProgress` with `step:"thumbnails"`), and a 429 or 5xx response retries on the next tick with backoff. Thumbnails are cached per file by `(presentationId, revisionId, pageObjectId)`, so a conversion retry or an option change never fetches the same thumbnail twice. The file stays `converting` until every thumbnail is cached.
- **Unsupported visuals always get a fallback.** A slide with an unsupported element gets a private rendered thumbnail fallback **even when `addSlideImages` is false**. Unsupported elements are charts (`sheetsChart` page elements, and `c:chart` graphic frames in the original slide XML), video (`video` page elements), animations (a `<p:timing>` node in the original `ppt/slides/slideN.xml`, read with `ZipArchive` from the private original because the Slides API does not expose animations), WordArt, and groups that contain any of these or any element that is not text, table, or image. Each occurrence produces one numbered `unsupportedElement` warning (section 4). The effective document then contains, at the position of the first unsupported element on that slide, one `image` block that uses the slide's `slideThumbnail` asset with `fallback.warningNumbers` listing those warnings, and caption `Slide {n} shown as an image: {elements} (warnings {numbers})`. When `addSlideImages` is also on, the slide's top thumbnail block carries the `fallback` data instead, so the slide image never appears twice. Supported text, tables, and images on that slide are still extracted as blocks. Unsupported visuals are never silently dropped or silently rasterized.

**PDF rules:** `pages` defaults to every page. `renderMode: auto` renders a page as an image only when it has low text density or a figure, which produces the warning `pageRenderedAsImage`. `text` never renders pages; `image` renders every selected page.

**DOCX rules:** `keepSynced` defaults to `true`. It requires `drive.file`, as does every DOCX and PPTX conversion.

Limits: at most 200 slides per deck and 300 pages per PDF. Larger files fail with `docsync_wp_import_too_many_pages`.

## 4. Canonical document v1 (`CanonicalDocument`)

```ts
type CanonicalDocument = {
  version: 1; title: string;
  source: { format: 'docx' | 'pptx' | 'pdf'; originalName: string; sha256: string; pages?: number; slides?: number };
  sections: Section[]; assets: Asset[]; warnings: Warning[]; statistics: Statistics;
};
type Section = { id: string; kind: 'body' | 'slide' | 'page' | 'notes'; title: string | null; origin: Origin; blocks: Block[] };
type Block =
  | { type: 'paragraph'; id: string; runs: Run[]; align?: 'left' | 'center' | 'right' | 'justify'; origin: Origin }
  | { type: 'heading'; id: string; level: 1 | 2 | 3 | 4 | 5 | 6; runs: Run[]; origin: Origin }
  | { type: 'list'; id: string; ordered: boolean; items: ListItem[]; origin: Origin }
  | { type: 'table'; id: string; rows: { cells: { runs: Run[]; header: boolean; colSpan: number; rowSpan: number }[] }[]; origin: Origin }
  | { type: 'image'; id: string; assetId: string; alt: string; caption: string | null; origin: Origin;
      fallback?: { warningNumbers: number[] } };                    // PPTX slide-thumbnail fallback for unsupported visuals (section 3.11)
type ListItem = { runs: Run[]; children: ListItem[] };            // max depth 6
type Run = { text: string; bold?: true; italic?: true; underline?: true; strike?: true; code?: true; link?: string };   // link: http(s) only
type Origin = { page?: number; slide?: number; docIndex?: number; bounds?: { x: number; y: number; width: number; height: number } };   // points, top-left origin
type Asset = {
  assetId: string; kind: 'embedded' | 'slideThumbnail' | 'pdfPageRender';
  mimeType: 'image/png' | 'image/jpeg' | 'image/gif' | 'image/webp';
  width: number | null; height: number | null; byteSize: number | null; sha256: string | null;
  status: 'ready' | 'pendingRender' | 'rejected'; origin: Origin;
};
type Warning = { number: number; code: WarningCode; message: string; severity: 'info' | 'warning'; origin?: Origin;
  element?: 'chart' | 'video' | 'animation' | 'wordArt' | 'group' };   // element: unsupportedElement only
// number: 1-based, assigned in document order (section, then block position) by applyOptions over the EFFECTIVE document,
// so the preview, the slide navigator, fallback captions, and the commit result show the same numbers.
type WarningCode = 'unsupportedElement' | 'imageSkipped' | 'pageRenderedAsImage'
  | 'stylingDropped' | 'tableSimplified' | 'linkRemoved' | 'pdfTableAsText' | 'emptySection';
type Statistics = { sections: number; blocks: number; paragraphs: number; headings: number; lists: number;
  tables: number; images: number; words: number; characters: number; assets: number; pendingRenders: number;
  skippedPages: number; skippedSlides: number };
```

How each source format fills the origin:
- **DOCX:** `docIndex` is the Docs API `startIndex`.
- **PPTX:** `slide` is set, and `bounds` comes from the Slides API page element transform (EMU ÷ 12700).
- **PDF:** `page` is set, and `bounds` comes from the smalot text matrix in PDF user space, flipped to a top-left origin.

The stored document is the full conversion (every slide with its notes, every selected PDF page). `CanonicalDocument::applyOptions` derives the effective document for preview and commit: it filters slides, keeps or drops `notes` sections, inserts slide thumbnails when `addSlideImages` is on, always inserts the unsupported-visual fallback thumbnails, merges consecutive titles, numbers the warnings, and recomputes `statistics` (including `skippedSlides` and `skippedPages`). Preview and commit call the same method with the same options, so they produce the same blocks.

The canonical JSON is stored in `PrivateAssetStore` (encrypted, or outside the web root). It is never stored in options or post meta.

## 5. Matching routes (`MatchingController`)

| Method | Route | Purpose |
|---|---|---|
| POST | `/matching/jobs` | Create a bulk-linking job; runs in the background |
| GET | `/matching/jobs/{jobId}` | Read job progress and rows |
| GET | `/matching/jobs/{jobId}/compare?postId=&fileId=` | Side-by-side comparison of one post and one Doc |
| POST | `/matching/jobs/{jobId}/commit` | Attach-only commit of the chosen pairs |
| POST | `/matching/jobs/{jobId}/create-doc` | Create a Google Doc from a post that has no match |

### 5.1 POST `/matching/jobs`
Body:
```json
{"posts":{"postType":"post","postIds":[12,13]},"scope":{"location":"myDrive","folderId":"","driveId":"","fileIds":[]}}
```

- `postIds` is optional, with at most 100 entries. When it is omitted, the server takes the 100 most recently modified posts of `postType` that are unlinked and editable by the caller.
- Every post must be editable by the caller and of an enabled type. Already-linked posts are returned with row state `alreadyLinked`.
- Candidate Docs: the explicit `fileIds` (at most 200), or else the inventory of `scope` (below), capped at 200 Docs.

Returns 202 with a `MatchJob`. The job runs on cron hook `docsync_wp_matching_run` with args `[jobId]`. Each tick does a bounded amount of work, saves the job, reschedules the hook, and spawns cron, until the job is `ready`:

1. **Inventory phase** (`status:"listing"`). At most `MatchingService::LIST_PAGES_PER_TICK` (5) Drive list pages per tick.
   - The job stores a FIFO queue `inventory.queue: {folderId, driveId, pageToken, depth}[]`. A partly listed folder is re-queued with its `nextPageToken`, so every page of every folder is read.
   - Folder scopes (`myDrive`, `sharedDrive`, or any `folderId`) start with one entry: `folderId`, or `root`, or `driveId`. Each page is read with `DriveClient::listDriveItems( $user_id, $folderId, $driveId, '', $pageToken, 50 )`. Docs join the inventory. Child folders are queued with `depth + 1`, so all descendants are covered. Each folder ID is visited once (`inventory.visitedFolderIds`).
   - Flat scopes (`sharedWithMe`, `recent`, `starred`, with an empty `folderId`) are paged through `DriveClient::searchDriveItems( $user_id, ['location'=>…, 'pageToken'=>…, 'pageSize'=>50] )` until `nextPageToken` is empty. Folders returned by `sharedWithMe` and `starred` are queued for recursive listing like any other folder.
   - Limits: 200 candidate Docs (`MAX_CANDIDATE_DOCS`), 500 folders (`MAX_FOLDERS`), depth 10 (`MAX_FOLDER_DEPTH`). Reaching a limit, a Drive `incompleteSearch:true`, or a listing error that still fails after 3 retries on later ticks stops that branch and adds an inventory warning (`docLimitReached`, `folderLimitReached`, `depthLimitReached`, `incompleteSearch`, `listingFailed`). Any warning sets `inventory.complete = false`.
   - With explicit `fileIds`, the inventory is those files, read with `DriveClient::getMetadata`. It is complete only when every listed file is read; each unreadable file adds a `fileUnavailable` warning.
2. **Matching phase** (`status:"running"`). At most 10 Docs per tick (`MATCH_DOCS_PER_TICK`) are fetched with `DocsClient::getDocument`, normalized, and scored. `progress` counts Docs in this phase; `progress.total` is fixed when the inventory phase ends.

| Status | Code | When |
|---|---|---|
| 400 | `docsync_wp_matching_invalid_input` | The body is malformed |
| 403 | `docsync_wp_matching_post_forbidden` | A post is not editable by the caller |
| 409 | `docsync_wp_matching_job_limit` | The caller already has 2 active jobs |

### 5.2 GET `/matching/jobs/{jobId}`
Returns 200 with a `MatchJob`. Returns 404 `docsync_wp_matching_job_not_found` for a missing job or a non-owner, and 410 `docsync_wp_matching_job_expired` after 24 hours.

### 5.3 GET `/matching/jobs/{jobId}/compare`
Returns 200:
```json
{"postId":12,"fileId":"…","compareFingerprint":"sha256…","titleMatch":true,"leadingTokensMatch":false,"score":0.74,
 "post":{"title":"…","wordCount":812,"modifiedAt":"…","excerpt":"…"},
 "doc":{"name":"…","wordCount":790,"modifiedTime":"…","webViewLink":"…","excerpt":"…"},
 "diff":[{"op":"equal","text":"…"},{"op":"delete","text":"…"},{"op":"insert","text":"…"}]}
```

`diff` is a token diff over the first 2000 normalized tokens. `compareFingerprint` is `sha256(postId|fileId|post_modified_gmt|doc version)`.

### 5.4 POST `/matching/jobs/{jobId}/commit`
Body:
```json
{"idempotencyKey":"…","pairs":[{"postId":12,"fileId":"…","compareFingerprint":"sha256…"}]}
```

Pairs must be one-to-one: a repeated `postId` or `fileId` returns 400 `docsync_wp_matching_not_one_to_one`.

A pair whose `fileId` is not the row's preselected exact match needs a current `compareFingerprint`. This includes every manual choice in a job whose inventory is incomplete, where no row is preselected. A missing or stale fingerprint returns 409 `docsync_wp_matching_compare_required`.

Each pair is committed with `SourceBatchService::attachOnly`. No content is written.

Returns 200:
```json
{"jobId":"…","results":[{"postId":12,"fileId":"…","status":"linked"|"failed","error":null|{code,message}}]}
```

Per-pair failures:
- `docsync_wp_post_already_linked`
- `docsync_wp_source_already_linked` (the Doc is linked to another post)
- `docsync_wp_source_syncing`

### 5.5 POST `/matching/jobs/{jobId}/create-doc`
Body: `{"idempotencyKey":"…","postId":12,"folderId":""}`.

The server renders the post content with `do_blocks` and `wp_kses_post`, then creates a Google Doc through `DriveWriteClient::createDocumentFromHtml`. The Doc is named after the post title and placed in `folderId`, or in the import folder (invariant 12) when `folderId` is empty. It is tagged `docsyncWpCreated` and recorded in the job's `googleTemporaries`.

The row becomes `state:"created"`, `matchKind:"created"`, preselected. The new Doc is not linked until commit. A successful commit of that pair removes its ID from `googleTemporaries`.

Returns 201 `{row: MatchRow}`. Returns 403 `docsync_wp_google_write_scope_required` with `data.reconnect` when the scope is missing.

When a job expires, every ID still in its `googleTemporaries` is trashed under invariant 11. IDs whose trash fails stay in the job, and the job record is kept as an `expired` cleanup record that `ImportCleanup` retries.

### 5.6 Matching schemas
```ts
type MatchJob = {
  jobId: string; version: 1; status: 'queued' | 'listing' | 'running' | 'ready' | 'failed' | 'committed' | 'expired';
  createdAt: string; expiresAt: string; progress: { processed: number; total: number };   // matching phase only
  inventory: MatchInventory;
  error: { code: string; message: string } | null;
  rows: MatchRow[];                                                // [] until status is ready
};
type MatchInventory = {
  complete: boolean;                                               // false once any warning is recorded; false while listing
  docsFound: number; foldersVisited: number; foldersQueued: number; pagesFetched: number;
  warnings: { code: 'docLimitReached' | 'folderLimitReached' | 'depthLimitReached' | 'incompleteSearch' | 'listingFailed' | 'fileUnavailable';
    message: string; folderId: string | null; fileId: string | null }[];
};
// Stored only (never on the wire): inventory.queue {folderId,driveId,pageToken,depth,attempts}[], inventory.visitedFolderIds, inventory.fileIds.
type MatchRow = {
  postId: number; postTitle: string; postType: string; editUrl: string;
  state: 'preselected' | 'ambiguous' | 'approximate' | 'none' | 'conflict' | 'created' | 'alreadyLinked';
  selectedFileId: string | null;                                   // set only for preselected/created
  matchKind: 'exactTitle' | 'exactLeadingTokens' | 'created' | null;
  preselectBlocked: 'inventoryIncomplete' | null;                  // an exact match exists but uniqueness is unproven; state is 'ambiguous'
  candidates: { fileId: string; name: string; webViewLink: string; modifiedTime: string;
    kind: 'exactTitle' | 'exactLeadingTokens' | 'approximate'; score: number; linkedPostId: number | null }[];   // max 5
};
```

Matching rules (`MatchNormalizer` and `MatchingService`):
1. **Normalization.** Strip tags and shortcodes, decode entities, apply NFKC (when `intl` is available), lowercase with `mb_strtolower`, replace `\p{P}\p{S}` with spaces, and collapse whitespace. Tokens are split on whitespace. Post text comes from `do_blocks(post_content)`. Doc text comes from the text runs of the `DocsClient::getDocument` body, cached per `(fileId, version)` within the job.
2. **Exact title.** The normalized post title equals the normalized Doc name.
3. **Exact leading tokens.** Both sides have at least 20 tokens, and `implode(' ', array_slice(tokens, 0, 100))` is identical on both sides.
4. **Preselect.** A row is preselected only when `inventory.complete` is `true`, exactly one Doc matches by rule 2, or else by rule 3, and that Doc matches no other post by the same rule. If rules 2 and 3 point at different Docs, or several posts claim the same Doc, the affected rows become `conflict` or `ambiguous` with no selection. A Doc already linked to another post is never preselected. When the inventory is incomplete, a row that would otherwise be preselected becomes `ambiguous` with `preselectBlocked:"inventoryIncomplete"` and `selectedFileId:null`; its exact candidates stay listed so the user can choose one manually, and the UI shows the inventory warnings.
5. **Approximate.** The score is the larger of the `similar_text` percentage of the titles and the token-set Jaccard over the first 300 tokens. Candidates scoring at least 0.5 are listed with `kind:"approximate"`. They are never preselected.

## 6. Google Drive and OAuth additions

### 6.1 GET `/drive/items` (additive)
The legacy parameters `folder_id`, `drive_id`, `search`, `page_token`, and `page_size` behave exactly as before.

New optional camelCase parameters:

| Parameter | Values | Default |
|---|---|---|
| `location` | `myDrive`, `sharedWithMe`, `sharedDrive`, `recent`, `starred` | `myDrive` |
| `globalSearch` | `true` searches every Doc the account can see, ignoring parents (`corpora=allDrives`, `includeItemsFromAllDrives`) | `false` |
| `owner` | `any`, `me`, `others` | `any` |
| `linked` | `any`, `linked`, `unlinked` | `any` |

`sharedDrive` requires `drive_id`. Shared drives are still listed through the existing `/drive/shared-drives`.

When none of the new parameters is present, the request runs through the existing `DriveClient::listDriveItems` path unchanged. Otherwise it uses `DriveClient::searchDriveItems`.

Document items gain these fields:
- `ownedByMe: boolean`
- `ownerDisplayName: string`
- `linked: boolean`
- `linkedPostId: number | null`, non-null only when the caller can edit that post

The `linked` filter is applied per page after the fetch. A page can therefore return fewer items than `page_size` while `nextPageToken` is still present.

### 6.2 OAuth (additive)
- `GET /oauth/google/url` accepts these optional parameters:

  | Parameter | Values | Default |
  |---|---|---|
  | `scopeSet` | `readonly`, `driveFile` | `readonly` |
  | `returnTo` | `sources`, `setup` | — |
  | `resumeKind` | `import`, `matching` | — |
  | `resumeId` | a sessionId or jobId owned by the caller | — |

  With no parameters, the response stays `{authUrl}`. With a continuation, it is `{authUrl, continuationId, expiresAt}`. `driveFile` requests `drive.readonly drive.file` with `include_granted_scopes=true`.
- `GET /oauth/google/account` adds `hasDriveFileScope: boolean` and `driveFileScope: "https://www.googleapis.com/auth/drive.file"`.
- On callback, the continuation is consumed once. The redirect target is `admin.php?page=<allowlisted page>&docsync_resume=<resumeKind>&docsync_resume_id=<id>&docsync_oauth=connected`. When `drive.file` was requested but not granted, the target also carries `&docsync_oauth_scope=drive_file_denied`.
- After a continuation is consumed, the action `docsync_wp_oauth_continuation_consumed` fires with `( int $user_id, array $continuation )`. `ImportService` listens and queues conversion for `awaitingGoogleWrite` files once the scope is present.
- Allowlist: `sources` maps to `brasth-document-sync-for-google-docs-sources`, and `setup` maps to `brasth-document-sync-for-google-docs`. The redirect must pass `wp_validate_redirect` and start with `admin_url()`.
- Expired, foreign-owner, or mismatched-generation continuations fall back to the existing callback redirect with `docsync_oauth=continuation_invalid` and never resume.

## 7. Sources, content, and activation additions (`SourceController`, `ContentController`, `WorkspaceController`)

### 7.1 POST `/sources` (additive)
`syncMode` also accepts `"attach_only"`, but only together with `target.mode:"existing"`. Any other combination returns 400 `docsync_wp_invalid_sync_mode`.

`attach_only` runs the existing-target path: owner-transfer check, then `SyncService::attachSource`, with no queue and no content write. The existing `inline` and `background` values are unchanged.

### 7.2 POST `/sources/batch`
Body:
```json
{"idempotencyKey":"…","items":[
  {"fileId":"…","target":{"mode":"new","postType":"post","postStatus":"draft"},"syncMode":"background","layoutPreset":"","elementorSync":false,"elementorPreset":""},
  {"fileId":"…","target":{"mode":"existing","postId":12},"syncMode":"attach_only","transferOwnership":false}]}
```

`items` holds 1 to 20 entries. `fileId` accepts a URL or a raw ID, parsed by `DocumentIdParser`.

Item rules:
- `mode:new` requires `syncMode:"background"`.
- `mode:existing` requires `syncMode:"attach_only"`. Bulk mode never overwrites immediately.
- `postStatus` is `draft` or `publish`. `publish` requires `SourceRepository::userCanPublishSyncedPost`. (This applies to Google Docs only; uploads are drafts only.)

Returns 200:
```json
{"batchId":"…","results":[{"index":0,"fileId":"…","status":"queued"|"linked"|"failed","postId":34|null,"source":FormattedSource|null,"error":null|{code,message,status}}]}
```

Request-level errors: 400 `docsync_wp_source_batch_invalid` and 400 `docsync_wp_source_batch_duplicate_file`.

Per-item errors:
- `docsync_wp_source_already_linked` (the Doc is linked to another post)
- `docsync_wp_post_already_linked`
- `docsync_wp_source_owner_transfer_required`
- `docsync_wp_source_syncing`
- the existing permission codes

### 7.3 GET `/content` (new `ContentController`, combined listing)
The legacy `GET /sources/{postId}/content` route keeps its current request, response, and 404 exactly; it is not edited. Combined Google and one-time content is served by a new route in `src/Rest/ContentController.php`, registered by `Journey2ServiceProvider`.

Query parameters (camelCase; unknown parameters return 400 `docsync_wp_content_invalid_query`, as do out-of-range values):

| Parameter | Values | Default |
|---|---|---|
| `page` | integer ≥ 1 | `1` |
| `perPage` | integer 1–100 | `20` |
| `kind` | `all`, `google`, `oneTime` | `all` |
| `postType` | an enabled post type the caller can edit; `''` means every such type | `''` |
| `search` | post title substring (`search_columns: ['post_title']`), at most 200 characters | `''` |
| `orderBy` | `modified`, `title`, `date` | `modified` |
| `order` | `asc`, `desc` | `desc` |
| `postId` | integer ≥ 1; returns that one post or `[]`, and every other filter is ignored (the post-sync panel uses this) | — |

Returns 200:
```ts
type ContentListResponse = { items: ContentItem[]; page: number; perPage: number; hasMore: boolean; truncated: boolean };
type ContentItem = {
  postId: number; title: string; postType: string; postStatus: string;
  editUrl: string; viewUrl: string | null; modifiedAt: string;
  provenance: ContentProvenance;
};
type ContentProvenance =
  | { kind: 'google'; googleFileId: string; googleDocUrl: string; source: FormattedSource;   // FormattedSource = SourceRepository::formatSource, unchanged
      importedFrom: { format: 'docx'; originalName: string; importedAt: string } | null }   // set for syncedWord posts
  | { kind: 'oneTime'; format: 'docx' | 'pptx' | 'pdf'; originalName: string;
      converter: 'googleDocsOneTime' | 'googleSlidesOneTime' | 'localPdf'; importedAt: string; importedByUserId: number };
```

Classification rules:
- A post with a non-empty `_docsync_wp_google_file_id` is `kind:'google'`, including `syncedWord` imports and one-time posts later linked with `attach_only`.
- A post without a Google file ID and with `_docsync_wp_import_kind = oneTime` is `kind:'oneTime'`. The UI shows no Sync action for it.
- A `syncedWord` post whose source was later detached is ordinary content and is not listed.
- `/sources` listings never include one-time posts.

Paging and filtering (`ImportProvenanceRepository::listAccessibleContent`):
- Candidates come from `WP_Query` with `fields => 'ids'`, `post_status => 'any'`, the allowed post types, and this meta query: `google` is `_docsync_wp_google_file_id != ''`; `oneTime` is `_docsync_wp_import_kind = 'oneTime'` AND (`_docsync_wp_google_file_id` NOT EXISTS OR `= ''`); `all` is the OR of both. `orderby` maps to `modified`, `title`, or `date` with `ID` as the tie-breaker.
- Candidates are scanned in batches of 100 and each must pass `SourceRepository::userCanSyncPost`, the same per-post authority as the existing source scans. The first `(page - 1) * perPage` accessible posts are skipped, then `perPage` are returned. `hasMore` is true when one more accessible post exists.
- The scan stops after 2000 candidates. When it stops early, `truncated` is `true`.

### 7.4 Activation
`SourceRepository` is not edited. `WorkspaceController::formatSourceSummary()` computes:

```php
'activated' => $summary['activated'] || $folder['imported'] >= 1
  || ( null !== $this->import_provenance && $this->import_provenance->hasAccessibleSuccess( $user_id ) ),
```

`ImportProvenanceRepository::hasAccessibleSuccess( int $user_id ): bool` scans posts in the enabled post types the user can edit that carry `_docsync_wp_import_kind` (`syncedWord` or `oneTime`). It uses batches of 100, ordered by `ID`, and returns `true` at the first post that passes `SourceRepository::userCanSyncPost`, mirroring `SourceRepository::hasAccessibleSource`. Provenance is written only after a successful commit (invariant 10), so a successful import of either kind activates. The scan runs only when the existing terms are false. The `sourceSummary` wire shape is unchanged. `WorkspaceController` gains only the injected setter (section 10).

## 8. Storage

| Data | Location |
|---|---|
| Import session | Option `docsync_wp_import_session_{sessionId}`, `autoload=no`. Index option `docsync_wp_import_session_index` maps `{sessionId: {ownerUserId, expiresAt, cleanupPending, nextCleanupAt}}`. Per file it stores `googleTemporaries: string[]`, `googleFileId`, and `commitState`. A cancelled, expired, or finished session whose files still hold `googleTemporaries` keeps its record (bytes already purged) with `cleanupPending:true`, `cleanupAttempts`, and `nextCleanupAt` (backoff 1 h, doubling, capped at 24 h). It does not count toward `MAX_OPEN_SESSIONS`, and the session routes return 404 for it. It is deleted only once every `googleTemporaries` list is empty. |
| Session lock | Option `docsync_wp_import_lock_{sessionId}` with a 300 s TTL, using the `SyncLock` add_option pattern |
| Private bytes | Outside the web root: the `DOCSYNC_WP_PRIVATE_STORAGE_DIR` constant (absolute, outside `ABSPATH`, writable). Otherwise encrypted in `uploads/docsync-wp-private/<random32hex>/`, in 1 MiB chunks, each sealed with `EncryptionService::encrypt`. The directory also gets `.htaccess` (deny), `web.config`, and `index.php`. If `EncryptionService::isAvailable()` is false, storage is `unavailable` and fails closed. |
| Matching jobs | Option `docsync_wp_match_job_{jobId}`, `autoload=no`, plus index option `docsync_wp_match_job_index`. Each job stores `googleTemporaries` and the full `inventory` state, including the stored-only `queue`, `visitedFolderIds`, and `fileIds` (section 5.6). An expired job that still holds `googleTemporaries` stays as a cleanup record with the same `cleanupPending`/`nextCleanupAt` backoff as sessions. |
| OAuth continuations | User meta `_docsync_wp_oauth_continuations`, keyed by `sha256(continuationId)` and storing `{scopeSet, returnTo, resumeKind, resumeId, generation, createdAt, expiresAt}`. TTL is 3600 s, with at most 5 per user. |
| Import folder | User meta `_docsync_wp_import_folder_id`; verified with `files.get` (`appProperties.docsyncWpFolder = "imports"`, not trashed) before each use, recreated when invalid |
| Batch idempotency | Option `docsync_wp_source_batch_{sha256(userId|key)}`, `autoload=no`, 24 h expiry |
| Provenance | Post meta `_docsync_wp_import_provenance`: `{version:1, kind:"syncedWord"|"oneTime", format, originalName, sha256, converter, googleFileId, importedAt, importedByUserId, sessionId}`. `googleFileId` is the real Doc ID for `syncedWord` and `''` for `oneTime`. `converter` is `googleDocsSynced` for `syncedWord`. A flat queryable post meta `_docsync_wp_import_kind` (`syncedWord` or `oneTime`) is written in the same `save()` call; `/content` and activation query it. |
| Slide thumbnail cache | Per session file: `thumbnailCache: {"<revisionId>:<pageObjectId>": assetId}`; bytes in `PrivateAssetStore`, purged with the session |
| Cron hooks | `docsync_wp_import_convert`, `docsync_wp_import_commit`, `docsync_wp_matching_run`, `docsync_wp_import_cleanup` (hourly) |

Deactivation in the main plugin file clears all four hooks. `uninstall.php` removes the four cron hooks, all options and user meta above, and the private directory. Provenance meta (`_docsync_wp_import_provenance` and `_docsync_wp_import_kind`) is removed only on full uninstall.

## 9. Services: constructors and public methods

Return conventions follow the existing code: `array|WP_Error` and `bool|WP_Error`. Every class is `final`, `declare(strict_types=1)`, and starts with `defined('ABSPATH') || exit;`. Files live in `src/<Namespace>/<Class>.php` under PSR-4 `DocSyncWP\`.

### `DocSyncWP\Import` (`src/Import/`)
```php
final class ImportSessionRepository {
  public const MAX_OPEN_SESSIONS = 3; public const TTL_SECONDS = DAY_IN_SECONDS;
  public function __construct();
  public function create( int $user_id ): array|WP_Error;
  public function get( string $session_id, int $user_id ): array|WP_Error;          // 404 non-owner, 410 expired
  public function getForWorker( string $session_id ): ?array;                     // cron only, no owner check
  public function save( array $session ): bool|WP_Error;
  public function delete( string $session_id ): bool;
  public function listOpenForUser( int $user_id ): array;
  public function listExpired( int $now ): array;                                 // array<int,array{sessionId:string,ownerUserId:int}>; expired and not yet cleaned
  public function listCleanupDue( int $now ): array;                              // array<int,array{sessionId:string,ownerUserId:int}>; cleanupPending and nextCleanupAt <= $now
  public function addGoogleTemporary( string $session_id, string $file_id, string $google_file_id ): bool|WP_Error;   // persists one ID at once; caller already holds the lock
  public function lock( string $session_id ): bool;
  public function unlock( string $session_id ): void;
}
final class PrivateAssetStore {
  public function __construct( EncryptionService $encryption );
  public function storageMode(): string;                                          // outsideWebroot|encrypted|unavailable
  public function isAvailable(): bool;
  public function storeUpload( string $session_id, string $file_id, string $tmp_path ): array|WP_Error;   // {key,byteSize,sha256}
  public function put( string $session_id, string $key, string $bytes ): array|WP_Error;                  // {key,byteSize,sha256}
  public function read( string $session_id, string $key ): string|WP_Error;
  public function materialize( string $session_id, string $key ): string|WP_Error;  // plaintext temp path; caller must release()
  public function release( string $temp_path ): void;
  public function deleteSession( string $session_id ): bool;
  public function purgeOrphans( array $live_session_ids ): int;
}
final class UploadValidator {
  public const MAX_FILES = 20; public const MAX_FILE_BYTES = 26214400; public const MAX_PNG_BYTES = 8388608;
  public function __construct();
  public function limits(): array;                                                // {maxFiles:int,maxFileBytes:int}; maxFileBytes = min(MAX_FILE_BYTES, wp_max_upload_size())
  public function validate( array $uploaded_file, int $accepted_count ): array|WP_Error;   // {format,mimeType,originalName,byteSize,tmpPath}; DOCX/PPTX without ZipArchive => docsync_wp_import_zip_unavailable
  public function zipAvailable(): bool;                                           // class_exists( 'ZipArchive' ); also checked by DocxConverter and DeckConverter before conversion
  public function validatePng( string $bytes, array $expected ): array|WP_Error;  // $expected {widthPt,heightPt}; returns {bytes,width,height}
}
final class CanonicalDocument {
  public const VERSION = 1;
  public static function fromArray( array $data ): self|WP_Error;                 // strict schema validation
  public function toArray(): array;
  public function fingerprint(): string;
  public function getTitle(): string;
  public function getSections(): array;
  public function getAssets(): array;
  public function getWarnings(): array;
  public function getStatistics(): array;
  public function withAsset( array $asset ): self;                                // used when a PDF.js render or slide thumbnail arrives
  public function applyOptions( array $options ): self;                           // effective document for preview and commit (section 4)
  public static function detectPptx( self $document ): array;                     // PptxDetection
}
final class CanonicalRenderer {
  public const RENDERER_VERSION = '1';
  public function __construct( LayoutConversionService $layout );
  public function resolvePreset( string $layout_preset ): string;                 // '' => site default via LayoutConversionService::resolvePresetForSource
  public function renderHtml( CanonicalDocument $document, array $asset_urls ): string;
  public function renderBlocks( CanonicalDocument $document, array $asset_urls, string $layout_preset ): string|WP_Error;   // LayoutConversionService::convert( renderHtml(), resolvePreset() )
  public function previewFingerprint( CanonicalDocument $effective_document, array $options, string $layout_preset ): string;
}
final class DocxConverter {
  public function __construct( DriveWriteClient $drive_write, DocsClient $docs_client, PrivateAssetStore $assets );
  public function convert( int $user_id, string $session_id, array $file, array $options, ?callable $on_created_file = null ): array|WP_Error;
}
final class DeckConverter {
  public const THUMBNAILS_PER_TICK = 20;
  public function __construct( DriveWriteClient $drive_write, SlidesClient $slides, PrivateAssetStore $assets );
  public function convert( int $user_id, string $session_id, array $file, array $options, ?callable $on_created_file = null ): array|WP_Error;
  // Also returns slideThumbnails, thumbnailCache, and complete:bool. complete=false means the thumbnail budget for this tick
  // was used; ImportService saves progress and reschedules CONVERT_HOOK. Unsupported-visual detection reads the private
  // original through PrivateAssetStore::materialize() and ZipArchive (section 3.11).
}
final class PdfConverter {
  public function __construct( PrivateAssetStore $assets );
  public function convert( int $user_id, string $session_id, array $file, array $options ): array|WP_Error;
}
// Every convert() returns array{document:CanonicalDocument, googleFileId:string, googleTemporaries:array<int,string>}.
// DOCX and PPTX: googleTemporaries contains every Google file created for this conversion, INCLUDING googleFileId
// (also for keepSynced DOCX). A retry first trashes the previous conversion's temporaries; IDs whose trash fails stay.
// PDF: googleFileId is '' and googleTemporaries is empty; PdfConverter::convert takes no callback.
//
// $on_created_file: callable( string $google_file_id ): true|WP_Error.
// DocxConverter and DeckConverter call it exactly once per Google file they create, immediately after
// DriveWriteClient::uploadForConversion returns the ID and BEFORE any DocsClient or SlidesClient read. ImportService
// always passes a closure that calls ImportSessionRepository::addGoogleTemporary( $session_id, $file_id, $id ).
// If the callback returns WP_Error, the converter makes no further API call and returns that WP_Error with
// data.googleTemporaries = [ $id ]; ImportService then trashes the ID at once and keeps it in the file record if
// the trash fails. Every later converter error also carries data.googleTemporaries with the IDs created so far,
// so the IDs survive any failure. The returned googleTemporaries is merged idempotently with the persisted set.
final class ImportService {
  public const CONVERT_HOOK = 'docsync_wp_import_convert';
  public function __construct( ImportSessionRepository $sessions, PrivateAssetStore $assets, UploadValidator $validator,
    DocxConverter $docx, DeckConverter $deck, PdfConverter $pdf, CanonicalRenderer $renderer,
    SourceRepository $source_repository, DriveWriteClient $drive_write );
  public function register(): void;                                               // CONVERT_HOOK + docsync_wp_oauth_continuation_consumed
  public function createSession( int $user_id ): array|WP_Error;
  public function listSessions( int $user_id ): array;
  public function getSession( string $session_id, int $user_id ): array|WP_Error;
  public function cancelSession( string $session_id, int $user_id ): array|WP_Error;
  public function addFiles( string $session_id, int $user_id, array $uploaded_files ): array|WP_Error;
  public function runConversion( string $session_id, string $file_id ): void;
  public function updateOptions( string $session_id, string $file_id, int $user_id, array $options ): array|WP_Error;
  public function getPreview( string $session_id, string $file_id, int $user_id ): array|WP_Error;
  public function readAsset( string $session_id, string $file_id, string $asset_id, int $user_id ): array|WP_Error;   // {bytes,mimeType}
  public function storeRenderedAsset( string $session_id, string $file_id, string $asset_id, int $user_id, string $png_bytes ): array|WP_Error;
  public function trashTemporaries( int $user_id, array $file_ids ): array;      // $file_ids are Google file IDs. Returns the IDs whose trash failed; callers keep exactly
                                                                                  // those in googleTemporaries. Resolved IDs (trashed, Drive 404, already trashed, not
                                                                                  // app-created, linked via findPostIdByGoogleFileId) are not returned. Linked IDs are never trashed.
  public function formatSession( array $session ): array;
  public function onContinuationConsumed( int $user_id, array $continuation ): void;
}
final class ImportCommitter {
  public const COMMIT_HOOK = 'docsync_wp_import_commit';
  public function __construct( ImportSessionRepository $sessions, PrivateAssetStore $assets, CanonicalRenderer $renderer,
    ImportProvenanceRepository $provenance, SourceRepository $source_repository, SyncService $sync_service,
    ImportService $imports );
  public function register(): void;
  public function commit( string $session_id, int $user_id, array $files, string $idempotency_key ): array|WP_Error;   // returns formatted session
  public function runCommit( string $session_id ): void;
}
final class ImportProvenanceRepository {
  public const META_KEY = '_docsync_wp_import_provenance'; public const KIND_META_KEY = '_docsync_wp_import_kind';
  public const SCAN_BATCH_SIZE = 100; public const CONTENT_SCAN_LIMIT = 2000;
  public function __construct( SourceRepository $source_repository );            // existing public methods only: getEnabledPostTypes, userCanEditPostType, userCanSyncPost, formatSource, META_FILE_ID
  public function save( int $post_id, array $provenance ): bool|WP_Error;         // writes META_KEY and KIND_META_KEY
  public function get( int $post_id ): ?array;
  public function formatOneTime( int $post_id ): ?array;                          // wire provenance kind oneTime, null for syncedWord or missing
  public function formatImportedFrom( int $post_id ): ?array;                     // {format,originalName,importedAt} for syncedWord, else null
  public function hasAccessibleSuccess( int $user_id ): bool;                     // section 7.4; called by WorkspaceController
  public function listAccessibleContent( int $user_id, array $query ): array;     // section 7.3; {postIds:int[],page,perPage,hasMore,truncated}; $query is the validated camelCase query
  public function formatContentItem( int $post_id ): ?array;                      // ContentItem, null when the post is neither kind
  public function delete( int $post_id ): bool;                                   // removes both meta keys
}
final class ImportCleanup {
  public const HOOK = 'docsync_wp_import_cleanup';
  public function __construct( ImportSessionRepository $sessions, PrivateAssetStore $assets, ImportService $imports,
    MatchSessionRepository $match_jobs, MatchingService $matching, OAuthContinuationStore $continuations,
    SourceBatchService $source_batch );
  public function register(): void;                                               // init: schedule hourly; HOOK => run
  public function run(): void;                                                    // expired sessions + jobs, listCleanupDue retries for both, orphans, continuations, batch keys
  public function cleanupSession( array $session ): void;                         // deletes bytes, trashes remaining googleTemporaries; deletes the record only when none remain,
                                                                                  // otherwise saves it with cleanupPending and the next backoff time
  public static function unschedule(): void;
}
```

`ImportCommitter` trashes through `ImportService::trashTemporaries`, so only one class talks to `DriveWriteClient::trashAppCreatedFile` for uploads.

### `DocSyncWP\Matching` (`src/Matching/`)
```php
final class MatchNormalizer {
  public const LEADING_TOKENS = 100; public const MIN_TOKENS = 20; public const APPROXIMATE_MIN_SCORE = 0.5;
  public function __construct();
  public function normalizeTitle( string $title ): string;
  public function normalizeText( string $html_or_text ): string;
  public function tokens( string $html_or_text ): array;
  public function leadingKey( array $tokens ): ?string;                           // null when < MIN_TOKENS
  public function docsText( array $docs_api_document ): string;
  public function approximateScore( string $title_a, string $title_b, array $tokens_a, array $tokens_b ): float;
  public function tokenDiff( array $tokens_a, array $tokens_b, int $limit = 2000 ): array;
}
final class MatchSessionRepository {
  public const TTL_SECONDS = DAY_IN_SECONDS; public const MAX_ACTIVE_JOBS = 2;
  public function __construct();
  public function create( int $user_id, array $input ): array|WP_Error;
  public function get( string $job_id, int $user_id ): array|WP_Error;
  public function getForWorker( string $job_id ): ?array;
  public function save( array $job ): bool|WP_Error;
  public function delete( string $job_id ): bool;
  public function listExpired( int $now ): array;                                 // array<int,array{jobId:string,ownerUserId:int}>; expired and not yet cleaned
  public function listCleanupDue( int $now ): array;                              // array<int,array{jobId:string,ownerUserId:int}>; cleanupPending and nextCleanupAt <= $now
  public function lock( string $job_id ): bool;
  public function unlock( string $job_id ): void;
}
final class MatchingService {
  public const RUN_HOOK = 'docsync_wp_matching_run';
  public const LIST_PAGES_PER_TICK = 5; public const MATCH_DOCS_PER_TICK = 10; public const LIST_RETRY_LIMIT = 3;
  public const MAX_CANDIDATE_DOCS = 200; public const MAX_FOLDERS = 500; public const MAX_FOLDER_DEPTH = 10;
  public function __construct( MatchSessionRepository $jobs, MatchNormalizer $normalizer, DriveClient $drive_client,
    DocsClient $docs_client, DriveWriteClient $drive_write, SourceRepository $source_repository, SourceBatchService $source_batch );
  public function register(): void;
  public function createJob( int $user_id, array $input ): array|WP_Error;
  public function getJob( string $job_id, int $user_id ): array|WP_Error;
  public function runJob( string $job_id ): void;                                 // one bounded tick: inventory (listing) or matching (running); reschedules until ready
  public function compare( string $job_id, int $user_id, int $post_id, string $file_id ): array|WP_Error;
  public function commit( string $job_id, int $user_id, array $pairs, string $idempotency_key ): array|WP_Error;
  public function createDoc( string $job_id, int $user_id, int $post_id, string $folder_id, string $idempotency_key ): array|WP_Error;
  public function expireJob( array $job ): void;                                  // trashes remaining googleTemporaries; deletes the record only when none remain,
                                                                                  // otherwise saves it as expired with cleanupPending and the next backoff time
  public function formatJob( array $job ): array;
}
```

### `DocSyncWP\Google` (`src/Google/`)
```php
final class DriveWriteClient {
  public const GOOGLE_SLIDES_MIME_TYPE = 'application/vnd.google-apps.presentation';
  public const IMPORT_FOLDER_NAME = 'Imported from WordPress';
  public function __construct( GoogleOAuthService $oauth );
  public function hasWriteScope( int $user_id ): bool;                            // GoogleOAuthService::userHasDriveFileScope
  public function ensureImportFolder( int $user_id ): string|WP_Error;           // My Drive / Imported from WordPress; app-created, tagged docsyncWpFolder=imports
  public function uploadForConversion( int $user_id, string $file_path, string $source_mime, string $target_mime, string $name, string $session_id ): array|WP_Error;   // in the import folder; tagged docsyncWpCreated=1; {fileId,name,mimeType,webViewLink,version}
  public function createDocumentFromHtml( int $user_id, string $html, string $name, string $parent_folder_id = '' ): array|WP_Error;   // '' => import folder
  public function trashAppCreatedFile( int $user_id, string $file_id ): bool|WP_Error;   // fresh files.get; requires docsyncWpCreated=1; never deletes permanently
}
final class SlidesClient {
  public function __construct( GoogleOAuthService $oauth );
  public function getPresentation( int $user_id, string $presentation_id ): array|WP_Error;
  public function getThumbnail( int $user_id, string $presentation_id, string $page_object_id ): array|WP_Error;   // {bytes,mimeType:'image/png',width,height}
  public function downloadContentUrl( int $user_id, string $content_url ): array|WP_Error;   // {bytes,mimeType}; image MIME + 10 MiB cap
}
// DriveClient (existing) adds; existing signatures unchanged:
public function searchDriveItems( int $user_id, array $query ): array|WP_Error;
// $query {location,folderId,driveId,search,globalSearch,owner,pageToken,pageSize}; same response shape as listDriveItems plus ownedByMe/ownerDisplayName
```

### `DocSyncWP\Auth` (`src/Auth/`)
```php
final class OAuthContinuationStore {
  public const META_KEY = '_docsync_wp_oauth_continuations'; public const TTL_SECONDS = HOUR_IN_SECONDS; public const MAX_PER_USER = 5;
  public function __construct( SettingsRepository $settings );                    // uses getOAuthConfigurationGeneration()
  public function create( int $user_id, string $scope_set, string $return_to, string $resume_kind, string $resume_id ): array|WP_Error;   // {continuationId,expiresAt}
  public function consume( string $continuation_id, int $user_id ): array|WP_Error;   // {returnUrl,scopeSet,resumeKind,resumeId}; single use
  public function purgeExpired(): int;
  public function deleteForUser( int $user_id ): void;
}
// GoogleOAuthService (existing) adds; existing signatures unchanged:
public const DRIVE_FILE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
public function __construct( SettingsRepository $settings, TokenStore $token_store, ?OAuthContinuationStore $continuations = null );   // defaults to new OAuthContinuationStore( $settings )
public function createContinuationAuthorization( int $user_id, string $scope_set, string $return_to, string $resume_kind, string $resume_id ): array|WP_Error;   // {authUrl,continuationId,expiresAt}
public static function hasDriveFileScope( string $scope ): bool;
public function userHasDriveFileScope( int $user_id ): bool;                      // reads the stored token scope
// handleCallback() reads continuation_id from state and returns the continuation URL. buildFailureRedirect() honors the same allowlist.
```

### `DocSyncWP\Sync` (`src/Sync/`)
```php
final class SourceBatchService {
  public const MAX_ITEMS = 20;
  public function __construct( SourceRepository $source_repository, SyncService $sync_service, DocumentIdParser $document_id_parser,
    LayoutPresetRegistry $layout_presets, ElementorPresetRegistry $elementor_presets );
  public function createBatch( int $user_id, array $items, string $idempotency_key ): array|WP_Error;   // {batchId,results}
  public function attachOnly( int $user_id, int $post_id, string $file_id, array $options = array() ): array|WP_Error;
  public function purgeExpired(): int;
}
// SourceRepository (existing) is NOT edited: no new methods, all signatures, constants, and wire unchanged.
```

`attachOnly` validates that the post is editable and has no link, that the file is not linked elsewhere, and handles the owner transfer. It then calls `SyncService::attachSource` and never `markSyncQueued`.

The `background` items in `createBatch` call `SyncService::createDraftFromSource(..., false, ...)` and `SyncService::markSyncQueued`, then `SyncCron::scheduleSourceSync($post_id, $user_id, false)`. `SyncCron::spawnScheduledSyncs()` runs once at the end. This is the Google Docs path only; upload commits never use it.

### `DocSyncWP\Rest` (`src/Rest/`)
```php
final class ImportController {
  public function __construct( ImportService $imports, ImportCommitter $committer );
  public function registerRoutes( string $rest_namespace ): void;
  public function listSessions( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function createSession( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function getSession( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function deleteSession( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function addFiles( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function updateFileOptions( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function getFilePreview( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function getFileAsset( WP_REST_Request $request ): WP_REST_Response|WP_Error;   // streams via rest_pre_serve_request
  public function putFileAsset( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function commitSession( WP_REST_Request $request ): WP_REST_Response|WP_Error;
}
final class MatchingController {
  public function __construct( MatchingService $matching );
  public function registerRoutes( string $rest_namespace ): void;
  public function createJob( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function getJob( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function compare( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function commit( WP_REST_Request $request ): WP_REST_Response|WP_Error;
  public function createDoc( WP_REST_Request $request ): WP_REST_Response|WP_Error;
}
final class ContentController {
  public function __construct( SourceRepository $source_repository, ImportProvenanceRepository $provenance );
  public function registerRoutes( string $rest_namespace ): void;                 // GET /content
  public function listContent( WP_REST_Request $request ): WP_REST_Response|WP_Error;   // section 7.3
}
// Existing controllers add only these (constructors and every other signature unchanged):
public function getDependencies(): array;   // SourceController, DocumentController, OAuthController; maps in section 10
SourceController::setSourceBatch( SourceBatchService $source_batch ): void;          // provider-injected; /sources/batch returns 503 docsync_wp_journey2_unavailable until set
DocumentController::setSourceRepository( SourceRepository $source_repository ): void;   // for the linked filter
WorkspaceController::setImportProvenance( ImportProvenanceRepository $provenance ): void;   // provider-injected; activation term (7.4) is skipped until set
```

`SourceController` never names or constructs a Journey 2 class in its constructor or defaults, so it loads unchanged even when the Journey 2 classes are absent. It holds no provenance dependency: provenance reads moved to `ContentController`, and its only new collaborator is the injected `SourceBatchService`. `POST /sources` with `syncMode:"attach_only"` uses only its existing `SyncService` and `SourceRepository`.

### `DocSyncWP` (`src/Journey2ServiceProvider.php`)
```php
namespace DocSyncWP;
final class Journey2ServiceProvider {
  public function __construct( array $dependencies );   // merged map from RestServiceProvider::getDependencies(); builds every Journey 2 service once
  public function isReady(): bool;                      // false when a required dependency is missing or of the wrong class, or Smalot\PdfParser\Parser is absent.
                                                        // ZipArchive is NOT checked here: its absence only rejects DOCX/PPTX uploads (UploadValidator, invariant 7)
  public function register(): void;                     // no-op unless ready; rest_api_init => registerRoutes; ImportService/ImportCommitter/MatchingService/ImportCleanup ->register()
  public function registerRoutes(): void;               // ImportController, MatchingController, ContentController with RestServiceProvider::NAMESPACE
  public function getSourceBatch(): ?SourceBatchService;               // null unless ready
  public function getImportProvenance(): ?ImportProvenanceRepository;  // null unless ready
}
```

## 10. Wiring (no `Plugin.php` edits)

`Plugin::boot()` already builds every shared service and passes them into the controllers it gives `RestServiceProvider`. Journey 2 reads those instances back through getters instead of changing `Plugin`. Only `SourceController`, `DocumentController`, and `OAuthController` gain getters. `SourceController`, `DocumentController`, and `WorkspaceController` each gain one provider-injected setter (section 9); their constructors are unchanged. `WorkspaceController` is part of this integration only through that setter and the one activation term in section 7.4. `SettingsController`, `FolderWatchController`, `SourceRepository`, and the other controllers and services are not edited.

1. Getters. Each returns an array keyed by these names, holding the controller's own injected instances. A getter never constructs anything:

   | Controller | `getDependencies()` keys |
   |---|---|
   | `SourceController` | `sourceRepository`, `syncService`, `documentIdParser`, `layoutPresets`, `elementorPresets` |
   | `DocumentController` | `documentIdParser`, `driveClient` |
   | `OAuthController` | `googleOAuth`, `tokenStore` |

2. `RestServiceProvider` (`src/Rest/RestServiceProvider.php`) gains `public function getDependencies(): array`, which merges the three maps. `register()`:
   - keeps `add_action('rest_api_init', [$this,'registerRoutes'])` and the existing route registration;
   - calls `$this->document_controller->setSourceRepository( $deps['sourceRepository'] )`;
   - when `class_exists( \DocSyncWP\Journey2ServiceProvider::class )`, creates `$journey2 = new \DocSyncWP\Journey2ServiceProvider( $deps )` and calls `$journey2->register()`;
   - only when `$journey2->isReady()`, calls `$this->source_controller->setSourceBatch( $journey2->getSourceBatch() )` and `$this->workspace_controller->setImportProvenance( $journey2->getImportProvenance() )`.

   These setters run synchronously in `register()` at plugin load, before `rest_api_init`, so every request sees them. When Journey 2 is not ready, the legacy routes behave exactly as they do today: `/sources/batch` returns 503 `docsync_wp_journey2_unavailable`, the activation term is skipped, and no Journey 2 route is registered. `Plugin::register()` already calls `$this->rest->register()`, so nothing in `Plugin.php` changes.
3. Until `DocumentController::setSourceRepository()` runs, a `linked` filter other than `any` returns 400 `docsync_wp_linked_filter_unavailable`.
4. `Journey2ServiceProvider` builds every Journey 2 object exactly once from those instances. Services not exposed by the three getters are stateless wrappers over options, constants, and the injected Google client. New instances built with the same arguments therefore behave exactly like the ones in `Plugin::boot()`:
   - `$encryption = new EncryptionService()` (key material comes from constants and salts)
   - `$settings = new SettingsRepository( $encryption, $deps['layoutPresets'], $deps['elementorPresets'] )`. This is the same construction and the same registries as `Plugin::boot()`. It reads option `docsync_wp_settings` on every call and holds no cached state.
   - `new DocsClient( $googleOAuth )`, `new SlidesClient( $googleOAuth )`, `new DriveWriteClient( $googleOAuth )`
   - `new LayoutConversionService( $settings, new HtmlToBlockContentConverter(), $deps['layoutPresets'] )`. This is the same construction as `Plugin::boot()`, so preview, commit, and the sync pipeline resolve presets identically.
   - `new OAuthContinuationStore( $settings )`, backed by the same user meta as the instance inside `GoogleOAuthService`
   - `$sourceBatch = new SourceBatchService( $deps['sourceRepository'], $deps['syncService'], $deps['documentIdParser'], $deps['layoutPresets'], $deps['elementorPresets'] )`
   - `$importProvenance = new ImportProvenanceRepository( $deps['sourceRepository'] )`
   - `new ContentController( $deps['sourceRepository'], $importProvenance )`
   - every Import and Matching class, reusing `sourceRepository`, `syncService`, `driveClient`, `$sourceBatch`, and `$importProvenance`. `getSourceBatch()` and `getImportProvenance()` return these same objects.
5. `GoogleOAuthService` gets its continuation store from the new optional constructor default, so `Plugin::boot()` still calls `new GoogleOAuthService( $settings, $token_store )` unchanged.
6. The main plugin file (`brasth-document-sync-for-google-docs.php`) changes only in `docsync_wp_deactivate()`. It calls `ImportCleanup::unschedule()` and clears the convert, commit, and matching hooks, guarded by `class_exists`.

## 11. Frontend placement

- No `add-source` folder and no new file under `resources/js/admin/entries/`. The UI lives in `resources/js/admin/features/add-content/`. Its root component is `add-content-dialog.tsx`, which exports `AddContentDialog`.
- The existing `DocSourceModal` (`resources/js/admin/features/doc-source-modal/doc-source-modal.tsx`) imports `AddContentDialog` directly with a static import and renders it for the Journey 2 views. It opens from Sources and the Setup first-source step. Because it is a direct import, it ships in every existing bundle that already includes `DocSourceModal` (Sources, Setup, Folders, and post-sync). `resources/js/admin/entries/doc-source-modal-entry.ts` is not changed: it gets no global export and there is no `DocSyncWPDocSourceModalBundle`.
- Styles go in `resources/css/components/journey-add-content.css`, imported by the existing `resources/css/doc-source-modal-entry.css`, which is already loaded wherever `DocSourceModal` renders.
- URL-backed `view`, `session`, and `job` state lets an OAuth resume (section 6.2) land on the same screen.
- The PPTX slide navigator shows `ImportFile.slideThumbnails` (real Slides thumbnails for every slide) with each slide's warning numbers.
- Typed API clients in `resources/js/admin/api/` mirror sections 3 to 7 exactly.

### 11.1 Network-lazy PDF renderer

PDF.js never ships in the Setup, Sources, Folders, post-sync, or doc-source-modal bundles. It is downloaded only when a PDF thumbnail, page picker, or page render is about to be shown.

- **Build.** One new Vite build mode, `pdf-renderer`, is the only new mode. Its entry is the add-content module `resources/js/admin/features/add-content/pdf-renderer.ts` (`entryByMode['pdf-renderer']`, `entryNameByMode['pdf-renderer'] = 'pdfRenderer'`, manifest `manifest.pdf-renderer.json`, IIFE like the other modes, with no WordPress externals used). The build integration owns the edits to `vite.config.ts`, the `package.json` build script, and `AssetRegistry`.
- **Worker.** `pdf-renderer.ts` imports the worker as `pdfjs-dist/build/pdf.worker.min.mjs?url`. The `pdf-renderer` mode emits it as a hashed local `.js` asset under `build/assets/js/`, so hosts serve it with a JavaScript MIME type. At load time the renderer sets `GlobalWorkerOptions.workerSrc` to that path resolved against `window.DocSyncWPAdmin.pluginUrl + 'build/'`. There is no CDN.
- **Config.** `AssetRegistry::adminConfig()` adds one key, `pdfRendererScriptUrl: string`: `pluginUrl + 'build/' + manifest.pdf-renderer.json[pdfRenderer].file`, or `''` when that build is missing. It is not enqueued on any page.
- **Global.** The renderer bundle assigns exactly one global:
  ```ts
  declare global { interface Window { DocSyncWPPdfRenderer?: DocSyncWPPdfRenderer } }
  type DocSyncWPPdfRenderer = {
    version: 1;
    open( source: { url: string } ): Promise<PdfRendererDocument>;   // url: asset route for originalAssetId (section 3.8), with _wpnonce; fetched same-origin with credentials
  };
  type PdfRendererDocument = {
    pageCount: number;
    getPageSize( page: number ): Promise<{ widthPt: number; heightPt: number }>;            // 1-based page
    renderThumbnail( page: number, canvas: HTMLCanvasElement, maxWidthPx: number ): Promise<void>;
    renderPagePng( page: number, widthPx: number ): Promise<Blob>;                         // image/png, widthPx 200–2400; body for POST assets/{assetId} (section 3.9)
    destroy(): Promise<void>;
  };
  ```
- **Loader.** `resources/js/admin/features/add-content/pdf-renderer-loader.ts` exports `loadPdfRenderer( scriptUrl: string ): Promise<DocSyncWPPdfRenderer>`. It appends one `<script src={scriptUrl}>` on first call, caches the promise, and resolves when `window.DocSyncWPPdfRenderer?.version === 1`. It rejects when `scriptUrl` is `''`, the script fails, or the global is missing; a failed promise is dropped so Retry injects again. The PDF components call it with `window.DocSyncWPAdmin.pdfRendererScriptUrl` only when they mount for a PDF file. Main-bundle modules use only `import type` from `pdf-renderer.ts` and never import `pdfjs-dist`.
- **Failure.** A rejected load shows an inline error with Retry in the PDF panel. Server-side PDF conversion and the other formats are unaffected.

## 12. Dependencies
- Composer: `smalot/pdfparser` ^2 (runtime `require`). Text and font matrices come from `Page::getDataTm()`.
- PHP: the `zip` extension (`ZipArchive`) for DOCX and PPTX archive validation and for PPTX animation and chart detection in the original slide XML. It is checked per format at runtime (`UploadValidator::zipAvailable`); without it only DOCX and PPTX are rejected (`docsync_wp_import_zip_unavailable`). `Journey2ServiceProvider::isReady()` does not require it.
- pnpm: `pdfjs-dist` for thumbnails, the page picker, and page renders, pinned to a version that compiles into the `pdf-renderer` IIFE bundle only (section 11.1).
- Google APIs: Drive v3 (`files.create` with conversion, `files.list` for the import folder, `files.get` appProperties, `files.update` trashed), Docs v1 `documents.get`, Slides v1 `presentations.get` and `pages.getThumbnail` (one MEDIUM PNG per slide, bounded and cached as in section 3.11). Reads are covered by `drive.readonly`; create, folder, and trash calls need `drive.file`.
