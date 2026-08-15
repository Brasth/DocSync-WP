---
phase: 3
title: "Verification and demo kit"
status: pending
priority: P1
dependencies: [1, 2]
effort: "0.5-1d"
---

# Phase 3: Verification and demo kit

## Context Links

- Example Doc: https://docs.google.com/document/d/18uBD2pW6Xh1JSuVK9VfxGcXv88xYAlVMKbdMnzA32Ts/edit?tab=t.0
- Phase 1 fixtures + Phase 2 multi-select
- Verify scripts: `composer test:layout-fixtures`, `pnpm lint`, `pnpm typecheck`, `pnpm build`

## Overview

Prove the example SEO Doc is editor-valid, prove folder multi-select on a staging site with real cron, and give marketing a 4-minute demo kit. No extra product surface.

## Requirements

- Functional: sync the example Doc (or a private copy) via HTML ZIP path. Open in Gutenberg: both bullet lists editable, no recovery banner, numbered `1.` / `1.1.` remain headings.
- Functional: put 3–5 similar Docs in one Drive folder. Multi-select → that many drafts.
- Non-functional: layout fixtures + lint/typecheck/build green.
- Non-functional: one GIF/screenshot set for marketing. Script already agreed: folder → drafts → open one post.

## Architecture

Verification only. No new runtime module.

Check both convert paths if the example Doc is small enough for ZIP (it is). Large-doc fallback is out of scope unless ZIP fails.

## Related Code Files

- No product files unless a Phase 1/2 bug is found.
- Optional: `docs/project-changelog.md` + `readme.txt` changelog when this ships (freeze overridden; this update can ship on its own line).
<!-- Updated: Validation Session 1 - start now, not after 1.1.4 -->
- Demo notes stay in `plans/260815-1054-plain-folder-drafts-gutenberg-lists/reports/` — not marketing site copy in the plugin.

## Implementation Steps

1. Run `composer test:layout-fixtures`, `composer lint` (if PHP env), `pnpm lint`, `pnpm typecheck`, `pnpm build`.
2. On staging with **real server cron**: connect Google, sync the example Doc as a single source. Screenshot Gutenberg list blocks (list-item tree in List View).
3. Multi-select 5 Docs including the example. Confirm 5 drafts, progress completes, lists valid on at least two posts.
4. Negative checks: folder row does not create; 21st checkbox blocked; already-linked fileId skip/warning.
5. Record a silent GIF: open folder → select 5 → drafts appear → open one list. Give marketing the one sentence. Banned: auto-publish, watch overnight, "handles everything".
6. If editor still shows recovery: capture `post_content` list comments + HTML ZIP list snippet; return to Phase 1. Do not ship Phase 2 alone.

## Todo List

- [x] Automated fixtures/lint/typecheck (layout fixtures + typecheck + lint:js; build not re-run in review)
- [ ] Example Doc editor spot-check
- [ ] 5-Doc folder create spot-check
- [ ] Demo GIF + banned-line card for marketing
- [ ] Changelog when this ships

## Success Criteria

- [ ] Example Doc: 2 `core/list`, 0 unexpected `core/list` from `1.` headings, 0 recovery UI
- [ ] 5-Doc folder run: 5 drafts, content sync finishes
- [ ] Marketing can demo without Setup/OAuth on stage (pre-connected staging)
- [ ] No folder-watch or publish claims in the demo script

## Risk Assessment

- Staging WP-Cron-on-traffic will make the demo look broken. Use real cron.
- Example Doc has 6 images. Images are not the promise; if media fails, still ship lists + drafts, file a follow-up.
- Google export HTML ≠ Drive HTML ZIP in edge cases. If ZIP lists differ, add a ZIP-captured fixture from the real export, not the browser export.

## Security Considerations

- Do not commit the live Doc HTML if it contains private comments beyond the public URL. Public Doc is OK as a reduced fixture (lists + headings only).
- Do not put OAuth secrets in the demo kit.

## Next Steps

After ship: marketing pitches agencies. Folder watch stays a later plan.
