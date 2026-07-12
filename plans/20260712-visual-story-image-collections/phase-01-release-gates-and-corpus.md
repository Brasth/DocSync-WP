# Phase 01 Release Gates And Corpus

## Context links

- Parent: [plan.md](plan.md)
- Architecture: [reports/01-architecture-synthesis.md](reports/01-architecture-synthesis.md)
- Docs: [../../docs/project-roadmap.md](../../docs/project-roadmap.md), [../../plans/reports/architecture-decision-20260712-internal-release-validation.md](../../plans/reports/architecture-decision-20260712-internal-release-validation.md)

## Overview

- Date: 2026-07-12
- Description: Keep release scope honest and prove the source structures required for safe image collections.
- Priority: P1
- Implementation status: Pending
- Review status: Pending

## Key Insights

- 1.2.0 is explicitly frontend-only and must not include conversion changes.
- Adjacent images are not a Google Docs semantic. A real corpus must prove conservative boundaries before implementation.
- The large-document fallback is a separate import representation and must not be inferred from ZIP behavior.

## Requirements

- Complete and record all 1.1.4 evidence gates before any Visual Story code.
- Validate and ship the independent 1.2.0 preset-picker decision first.
- Assemble a fixture corpus for intended galleries and harmful false-positive examples.
- Confirm the product rule: minimum three images; explicit Visual Story selection only.

## Architecture

This phase creates no runtime behavior. It constrains later work to an independently releasable preset feature rather than coupling a UI picker, document analysis, and output conversion in one release.

## Related code files

- `docs/project-roadmap.md`
- `docs/deployment-guide.md`
- `plans/reports/architecture-decision-20260712-internal-release-validation.md`
- `tests/fixtures/layout-presets/`
- `tests/fixtures/elementor-presets/`

## Implementation Steps

1. Record the 1.1.4 release SHA, staging evidence, ZIP smoke result, and unresolved defects.
2. Run five uncoached layout-selection sessions to decide whether the 1.2.0 preset picker ships.
3. Create paired ZIP and Docs API fallback fixtures for image collections and negative cases.
4. Classify each fixture as intended collection, intentionally separate images, or unsupported/ambiguous.
5. Approve Visual Story only if the corpus demonstrates fidelity for both target editors.

## Todo list

- [ ] Complete 1.1.4 release gates.
- [ ] Complete the 1.2.0 selection evidence gate.
- [ ] Collect representative image collections, captions, links, tables, and large-doc cases.
- [ ] Confirm three-image minimum and explicit-selection policy.
- [ ] Mark unsupported structures in the test corpus.

## Success Criteria

- Visual Story has a bounded user promise and a fixture corpus before implementation begins.
- No source content is used for a feature recommendation or preview.
- The plan can be released or cancelled without changing 1.2.0 scope.

## Risk Assessment

- Risk: treating a visual idea as universal behavior. Mitigation: explicit preset plus negative corpus.
- Risk: release regressions hide conversion defects. Mitigation: complete 1.1.4 first.

## Security Considerations

- Use sanitized fixture content only.
- Do not retain, transmit, or log customer document content for feature discovery.

## Next steps

Define the shared semantic collection contract and golden output expectations.

## Unresolved Questions

- Which supported Elementor versions and configurations define the staging baseline?
