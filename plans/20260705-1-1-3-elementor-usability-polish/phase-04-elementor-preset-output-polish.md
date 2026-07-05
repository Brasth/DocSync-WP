# Phase 04 Elementor Preset Output Polish

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-01-product-qa-baseline.md](phase-01-product-qa-baseline.md)
- Docs: [../../docs/system-architecture.md](../../docs/system-architecture.md), [../../docs/code-standards.md](../../docs/code-standards.md)

## Overview

- Date: 2026-07-05
- Description: Make Elementor Hero Page and Feature Block visually publishable for simple agency docs.
- Priority: P1
- Implementation status: Pending
- Review status: Pending

## Key Insights

- Current output is valid but visually plain.
- Publishable means spacing, width, image behavior, and responsive basics.
- Must stay compatible with free Elementor widgets.

## Requirements

- Feature Block preset:
  - consistent container/section padding
  - readable max width
  - heading/text spacing
  - sane image width and alignment
  - icon list with less noisy default icon if supported
- Hero Page preset:
  - stronger first section
  - title/intro/image grouping
  - responsive stack behavior
  - remaining content as feature sections
- Preserve sanitized imported HTML.
- Do not use Elementor Pro widgets.

## Architecture

Enhance `LayoutBuilder` and `WidgetFactory` settings. Keep preset registry and source metadata unchanged. Update fixture expected JSON because settings become part of output contract.

## Related code files

- `src/Sync/Elementor/Preset/ElementorPresetConversionService.php`
- `src/Sync/Elementor/LayoutBuilder.php`
- `src/Sync/Elementor/WidgetFactory.php`
- `src/Sync/Elementor/Preset/ElementorPresetBlueprint.php`
- `tests/fixtures/elementor-presets/*`
- `scripts/verify-elementor-fixtures.php`

## Implementation Steps

1. Define minimal Elementor settings for containers/sections.
2. Add preset-aware layout group settings if needed.
3. Add practical heading/text/image/list defaults.
4. Update deterministic fixtures.
5. Validate in local fixture test.
6. Verify frontend screenshot on staging.

## Todo list

- [ ] Decide exact setting keys for current Elementor compatibility.
- [ ] Improve Feature Block group settings.
- [ ] Improve Hero Page group settings.
- [ ] Update golden fixtures.
- [ ] Run `composer test:elementor-fixtures`.
- [ ] Screenshot staging frontend results.

## Success Criteria

- Simple Feature Block doc needs 0 developer edits for acceptable layout.
- Hero Page doc produces recognizable page hero.
- Elementor editor can open and edit generated widgets.

## Risk Assessment

- Risk: Elementor setting keys drift by version.
- Mitigation: use stable free-widget/container settings and staging test against installed Elementor.

## Security Considerations

- Continue sanitizing HTML before converting to widget settings.
- Avoid embedding unsafe URLs or scripts in HTML widget.

## Next steps

Add diagnostics, fallback consistency, and release verification.

## Unresolved Questions

- Should Hero Page use one-column hero first, or two-column text/media when image exists? Recommended: one-column on first patch unless design QA demands split.
