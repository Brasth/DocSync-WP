---
title: "Visual Story Image Collections"
description: "Plan an explicit, source-safe visual preset that renders eligible image sequences in Gutenberg and Elementor."
status: in_progress
priority: P2
effort: 84h
branch: main
tags: [layout-presets, gutenberg, elementor, images, sync]
created: 2026-07-12
---

# Visual Story Image Collections

## Goal

Let a user deliberately select a Visual Story layout that turns only unambiguous consecutive standalone-image collections into editable native Gutenberg galleries or Elementor Free image grids. Preserve source fidelity and all current preset behavior.

## Product Decisions

- 1.1.4 remains hardening-only. It must pass before feature work.
- 1.2.0 remains the roadmap-bounded frontend preset picker; it ships independently and adds no image conversion behavior.
- Visual Story is a later independently shippable minor release, not a hidden expansion of 1.2.0.
- Image grouping is explicit through the Visual Story preset. No auto-grouping, preview, recommendation, migration, or new source setting ships.
- A collection requires at least three eligible adjacent standalone images. Ambiguous content remains individual images.
- Gutenberg uses native `core/gallery`; Elementor uses editable Elementor Free image grids, not Pro gallery or carousel widgets.

## Phase Overview

| Phase | Status | Progress | File |
| --- | --- | --- | --- |
| 01 Release Gates And Corpus | Pending | 0% | [phase-01-release-gates-and-corpus.md](phase-01-release-gates-and-corpus.md) |
| 02 Shared Collection Contract | Complete | 100% | [phase-02-shared-collection-contract.md](phase-02-shared-collection-contract.md) |
| 03 Gutenberg Visual Story | Implemented | 80% | [phase-03-gutenberg-visual-story.md](phase-03-gutenberg-visual-story.md) |
| 04 Elementor Visual Story | Implemented | 80% | [phase-04-elementor-visual-story.md](phase-04-elementor-visual-story.md) |
| 05 Safety, QA, And Release | In Progress | 50% | [phase-05-safety-qa-and-release.md](phase-05-safety-qa-and-release.md) |

## Architecture Summary

Keep document import output-neutral. A shared detector classifies a maximal run of eligible standalone image nodes only when the selected Visual Story preset requests image collections. Gutenberg and Elementor consume the same semantic collection but render it through their native editor models. Existing source meta and preset-selection payloads remain the contracts; selecting a new preset changes the existing layout fingerprint and safely re-converts only that source.

## Success Criteria

- No existing preset output changes.
- Explicit Visual Story output is editable with WordPress core blocks or Elementor Free widgets.
- ZIP and Docs API fallback produce equivalent final image grouping for equivalent source structure.
- Image order, alt text, captions, links, and media mapping survive conversion.
- No REST route, post meta, cron behavior, preview, recommendation engine, custom block, or Elementor Pro dependency is added.

## References

- [reports/01-architecture-synthesis.md](reports/01-architecture-synthesis.md)
- [../../docs/project-roadmap.md](../../docs/project-roadmap.md)
- [../../docs/system-architecture.md](../../docs/system-architecture.md)
- [../../docs/code-standards.md](../../docs/code-standards.md)
- [../../docs/design-guidelines.md](../../docs/design-guidelines.md)

## Unresolved Questions

- Select the release number after the separate 1.2.0 picker outcome is known.
- Complete the required staging, beta, ZIP-install, and release-validation evidence before approving a Visual Story release.
