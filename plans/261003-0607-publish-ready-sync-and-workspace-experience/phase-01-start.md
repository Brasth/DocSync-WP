---
phase: 1
title: "Phase 1: Start — baseline verification and release hygiene"
status: todo
priority: P1
effort: "0.5d"
dependencies: []
---

# Phase 1: Start — baseline verification and release hygiene

## Overview

Prove the assumptions later phases rest on, using one real Google Doc, and leave the release state clean. No product code changes.

## Key insights

- Redirect-link problem is inferred from code (no unwrap in `src/Sync`, fixtures only use `example.test` links). Not yet reproduced.
- Phase 7 depends on block markup surviving a Gutenberg open-and-save unchanged. Unknown today.
- Working tree bumps `1.1.4 → 1.1.5` in three files; `readme.txt` changelog stops at 1.1.4.

## Requirements

- Functional: none (verification only).
- Non-functional: findings written down with evidence; captured fixtures contain no private content.

## Related code files

- Create: `plans/261003-0607-publish-ready-sync-and-workspace-experience/reports/phase-01-baseline-findings.md`
- Create: `tests/fixtures/output-fidelity/google-redirect-links/source.html` (sanitized real export excerpt)
- Modify: `readme.txt`, `changelog/` (1.1.5 entry) — or revert the version bump, per owner decision

## Implementation steps

1. Start devcontainer (`http://localhost:8890`). Prepare test Doc: 3 external links, 1 mailto link, 1 link to another Google Doc, 2 images (one with alt text), headings, list, table.
2. Sync via HTML ZIP path. Inspect `post_content`: record every `href`. Confirm or refute `https://www.google.com/url?q=` wrapping.
3. Force the large-doc fallback path (existing fixture approach) and record `href` values. Expectation: fallback already clean (`DocsApiInlineRenderer` uses `link.url`).
4. Record Media Library filenames and attachment alt meta for imported images.
5. Open synced post in block editor, change nothing, save. Diff `post_content` before/after. Record any byte differences per block type.
6. Save sanitized export HTML excerpt as fixture for Phase 2.
7. Decide 1.1.5 contents with owner; add changelog entry or revert bump. Run `scripts/validate-wordpress-readme.sh`.
8. Write findings report: link result, image names, round-trip diff, decisions.

## Todo

- [ ] Real-Doc link behaviour recorded for both import paths
- [ ] Image filename and alt behaviour recorded
- [ ] Editor round-trip diff recorded
- [ ] Redirect-link fixture committed
- [ ] 1.1.5 release state reconciled
- [ ] Findings report written
- [ ] Release runtime gate executed (see Runtime verification gate)

## Success criteria

- [ ] Report states, with evidence, whether redirect wrapping occurs. If it does not, Phase 2 drops the link cleaner and keeps image naming only.
- [ ] Report lists block types whose markup changes on editor save (input to Phase 7 normalization).
- [ ] `readme.txt` stable tag and changelog agree.

## Runtime verification gate

Added 2026-10-04 by the code review. Code-level checks are green (`composer lint`, all `composer test:*`, `pnpm lint`, `pnpm typecheck`, `pnpm build`), but nothing has executed inside WordPress. These are the behaviour claims still resting on static evidence, consolidated from every phase's "needs runtime check" note. Each line is an action plus its expected result; run them before tagging the release.

### Apply policy and review-before-overwrite (Phase 8)

- [ ] Source on "Only when I sync" (`manual`), published post, recurring scheduled tick: content and stored status unchanged, no Google export in the log.
- [ ] Source on "Ask me first" (`review`), changed Doc, scheduled tick: status `update_available`, content unchanged, one event across three ticks, stored Google version not advanced.
- [ ] Apply update: content updates; "View changes" opens a revision comparison when revisions exist.
- [ ] Block added inside the synced run + changed Doc + `auto` policy: scheduled tick holds with the "WordPress edits" message; manual sync returns 409 `docsync_wp_local_edits` until `confirmOverwrite`.
- [ ] Doc unchanged but pipeline output changes: hold message says the plugin formats the Doc differently, not that the Doc changed.

### Doc metadata table (Phase 6)

- [ ] Full metadata table: title, slug, excerpt, featured image (image cell and `first`), categories, tags, author, and SEO fields land on the post; table gone from `post_content`.
- [ ] Post carries an extra WordPress category while the Doc has a Categories row: after sync the extra category is gone **and named** in the sync message (`Replaced in WordPress: categories (removed: …)`).
- [ ] Delete the Categories row from the Doc, sync again: WordPress terms survive untouched.
- [ ] Doc lists a term the sync owner may not create: term not created, warning says so instead of vanishing.
- [ ] Slug row on a published post: warning, slug unchanged.
- [ ] Yoast and Rank Math each write their keys; with neither active, info warning and no write.
- [ ] Large-doc fallback: fields applied at completion only, not mid-flush.

### Content preservation (Phase 7)

- [ ] Blocks added above and below the synced run survive re-sync and are reported as kept.
- [ ] Open the synced post in the block editor, save with no edits: no false conflict and no hold on the next tick.
- [ ] Edit inside the synced run and save: conflict detected (hold or confirm), never a silent overwrite.

### ZIP upload first sync (Phase 5)

- [ ] Real "Web Page (.html, zipped)" export uploaded from Setup and from the empty Sources screen through the admin `apiFetch` multipart path: formatted draft with imported media, no OAuth configuration.
- [ ] Corrupt ZIP: draft and any imported images are deleted.
- [ ] Eleventh import in an hour is refused; the rate-limit option rows are gone after uninstall.

### Failure alerts (Phase 3)

- [ ] Forced scheduled failure: one digest email per recipient, one Site Health warning, no duplicate email on the next tick.
- [ ] Source recovers: the next digest skips it.
- [ ] `failure_alerts = off`: no email and no pending rows.

### Editor, Posts list, and workspace (Phases 9-10)

- [ ] Posts list bulk sync queues linked posts, skips ones with WordPress edits, and reports both counts.
- [ ] Block editor pre-publish notice appears for `update_available`; command palette entries work; classic editor stays a no-op.
- [ ] Non-admin users are never sent to the admin-only Setup screen.
- [ ] Sources search keeps typed text while results load; the "needs attention" count equals its filtered list.

### Knowledge base mode (Phase 11)

- [ ] Folder watch with hierarchy: container pages created in order, Docs placed beneath them.
- [ ] Cross-Doc links resolve to permalinks; heading anchors and TOC render.

## Risk assessment

- Devcontainer needs working Google OAuth credentials → use the existing cloud bootstrap/OAuth configure script; if unavailable, verify on a staging site.
- Fixture could leak private Doc text → use a purpose-written test Doc only.

## Next steps

Unblocks Phase 2 and Phase 7.
