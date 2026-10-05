---
phase: 7
title: "Phase 7: Synced region merge and pattern placeholders"
status: todo
priority: P1
effort: "5d"
dependencies: [1, 2]
---

# Phase 7: Synced region merge and pattern placeholders

## Overview

Sync replaces only the blocks it wrote last time. Blocks an editor adds above or below survive. Writers can drop a WordPress pattern into the Doc with `{{pattern: name}}`. Gutenberg output only.

## Key insights

- A wrapper block would change front-end markup and theme layout. Fingerprinting the synced blocks needs no markup change and is backwards compatible.
- Today `last_hash` stores only a hash of converted content, so the plugin cannot tell which blocks it owns.

## Requirements

- Functional:
  - After each successful write, store ordered fingerprints of the top-level synced blocks.
  - On next sync, find that run in current content. Found → replace run, keep blocks before and after. Not found → conflict.
  - Conflict handling in this phase: full replace as today, plus a warning event "WordPress edits inside synced content were replaced". Phase 8 changes this to hold-for-review.
  - No baseline (first sync, legacy source) → full replace, then store baseline.
  - `{{pattern: name}}` alone in a paragraph → synced pattern (`wp_block` post, exact title) as `core/block` ref; else registered pattern slug as `core/pattern`; else removed with a warning event. Clean Article and Documentation presets only.
- Non-functional: merger pure; Plain Blocks golden output unchanged; Elementor path untouched.

## Architecture

```
SyncedRegionStore (WP glue)            SyncedRegionMerger (pure)
  parse_blocks( current )   ───────►   locate( baseline[], current_fps[] )
  fingerprints                          → merged{prefix,suffix} | no_baseline | conflict
  serialize_blocks( prefix ) + new + serialize_blocks( suffix )
  after write: read back post_content → fingerprints → meta
```

- Fingerprint = `md5( blockName | ksorted attrs JSON | innerHTML with whitespace collapsed )`. Whitespace-only freeform blocks ignored. Normalization list extended from Phase 1 round-trip findings.
- Meta `_docsync_wp_region_baseline`: JSON array of fingerprints. More than 2000 blocks → store nothing (legacy behaviour).
- Baseline computed from content read back after `wp_update_post`, so save-time filters are already applied.
- Skip rule unchanged: new converted content hash equals `last_hash` → nothing written, local additions untouched.
- Placeholder detection in `ContentRoleClassifier` (new role), block emitted in `LayoutConversionService`. Pipeline version bumped.
- Detach and full uninstall delete the baseline meta.

## Related code files

- Create: `src/Sync/Region/SyncedRegionMerger.php`, `SyncedRegionStore.php`, `BlockFingerprint.php`
- Create: `src/Sync/Layout/PatternPlaceholderResolver.php`
- Create: `scripts/verify-synced-region-merge.php`, `tests/fixtures/synced-region/{prefix-suffix-kept,no-baseline,edit-inside-conflict,reordered-conflict,empty-prefix}/`
- Create: `tests/fixtures/layout-presets/pattern-placeholder/`
- Create: `.devcontainer/scripts/verify-region-round-trip.php` (`wp eval-file`)
- Modify: `src/Sync/SyncService.php` (pre-write merge call, post-write baseline call), `src/Sync/SourceRepository.php` (meta key, detach cleanup), `uninstall.php`
- Modify: `src/Sync/Layout/ContentRoleClassifier.php`, `LayoutConversionService.php`, `src/Sync/ConversionPipelineVersion.php`
- Modify: `resources/js/admin/features/post-sync/post-meta-box-app.tsx` (one help line), `sync-log-events-table.tsx` (kept-blocks detail)
- Modify: `composer.json`, `pr-lint.yml`, `readme.txt`, `README.md`, `docs/system-architecture.md`

## Implementation steps

1. Merger as pure function over fingerprint arrays; table-driven fixtures including duplicate fingerprints (first contiguous full match wins).
2. `BlockFingerprint` with normalization; unit fixtures for attribute order and whitespace variance.
3. Store: parse, merge, serialize, read-back baseline. Guard: `parse_blocks` failure → legacy full replace.
4. Wire into `SyncService` Gutenberg branch only; large-doc progressive path stores baseline at completion.
5. Warning event on conflict; event detail "Kept N WordPress blocks" on merge.
6. Placeholder resolver + classifier role + preset fixtures; unresolved name warning.
7. Devcontainer round-trip script: sync → `serialize_blocks( parse_blocks( content ) )` equals stored; manual editor open-save; add block above and below; re-sync changed Doc; assert both survive.
8. Help copy in metabox; docs.

## Todo

- [x] Merger + fixtures
- [x] Fingerprint normalization
- [x] Store + SyncService wiring
- [x] Events
- [x] Pattern placeholders
- [x] Round-trip verification
- [x] Cleanup paths (detach, uninstall)
- [x] Docs

## Success criteria

- [x] Block added above and below synced content survives a changed-Doc re-sync.
- [x] Editor open-and-save with no changes does not produce a conflict for blocks the presets emit.
- [x] Edit inside synced content → conflict detected, warning event logged.
- [x] `{{pattern: Newsletter}}` renders the synced pattern; unknown name leaves no braces in output.
- [x] Plain Blocks and Elementor fixtures unchanged.

## Risk assessment

- Gutenberg re-serializes some block → false conflict → falls back to today's behaviour, never worse. Fix by extending normalization per block type.
- Third-party plugin mutates blocks on save (e.g., heading anchors) → same safe fallback; document.
- Identical repeated blocks make run location ambiguous → require full-run match, not partial.

## Security considerations

- Placeholders insert references only (pattern ID/slug). No shortcodes, no raw HTML from the Doc. Private or draft `wp_block` posts are not resolvable.

## Next steps

Phase 8 turns the conflict signal into a review step.
