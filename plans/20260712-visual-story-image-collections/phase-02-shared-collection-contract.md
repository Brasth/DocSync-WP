# Phase 02 Shared Collection Contract

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-01-release-gates-and-corpus.md](phase-01-release-gates-and-corpus.md)
- Docs: [../../docs/system-architecture.md](../../docs/system-architecture.md), [../../docs/code-standards.md](../../docs/code-standards.md)

## Overview

- Date: 2026-07-12
- Description: Add one conservative, renderer-neutral image-collection classifier shared by Gutenberg and Elementor.
- Priority: P1
- Implementation status: Complete
- Review status: Pending

## Key Insights

- `HtmlStandaloneImageDetector` is already the authority for a single eligible image.
- Importers must stay output-neutral; grouping belongs after each converter's normal structural flattening.
- Separate classifiers per editor would drift and create inconsistent sync results.

## Requirements

- Recognize only maximal runs of three or more eligible standalone-image nodes.
- Preserve detector-provided image fidelity data without reparsing URLs, captions, or links.
- Stop on non-whitespace structural content and retain original order.
- Keep all current non-Visual Story output byte-for-byte stable.

## Architecture

Add a small `HtmlStandaloneImageCollectionDetector` and an immutable collection value object under `src/Sync/`. The detector consumes the node sequence supplied by the current renderer, delegates per-node eligibility to `HtmlStandaloneImageDetector`, and returns individual nodes or collection values. The selected blueprint controls whether a renderer invokes collection behavior.

## Related code files

- `src/Sync/HtmlStandaloneImageDetector.php`
- `src/Sync/HtmlStandaloneImage.php`
- `src/Sync/Layout/LayoutBlueprint.php`
- `src/Sync/Layout/LayoutPresetRegistry.php`
- `src/Sync/Elementor/Preset/ElementorPresetBlueprint.php`
- `src/Sync/Elementor/Preset/ElementorPresetRegistry.php`
- `tests/fixtures/layout-presets/`
- `tests/fixtures/elementor-presets/`

## Implementation Steps

1. Define collection value data from existing standalone-image objects.
2. Define significant-node and run-boundary rules from the approved corpus.
3. Add Visual Story IDs and policy/version data to both preset blueprints/registries.
4. Include policy/version data in each affected fingerprint seed.
5. Add positive and negative classifier fixtures before renderer changes.

## Todo list

- [x] Create shared detector and value object only if the existing image type cannot represent a collection.
- [x] Add `visual_story` and `elementor_visual_story` registry records.
- [x] Version image-collection policy in fingerprints.
- [x] Add positive and negative fixtures for both renderers.
- [x] Verify existing fixture output is unchanged.

## Success Criteria

- Both renderers classify equivalent source sequences identically.
- No run of one or two images is grouped.
- Captions, links, text, tables, and unsupported wrappers do not cause accidental collections.

## Risk Assessment

- Risk: fallback and ZIP DOM structures differ. Mitigation: representation-specific fixtures with one semantic contract.
- Risk: a general-purpose detector grows into a page parser. Mitigation: only group existing standalone-image eligibility results.

## Security Considerations

- Do not weaken existing HTML sanitization or media validation.
- Treat all DOM input as untrusted until the established importer sanitizes it.

## Next steps

Render approved collections through Gutenberg's native block model.

## Unresolved Questions

- Confirm whether explicit `figcaption` is eligible while a following paragraph caption remains unsupported.
