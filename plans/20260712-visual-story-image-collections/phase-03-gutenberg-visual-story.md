# Phase 03 Gutenberg Visual Story

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-02-shared-collection-contract.md](phase-02-shared-collection-contract.md)
- Docs: [../../docs/system-architecture.md](../../docs/system-architecture.md), [../../docs/design-guidelines.md](../../docs/design-guidelines.md)

## Overview

- Date: 2026-07-12
- Description: Render explicit Visual Story collections as editable native Gutenberg galleries.
- Priority: P1
- Implementation status: Complete
- Review status: Pending

## Key Insights

- The plugin already emits native `core/image` blocks for standalone images.
- `core/gallery` with nested `core/image` blocks is portable and does not require a custom block or frontend asset.
- Only `LayoutConversionService` should alter Gutenberg rendering for the new preset.

## Requirements

- Visual Story uses `core/gallery` for eligible collections and current native image output otherwise.
- Preserve nested image order, URLs/media mapping, alt text, captions, and custom links.
- Use WordPress core attributes with a bounded desktop column policy; avoid plugin CSS.
- Preserve Clean Article, Documentation, and Plain Blocks exactly.

## Architecture

`LayoutConversionService` invokes the shared collection detector only when the resolved Gutenberg preset is `visual_story`. `HtmlBlockFactory` gains a gallery builder that composes the existing image-block construction rather than duplicating image serialization. The registry's policy version becomes part of the normal layout fingerprint.

## Related code files

- `src/Sync/Layout/LayoutConversionService.php`
- `src/Sync/Layout/LayoutPresetRegistry.php`
- `src/Sync/HtmlBlockFactory.php`
- `src/Sync/HtmlStandaloneImageDetector.php`
- `scripts/verify-layout-fixtures.php`
- `tests/fixtures/layout-presets/`

## Implementation Steps

1. Add a native gallery builder that reuses existing child-image serialization.
2. Route only Visual Story node sequences through the collection detector.
3. Serialize eligible collections as `core/gallery`; leave all non-collections unchanged.
4. Add golden fixtures for collections, captions, links, interruptions, tables, and one/two-image negatives.
5. Test normal ZIP and forced large-document fallback final output.

## Todo list

- [x] Add gallery block composition without a custom block.
- [x] Preserve child image fidelity.
- [x] Add Visual Story Gutenberg fixtures.
- [x] Add negative fixtures for collection boundaries.
- [x] Run layout and fallback fixture suites.

## Success Criteria

- A qualifying Visual Story sequence is editable as a native gallery in WordPress 6.4+.
- Existing preset fixtures are byte-for-byte unchanged.
- Selecting Visual Story forces safe re-conversion; unselected sources retain skip behavior.

## Risk Assessment

- Risk: core gallery serialization differs from expected markup. Mitigation: use WordPress serialization primitives and supported-baseline runtime smoke.
- Risk: media fidelity is lost in nested blocks. Mitigation: compare every child against current standalone-image output.

## Security Considerations

- Reuse sanitized image URLs and existing block markup sanitization.
- Do not introduce raw source HTML into gallery attributes.

## Next steps

Map the same semantic collection to Elementor Free-compatible editable grids.

## Unresolved Questions

- Confirm the initial gallery column cap; recommended maximum is three desktop columns.
