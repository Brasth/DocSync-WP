# 1.1.6 Automation ops + listing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drive Folders becomes a thin ops surface (honest status, clickable health, attention queue) and the WordPress.org listing leads with that outcome.

**Architecture:** Extract pure watch-ops helpers (filter, attention rank, primary action, status tone). Drive Folders list consumes them. Compact `AdminShell` density is a prop. Listing and QA docs are copy-only except screenshot-1.

**Tech Stack:** WordPress 6.4+, PHP 8.1, React via `wp.element`, existing folder-watch REST, Node 22 `--experimental-strip-types` verifier.

## Global Constraints

- PHP 8.1+, WordPress 6.4+, slug/text domain `brasth-document-sync-for-google-docs`, namespace `DocSyncWP\`.
- All free. No Pro gate. No new Google scopes. No Atomic Elementor JSON.
- Do not bump `DOCSYNC_WP_VERSION` / stable tag in this change.
- Inline PHPCS suppressions prohibited.
- Keep Vite screen bundles. Reuse `AdminButton`, `StatusPill`, `ConfirmDialog`.
- WP.org: max 5 tags; screenshot files stay 1:1 with `readme.txt`.

---

### Task 1: Watch ops helpers (TDD)

**Files:**
- Create: `resources/js/admin/features/folder-watches/folder-watch-ops.ts`
- Create: `scripts/verify-folder-watch-ops.mjs`
- Modify: `composer.json` (optional) / `package.json` scripts

**Produces:** `watchNeedsAttention`, `attentionRank`, `filterFolderWatches`, `primaryWatchAction`, `watchStatusTone`

- [ ] Failing verifier, then helpers, then `node --experimental-strip-types scripts/verify-folder-watch-ops.mjs` passes
- [ ] Commit

### Task 2: Drive Folders ops UI

**Files:**
- Modify: `folder-watch-labels.ts`, `status-pill.tsx`, `folder-watches-view.tsx`, `folder-watch-table.tsx`, `folder-watch-row-actions.tsx`, `folder-watch-detail-page.tsx`, `folders-app.tsx`, `folder-watches.css`, `primitives.css`

- [ ] Honest pills, clickable tiles, attention queue, toolbar order, primary row action, detail h2, folders skeleton
- [ ] `pnpm typecheck` + `pnpm lint:js`
- [ ] Commit

### Task 3: Chrome + Sources teaser + review prompt

**Files:**
- Modify: `admin-shell.tsx`, `admin-shell.css`, `sources-app.tsx`, `sync-logs-view.tsx`, `sources-folder-watches.tsx`, `AdminPage.php`
- Create: `resources/js/admin/features/folder-watches/folder-watch-review-prompt.tsx`

- [ ] Compact masthead on Sources/Folders/Logs
- [ ] Logs submenu = Sync Activity
- [ ] Review prompt after first import
- [ ] Commit

### Task 4: Listing, changelog, Elementor checklist

**Files:**
- Modify: `readme.txt`
- Create: `changelog/20260827-automation-ops-listing.md`
- Create: `docs/qa/elementor-3-4-compatibility-checklist.md`
- Modify: `docs/design-guidelines.md` (Drive Folders ops)

- [ ] Tags + short description + screenshot 1 caption
- [ ] Commit

### Task 5: Screenshot-1 + verify

- [ ] Capture Drive Folders ops PNG to `assets/screenshot-1.png`
- [ ] `pnpm lint:screenshots`, `pnpm typecheck`, `pnpm lint`, `node --experimental-strip-types scripts/verify-folder-watch-ops.mjs`
- [ ] Commit
