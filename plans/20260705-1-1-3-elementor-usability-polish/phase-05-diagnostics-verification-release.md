# Phase 05 Diagnostics Verification Release

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-02-explicit-output-choice.md](phase-02-explicit-output-choice.md), [phase-03-legacy-elementor-upgrade-path.md](phase-03-legacy-elementor-upgrade-path.md), [phase-04-elementor-preset-output-polish.md](phase-04-elementor-preset-output-polish.md)
- Docs: [../../README.md](../../README.md), [../../docs/deployment-guide.md](../../docs/deployment-guide.md)

## Overview

- Date: 2026-07-05
- Description: Ensure selected Elementor preset is logged, fallback is consistent, and release is verifiable.
- Priority: P1
- Implementation status: Pending
- Review status: Pending

## Key Insights

- 1.1.3 roadmap already points to sync logging and fixture gaps.
- Large-doc fallback currently risks bypassing preset path.
- QA should validate real browser output, not only generated JSON.

## Requirements

- Sync log/context records effective output path:
  - Gutenberg preset
  - Elementor preset
  - Elementor legacy
- Large-doc fallback uses selected Elementor preset.
- Release docs explain explicit output choice and legacy upgrade.
- Verification includes PHP, JS, fixtures, build, and staging smoke.

## Architecture

Keep bounded sync log model. Add safe context only; no document content, IDs beyond existing source references, tokens, or Google responses.

## Related code files

- `src/Sync/SyncService.php`
- `src/Sync/SourceRepository.php`
- `src/Sync/Elementor/Preset/ElementorPresetConversionService.php`
- `resources/js/admin/features/sync-logs/*`
- `docs/project-changelog.md`
- `readme.txt`
- `README.md`

## Implementation Steps

1. Add safe sync event context for effective layout path.
2. Update progressive fallback callback to use Elementor preset converter when preset exists.
3. Add or update tests/fixtures for fallback consistency if practical.
4. Update user-facing docs and changelog.
5. Run local verification.
6. Run staging smoke with browser screenshots.

## Todo list

- [ ] Log effective preset/legacy path safely.
- [ ] Fix large-doc fallback preset path.
- [ ] Update changelog/release notes.
- [ ] Run `composer test:layout-fixtures`.
- [ ] Run `composer test:elementor-fixtures`.
- [ ] Run `pnpm lint`, `pnpm typecheck`, `pnpm build`.
- [ ] Run staging smoke checklist.

## Success Criteria

- Support can see whether sync used Blocks, Elementor preset, or legacy.
- Large docs behave consistently with normal docs.
- Release package can be validated with existing pipeline.

## Risk Assessment

- Risk: logging adds noisy or sensitive context.
- Mitigation: log only preset IDs and mode labels.

## Security Considerations

- No Google content, token, account email, or raw document metadata in new logs.
- Keep capability checks on log visibility.

## Next steps

After release, use real-user feedback to decide whether 1.2.0 gallery/preview should move earlier.

## Unresolved Questions

- Should staging smoke screenshots become committed fixtures or release artifacts only? Recommended: release artifacts only.
