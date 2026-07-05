---
title: "1.1.3 Elementor Usability Polish"
description: "Make Elementor sync choices explicit, remembered, visually useful, and QA-verifiable."
status: in_progress
priority: P1
effort: 28h
branch: main
tags: [elementor, sync, ux, layout-presets, qa]
created: 2026-07-05
---

# 1.1.3 Elementor Usability Polish

## Goal

Make Elementor sync match end-user expectation: when users choose Elementor, synced Google Docs become understandable, editable, publishable Elementor layouts without hidden workflow rules.

## Product Decisions

- Ask each time during linking/new draft whether output should be WordPress Blocks or Elementor Layout.
- Remember output choice per source after linking.
- Do not silently migrate existing Elementor sources from legacy output.
- Show explicit upgrade action for legacy Elementor sources.
- Default Elementor preset is Feature Block when Elementor Layout selected.
- Hero Page remains opt-in for docs with title, intro, and image.

## Phase Overview

| Phase | Status | Progress | File |
| --- | --- | --- | --- |
| 01 Product QA Baseline | Pending | 0% | [phase-01-product-qa-baseline.md](phase-01-product-qa-baseline.md) |
| 02 Explicit Output Choice | Complete | 100% | [phase-02-explicit-output-choice.md](phase-02-explicit-output-choice.md) |
| 03 Legacy Elementor Upgrade Path | Complete | 100% | [phase-03-legacy-elementor-upgrade-path.md](phase-03-legacy-elementor-upgrade-path.md) |
| 04 Elementor Preset Output Polish | Pending | 0% | [phase-04-elementor-preset-output-polish.md](phase-04-elementor-preset-output-polish.md) |
| 05 Diagnostics Verification Release | Complete | 100% | [phase-05-diagnostics-verification-release.md](phase-05-diagnostics-verification-release.md) |

## Architecture Summary

Keep existing split between Gutenberg layout presets and Elementor presets. Extend the link UI and source update flow, not the preset architecture. Use current source meta: `_docsync_wp_elementor_sync`, `_docsync_wp_elementor_preset`, `_docsync_wp_layout_preset`, and `_docsync_wp_last_layout_fingerprint`.

## Success Criteria

- New link flow always asks output type when Elementor is available.
- Existing source sync does not ask again and uses remembered source settings.
- Legacy Elementor sources show clear upgrade option, not hidden legacy behavior.
- Elementor output has practical spacing, width, image, and responsive defaults.
- Large-doc fallback respects selected Elementor preset.
- Staging QA proves one Blocks sync, one Elementor Feature sync, one Elementor Hero sync, and one legacy upgrade.

## References

- [reports/01-elementor-usability-synthesis.md](reports/01-elementor-usability-synthesis.md)
- [../../docs/project-roadmap.md](../../docs/project-roadmap.md)
- [../../docs/system-architecture.md](../../docs/system-architecture.md)
- [../../docs/design-guidelines.md](../../docs/design-guidelines.md)

## Unresolved Questions

- Should we add a bulk upgrade action for legacy Elementor sources in 1.1.4 or later?
- Should Setup include a future site default for preferred output type, or keep ask-each-time only?
