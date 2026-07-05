# Phase 02 Explicit Output Choice

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-01-product-qa-baseline.md](phase-01-product-qa-baseline.md)
- Docs: [../../docs/design-guidelines.md](../../docs/design-guidelines.md), [../../docs/code-standards.md](../../docs/code-standards.md)

## Overview

- Date: 2026-07-05
- Description: Ask users during linking whether output should be WordPress Blocks or Elementor Layout.
- Priority: P1
- Implementation status: Pending
- Review status: Pending

## Key Insights

- User chose "ask each time" for new linking.
- Asking should happen at link time, not every sync.
- Remembered source settings keep repeat sync low-friction.

## Requirements

- Show output type choice when Elementor is available and enabled.
- Choices: WordPress Blocks, Elementor Layout.
- If WordPress Blocks, show Gutenberg preset selector.
- If Elementor Layout, show Elementor preset selector.
- Default selection should be conservative:
  - Existing Elementor-built post: Elementor Layout selected.
  - New draft or unknown post: no hidden auto-routing; ask visibly.
- Save `elementorSync`, `elementorPreset`, and `layoutPreset` consistently.

## Architecture

Extend existing `DocSourceModal` state. Keep REST payload shape unchanged: `elementorSync`, `elementorPreset`, `layoutPreset`. Do not add a new REST route.

## Related code files

- `resources/js/admin/features/doc-source-modal/doc-source-modal.tsx`
- `resources/js/admin/features/doc-source-modal/use-doc-source-modal.ts`
- `resources/js/admin/features/doc-source-modal/doc-source-modal-options.ts`
- `resources/js/admin/features/post-sync/post-meta-box-app.tsx`
- `resources/js/admin/features/post-sync/list-entry-app.tsx`
- `resources/js/admin/api/sources-api.ts`
- `src/Rest/SourceController.php`
- `src/Sync/SyncService.php`

## Implementation Steps

1. Add output type state to doc source modal.
2. Render compact segmented/radio choice in selected-doc preview area.
3. Switch preset selector based on output type.
4. Ensure new draft flow can submit Elementor Layout.
5. Ensure existing post flow initializes from current/default Elementor state.
6. Keep create/update REST payload compatible.

## Todo list

- [ ] Add output type UI.
- [ ] Add modal state and reset behavior.
- [ ] Wire new draft Elementor payload.
- [ ] Wire existing post Elementor payload.
- [ ] Add UI copy that is concise and non-technical.
- [ ] Typecheck frontend payload changes.

## Success Criteria

- User cannot link a Doc without understanding target output type.
- New synced draft can intentionally become Elementor.
- Existing source continues to remember output type after link.

## Risk Assessment

- Risk: modal becomes too dense.
- Mitigation: one compact "Output type" control, then one preset selector.

## Security Considerations

- Continue server-side validation of source target, file ID, and preset IDs.

## Next steps

Add legacy Elementor source upgrade prompt.

## Unresolved Questions

- Should output type be required before doc selection, or only after selected doc? Recommended: after selected doc.
