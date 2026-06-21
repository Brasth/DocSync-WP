# WordPress.org Screenshots Scaffolding

Status: plumbing in place, real screenshots pending capture.

## Context

The 1.0.3 release hardening plan listed WordPress.org listing screenshots as out of scope. The plugin is otherwise WordPress.org ready, but the directory page on `wordpress.org/plugins/brasth-document-sync-for-google-docs/` shows no preview images.

The official spec (https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) requires:

- `assets/screenshot-N.png` files in the SVN repository `assets/` directory (NOT in `trunk/`).
- A `== Screenshots ==` section in `readme.txt` whose numbered list maps 1:1 to the screenshot files.
- PNG or JPG, max 10 MB per file, lowercase filenames.

## What this change ships

- A `== Screenshots ==` section in `readme.txt` with 5 numbered captions for the highest-signal screens.
- 5 placeholder PNGs (`assets/screenshot-1.png` ... `screenshot-5.png`) so the slots exist in git and the eventual `svn add assets/screenshot-N.png` is wired. Placeholders are visually empty 1x1 transparent PNGs and must be replaced before any SVN upload.
- A Node lint script (`scripts/lint-screenshots.mjs`) that fails CI if the readme list and asset files drift.
- CI wiring in `.github/workflows/pr-lint.yml` so `pnpm lint:screenshots` runs on every PR.

## Capture runbook (deferred)

The actual capture step is intentionally not part of this change. When capture is ready:

1. Pick a capture method (live WP + real Google account, local WP + stubbed REST responses, or static mockups).
2. For each numbered slot below, take a 1200x900 PNG screenshot in the WordPress admin while signed in as an administrator. Crop out any personal Google account email or document title before saving.
3. Replace the matching `assets/screenshot-N.png` placeholder with the real image.
4. Run `pnpm lint:screenshots` to confirm the count still matches.
5. Upload the assets folder to the WordPress.org plugin SVN:
   ```
   svn add assets/screenshot-*.png
   svn propset svn:mime-type image/png assets/screenshot-*.png
   svn commit -m "Add plugin screenshots for WordPress.org listing."
   ```

## Slot map

| # | Source admin route | What to show |
|---|---|---|
| 1 | Brasth Document Sync > Setup | Setup wizard with OAuth client ID, client secret, redirect URI, and account connection state. |
| 2 | Brasth Document Sync > Sources | Sources list table with filters and the bulk sync control, populated with at least 3 sources covering different statuses. |
| 3 | Brasth Document Sync > Sources (Drive browser panel) | Drive browser with breadcrumbs, search, and folder navigation. Show 2-3 folders or files. |
| 4 | Posts > Add New (side meta box) | Brasth Document Sync meta box showing link state, last sync, and the link/sync/detach actions. |
| 5 | Brasth Document Sync > Logs | Logs table with diagnostic events and the source/level filters in the toolbar. |

## Risk notes

- The lint script cannot detect placeholder PNGs vs real images. The capture runbook above is the only safeguard against shipping the 1x1 placeholders by mistake.
- If the readme or asset count changes during normal development, the lint will fail and force the developer to update both sides in the same commit.
- Drive browser and Logs screens render their data through WordPress REST endpoints, so capturing them against a stubbed environment requires the stub to return non-empty data; an empty state does not match the planned captions.

## Validation

- `pnpm lint:screenshots` exits 0 with the current readme and placeholder assets.
- Manual drift test: rename `assets/screenshot-3.png` to `.bak` and confirm the lint fails with a clear missing-file message.
- Manual drift test: remove entry `3.` from readme.txt and confirm the lint fails with a count-mismatch message.
