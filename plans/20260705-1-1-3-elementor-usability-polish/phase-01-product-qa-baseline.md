# Phase 01 Product QA Baseline

## Context links

- Parent: [plan.md](plan.md)
- Docs: [../../docs/project-roadmap.md](../../docs/project-roadmap.md), [../../docs/system-architecture.md](../../docs/system-architecture.md)
- Report: [reports/01-elementor-usability-synthesis.md](reports/01-elementor-usability-synthesis.md)

## Overview

- Date: 2026-07-05
- Description: Establish current staging behavior and define the acceptance fixture set.
- Priority: P1
- Implementation status: Pending
- Review status: Pending

## Key Insights

- Current golden fixtures are too narrow.
- Need product-level proof, not just JSON diff.
- Staging must confirm whether sources are legacy, preset, or Gutenberg.

## Requirements

- Inspect one existing Elementor source, one new draft flow, and one Gutenberg flow.
- Capture source meta shape through admin-visible state or safe backend inspection.
- Define 3-4 Google Doc fixtures for QA.
- Do not expose credentials or tokens in logs/reports.

## Architecture

No product code change. This phase creates baseline evidence and fixture checklist.

## Related code files

- `resources/js/admin/features/post-sync/post-meta-box-app.tsx`
- `resources/js/admin/features/post-sync/list-entry-app.tsx`
- `resources/js/admin/features/doc-source-modal/doc-source-modal.tsx`
- `src/Sync/SourceRepository.php`
- `src/Sync/SyncService.php`

## Implementation Steps

1. Use safe authenticated browser/session flow for staging.
2. Record current link modal behavior for existing post and new synced draft.
3. Record current post sync metabox state for legacy Elementor source.
4. Define QA fixture docs: simple article, feature page, hero page, long doc fallback.
5. Write expected user-visible outcome for each fixture.

## Todo list

- [ ] Authenticate to staging with safe state handling.
- [ ] Inspect current 1.1.2 Elementor source behavior.
- [ ] Define fixture docs and acceptance checklist.
- [ ] Capture screenshots before changes.

## Success Criteria

- Team can explain exactly why current staging output feels wrong.
- Fixture set covers Blocks, Elementor Feature, Elementor Hero, and legacy upgrade.

## Risk Assessment

- Risk: staging data differs from real customer sites.
- Mitigation: include both existing-source and new-draft workflows.

## Security Considerations

- Never store Google tokens, WP cookies, or passwords in plan/report.
- Screenshots must avoid credential/account pages.

## Next steps

Proceed to explicit link flow once baseline confirms UX gap.

## Unresolved Questions

- Which staging Google Docs should become canonical QA fixtures?
