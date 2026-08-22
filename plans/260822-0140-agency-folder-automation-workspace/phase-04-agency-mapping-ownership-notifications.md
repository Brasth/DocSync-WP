# Phase 4 — Agency mapping, ownership transfer, failure digests

## Objective

Multi-client ergonomics: imports land organized (taxonomy/author), client folders survive staff churn (transfer), and agencies hear about failures without opening wp-admin (digest). Kills gaps #6 and #8.

## 1. Folder → content mapping

- New watch fields (CPT meta, editable in Phase 1 drawer):
  - `defaultCategoryId` (int, `category` taxonomy when target supports it; empty = site default behavior)
  - `defaultTagIds` (int[])
  - `defaultAuthorId` (int; validated `user_can(edit_posts)` for target type; empty = watch owner as today)
  - `subfolderCategoryMode`: `off` (default) | `child_categories` — when on, each first-level subfolder maps to a child category of `defaultCategoryId`, created on demand (`wp_insert_term` with parent), cached on the watch as `subfolderTermMap`
- Applied in `FolderWatchRunner::importFile` after `createDraftFromSource`: `wp_set_post_terms`, `wp_update_post( post_author )`. Mapping applies to **new imports only**; re-syncs never touch taxonomy/author (content-only contract preserved).
- Custom post types without `category`/`post_tag`: UI hides unsupported mapping fields based on `get_object_taxonomies`.

## 2. Watch ownership transfer

- `POST /folders/(?P<id>[a-z0-9-]+)/transfer` body `{ newOwnerUserId }`, admin-only (`manage_options`) or current owner.
- Validation: new owner has a connected Google token (`TokenStore`), can use the watch's post type (`RestPermissions::userCanUsePostType`), and can access the watched folder (probe `DriveClient::getDriveItem` with new owner's token; fail with actionable message when Drive says 404/403).
- Effect: watch `ownerUserId` swaps; optionally (checkbox, default on) member sources' `sync_owner_user_id` transfer too — reuse the existing source-transfer confirmation semantics ("changes scheduled-sync responsibility without removing content"). Changes token (`changesPageToken`) re-seeds from the new owner's feed.
- UI: "Transfer ownership" in the watch detail drawer, Radix confirm listing consequences; visible to admins always, to owners when another connected user exists.
- Watch in `error` from owner token loss: banner on Drive Folders row offering transfer as the recovery path.

## 3. Failure digest notifications

- New `src/Notifications/DigestService.php` + daily cron `docsync_wp_daily_digest` (registered like `TelemetryCron`).
- Digest collects last 24h per recipient: hard-failed watches (status `error` + `lastError`), per-file import failures count per watch, sources newly `orphaned`, cron-stall flag (from Phase 1 heartbeat). Skips sending when everything is healthy — no noise.
- Recipients: each watch owner gets their watches' items; admins get site-wide summary. One email per user (`wp_mail`), plain branded template, deep links to Drive Folders / Sync Activity.
- Settings (Setup → Sync defaults): `Email failure digest` toggle, default **on**; per-user opt-out link honored via user meta.
- No external services; `wp_mail` only. Slack/webhooks explicitly out of scope this phase.

## 4. Docs and polish

- README + user-guide sections: Drive Folders screen, per-folder schedules, mapping, transfer, digest.
- `docs/system-architecture.md` + `docs/codebase-summary.md` refresh (folder-watch CPT, changes-feed scan, scheduler).
- readme.txt feature bullets + changelog entries.

## Acceptance

- Watch with category "Client A" + `child_categories`: Doc in subfolder "Blog" imports into `Client A > Blog` (term auto-created once, reused after).
- Transfer to user without Drive access to the folder → 400 with clear message; with access → scans/imports continue under new token, sources transferred when opted.
- Owner disconnects Google → watch errors → digest email next morning names the watch and links recovery; transfer restores operation.
- Healthy site sends zero digest emails.

## Verification

- `scripts/verify-digest-collector.php` (composer `test:digest-collector`): collection matrix (failed watch, file failures, orphaned, stall, healthy-silent).
- Mapping: extend folder-watch fixtures to assert terms/author on imported drafts.
- Full suite: `composer lint`, all `composer test:*`, `pnpm typecheck && pnpm lint && pnpm build`, devcontainer manual pass (MailHog or `wp_mail` logger for digest).

## Risks

- Term auto-creation on CPTs with custom taxonomies — explicitly limited to `category`/`post_tag`-supporting targets this phase.
- Digest fatigue → send-only-on-issues rule plus per-user opt-out.
- Transfer with revoked-but-cached tokens: probe call surfaces real Drive state before committing.
