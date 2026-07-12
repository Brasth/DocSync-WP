# Phase 04 Elementor Visual Story

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-02-shared-collection-contract.md](phase-02-shared-collection-contract.md)
- Docs: [../../docs/system-architecture.md](../../docs/system-architecture.md), [../../plans/20260705-1-1-3-elementor-usability-polish/phase-04-elementor-preset-output-polish.md](../../plans/20260705-1-1-3-elementor-usability-polish/phase-04-elementor-preset-output-polish.md)

## Overview

- Date: 2026-07-12
- Description: Render explicit Visual Story collections as responsive, editable Elementor Free image grids.
- Priority: P1
- Implementation status: Complete
- Review status: Pending

## Key Insights

- Elementor does not need a gallery widget to give users responsive grouped images.
- Stable core containers/sections, columns, and Image widgets have a lower compatibility burden than Pro widgets.
- Existing legacy Elementor sources must not be migrated or changed.

## Requirements

- Invoke collection behavior only for `elementor_visual_story`.
- Use ordinary editable Image widgets within an Elementor Free responsive grid/row.
- Support current container and legacy section/column capability paths without migrating source output mode.
- Preserve image order, URLs/media mapping, alt, caption, and link behavior.
- Default to three desktop columns, two tablet columns, and one mobile column if current supported settings can express it reliably.

## Architecture

`ElementorPresetConversionService` consumes the shared collection detector after its existing content-node flattening. `LayoutBuilder` creates a collection wrapper appropriate to the active compatibility path; `WidgetFactory` creates the existing image-widget representation for every child. No gallery/carousel widget or new frontend CSS is introduced.

## Related code files

- `src/Sync/Elementor/Preset/ElementorPresetConversionService.php`
- `src/Sync/Elementor/Preset/ElementorPresetRegistry.php`
- `src/Sync/Elementor/Preset/ElementorPresetBlueprint.php`
- `src/Sync/Elementor/LayoutBuilder.php`
- `src/Sync/Elementor/WidgetFactory.php`
- `src/Sync/Elementor/CompatibilityChecker.php`
- `scripts/verify-elementor-fixtures.php`
- `tests/fixtures/elementor-presets/`

## Implementation Steps

1. Add the explicit Elementor Visual Story registry record and policy fingerprint data.
2. Convert eligible semantic collections to a container/section grid using stable Elementor Free settings.
3. Reuse the existing image-widget builder for collection children.
4. Add container and legacy output fixtures plus responsive setting assertions.
5. Smoke-test generated layouts in current Elementor Free and the supported legacy compatibility path.

## Todo list

- [x] Define only documented/stable Free grid settings.
- [x] Build container-path collection grid output.
- [x] Build legacy section/column equivalent.
- [x] Reuse child image widget fidelity behavior.
- [x] Add deterministic Elementor fixtures.
- [ ] Complete staging editor smoke checks.

## Success Criteria

- A qualifying collection becomes editable grouped Elementor content without Pro.
- Responsive behavior is valid on desktop, tablet, and mobile in supported Elementor versions.
- Hero Page, Feature Block, and legacy Elementor golden output remain unchanged.

## Risk Assessment

- Risk: Elementor setting schema drift. Mitigation: restrict to stable Free primitives and test the installed supported version.
- Risk: legacy sections cannot provide equivalent responsive behavior. Mitigation: reject legacy support rather than migrate or emit invalid data.

## Security Considerations

- Keep using sanitized input and existing image-widget URL handling.
- Do not emit raw HTML, scripts, or unvalidated external values into widget settings.

## Next steps

Expose the new presets through existing selection contracts and complete end-to-end regression validation.

## Unresolved Questions

- Should the release require Elementor container support if legacy-section grid behavior is not stable?
