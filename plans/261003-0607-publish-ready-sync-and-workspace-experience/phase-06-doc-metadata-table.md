---
phase: 6
title: "Phase 6: Doc metadata table"
status: todo
priority: P1
effort: "4d"
dependencies: [2]
---

# Phase 6: Doc metadata table

## Overview

A two-column table at the very top of the Doc sets WordPress post fields. Sync reads it, applies it, and removes it from the content.

## Requirements

- Functional — recognized keys (case-insensitive, English):

| Key | Effect | Guard |
|---|---|---|
| Title | `post_title` | — |
| Slug | `post_name` | ignored once post is published/scheduled (warning event) |
| Excerpt | `post_excerpt` | — |
| Featured image | image in value cell → featured image; value `first` → first body image | attachment must import |
| Categories | comma list → hierarchical taxonomy of the post type | new terms only if owner can `manage_terms`; else existing only |
| Tags | comma list → non-hierarchical taxonomy | same |
| Author | email or login → `post_author` | owner needs `edit_others_posts`; target must be able to edit the type |
| SEO title / SEO description | Yoast or Rank Math meta when that plugin is active | else info event, no write |

  - Detection: first content element is a 2-column table and every non-empty first-column cell is a recognized key. Otherwise the table is ordinary content.
  - Keys present overwrite on every sync; keys absent leave the WordPress value alone.
  - Works for Gutenberg and Elementor output and both import paths.
- Non-functional: extractor pure and fixture-tested; all writes capability-checked against the sync owner, not the current request user.

## Architecture

```
import html → DocMetadataTableExtractor::extract( html ) → { fields, html (table removed), warnings }
            → converter( html ) → wp_update_post( content )
            → PostMetadataApplier::apply( post_id, owner_id, fields ) → { applied, warnings }
            → sync event detail: "Applied: title, tags (2 created)"
```

- Content hash input becomes `content + wp_json_encode( fields )` so a metadata-only change is not skipped as unchanged.
- Large-doc fallback writes partial content progressively: extraction must run on the first chunk before the first flush (`progressiveFallbackFlushCallback`), then fields applied at completion.
- `SeoMetaWriter`: Yoast keys `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`; Rank Math keys `rank_math_title`, `rank_math_description`. Detect by `WPSEO_VERSION` / `RankMath` class.
- Site setting `metadata_table_enabled`, default on (only Docs matching the convention are affected).

## Related code files

- Create: `src/Sync/Metadata/DocMetadataTableExtractor.php`, `PostMetadataApplier.php`, `SeoMetaWriter.php`
- Create: `scripts/verify-doc-metadata-fixtures.php`, `tests/fixtures/doc-metadata/{full-table,partial-keys,unknown-key-is-content,no-table,featured-image-cell,fallback-first-chunk}/`
- Modify: `src/Sync/SyncService.php` (two call sites + hash input), `src/Plugin.php`
- Modify: `src/Settings/SettingsRepository.php`, `src/Rest/SettingsController.php`, `google-setup-sync-defaults-panel.tsx`, `api/types.ts`
- Modify: `resources/js/admin/features/sync-logs/sync-log-events-table.tsx` (render applied/warning detail if event detail shape needs it)
- Modify: `composer.json`, `pr-lint.yml`, `readme.txt`, `README.md`, `docs/system-architecture.md`

## Implementation steps

1. Fixtures from real export HTML (Phase 1 Doc plus a metadata table). Write expected `fields.json` and `content.html` per fixture.
2. Extractor: DOM-based, returns normalized fields; featured image cell returns the already-rewritten local attachment URL/ID.
3. Applier: field-by-field with guards above; collects applied list and warnings; uses `wp_update_post`, `wp_set_post_terms`, `set_post_thumbnail`.
4. SEO writer with plugin detection.
5. Wire into `SyncService` on both import paths; extend hash input; add event detail.
6. Fallback path: extract before first progressive flush; add fixture to `test:large-doc-fallback-fixtures`.
7. Setting + Setup checkbox with link to the convention doc.
8. Devcontainer matrix: post + page + one CPT; owner as editor vs author (term creation and author guards); Yoast active, Rank Math active, neither.
9. Write user-guide section and a copyable template table for docsyncwp.com (content handed to site owner; site is outside this repo).

## Todo

- [x] Fixtures + extractor
- [x] Applier with capability guards
- [x] SEO writer
- [x] SyncService wiring + hash input
- [x] Fallback path
- [x] Setting + UI
- [x] Devcontainer matrix
- [x] Docs + template

## Success criteria

- [x] Fixture Doc syncs to a post with correct title, slug, excerpt, featured image, terms, SEO meta and no table in content.
- [x] Changing only "Tags" in the Doc updates tags on next sync.
- [x] Table with one unknown key stays in content untouched.
- [x] Author-role sync owner cannot create terms or reassign author; warnings appear in Sync Activity.
- [x] Existing layout, Elementor, and fallback fixture suites unchanged.

## Risk assessment

- A real content table accidentally matches → strict all-keys rule + site setting to disable.
- Slug change on published post breaks URLs → blocked by guard.
- Non-English teams want localized keys → out of scope; note for later.

## Security considerations

- Doc author may not be a WordPress user: every write runs under sync-owner capability checks; no post status changes from the Doc; no arbitrary meta keys.

## Next steps

Phase 11 reuses the extractor stage for page ordering hints if needed.
