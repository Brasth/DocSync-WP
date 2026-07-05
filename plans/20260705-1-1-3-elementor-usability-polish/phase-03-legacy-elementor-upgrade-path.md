# Phase 03 Legacy Elementor Upgrade Path

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-02-explicit-output-choice.md](phase-02-explicit-output-choice.md)
- Docs: [../../docs/system-architecture.md](../../docs/system-architecture.md)

## Overview

- Date: 2026-07-05
- Description: Make existing legacy Elementor sources understandable and safely upgradeable.
- Priority: P1
- Implementation status: Complete
- Review status: Local checks passed

## Key Insights

- Current code intentionally preserves legacy sources.
- Product issue is hidden behavior: user expects new presets after update.
- Silent auto-migration is unsafe for live Elementor pages.

## Requirements

- Detect source uses Elementor sync but has no explicit Elementor preset.
- Show inline notice in post sync metabox.
- Offer actions:
  - Upgrade to Feature Block
  - Upgrade to Hero Page
  - Keep legacy
- Feature Block is recommended default.
- "Keep legacy" should dismiss prompt for this source if feasible without new broad data model.

## Architecture

Use source fields already returned by REST: `elementorSync`, `elementorPreset`. If a dismissal is needed, prefer source meta with a narrow key; otherwise keep first version simple and do not persist dismissal.

## Related code files

- `resources/js/admin/features/post-sync/post-meta-box-app.tsx`
- `resources/js/admin/features/post-sync/use-post-sync-actions.ts`
- `resources/js/admin/shared/ui/layout-preset-selector.tsx`
- `src/Sync/SourceRepository.php`
- `src/Rest/SourceController.php`

## Implementation Steps

1. Add helper to identify legacy Elementor source in UI.
2. Render notice below source summary and above actions.
3. Wire upgrade buttons to `updateSource(postId, { elementorPreset })`.
4. After upgrade, source state updates and next sync reconverts due fingerprint.
5. Keep "legacy output" label only for legacy state, not default UX.

## Todo list

- [x] Add legacy detection helper.
- [x] Add upgrade notice UI.
- [x] Add Feature Block and Hero Page quick actions.
- [x] Confirm sync fingerprint forces reconversion.
- [x] Decide whether "Keep legacy" needs persisted dismissal.

## Success Criteria

- Existing legacy Elementor source clearly explains why output did not change.
- User can upgrade without relinking the Google Doc.
- No existing source changes output unless user chooses upgrade.

## Risk Assessment

- Risk: users think upgrade instantly changes content.
- Mitigation: copy says "Preset applies on next sync" or trigger sync after confirmation if chosen.

## Security Considerations

- Upgrade action must keep existing capability and nonce checks.
- Do not allow unsupported preset IDs.

## Next steps

Improve actual Elementor preset output quality.

## Verification

- `pnpm typecheck`
- `pnpm lint:js`
- `pnpm build`

## Unresolved Questions

- None. Keep legacy dismissal is local to the metabox session; no new source meta was added.
