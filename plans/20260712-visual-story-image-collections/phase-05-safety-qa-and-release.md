# Phase 05 Safety, QA, And Release

## Context links

- Parent: [plan.md](plan.md)
- Depends on: [phase-03-gutenberg-visual-story.md](phase-03-gutenberg-visual-story.md), [phase-04-elementor-visual-story.md](phase-04-elementor-visual-story.md)
- Docs: [../../docs/deployment-guide.md](../../docs/deployment-guide.md), [../../docs/code-standards.md](../../docs/code-standards.md)

## Overview

- Date: 2026-07-12
- Description: Prove selection safety, conversion parity, editor compatibility, and release integrity.
- Priority: P1
- Implementation status: In Progress
- Review status: Pending

## Key Insights

- Existing source payloads already carry preset IDs; Visual Story needs no REST or meta contract change.
- Source re-conversion safety depends on correctly versioned layout fingerprints.
- Fixture tests prove deterministic conversion; staging proves editor behavior, real import representations, and release packaging.

## Requirements

- Expose new registry entries through existing modal and post-level preset selectors only after the independent picker release.
- Keep site defaults unchanged and never upgrade a source to Visual Story automatically.
- Verify normal ZIP, Docs API fallback, existing preset regressions, source switching, skips, owner transfer, and clean-install packaging.
- Update product/release documentation only when the Visual Story release is approved.

## Architecture

No route, job, data-model, or selector-state expansion is needed. Existing registry summaries flow through asset config and workspace payloads. Existing optional layout and Elementor preset metadata stores the deliberate source choice. Fingerprint changes are the re-conversion mechanism.

## Related code files

- `src/Assets/AssetRegistry.php`
- `src/Rest/WorkspaceController.php`
- `src/Rest/SourceController.php`
- `resources/js/admin/features/doc-source-modal/`
- `resources/js/admin/shared/ui/layout-preset-selector.tsx`
- `src/Sync/SyncService.php`
- `scripts/verify-layout-fixtures.php`
- `scripts/verify-elementor-fixtures.php`
- `scripts/verify-large-doc-fallback-fixtures.php`
- `README.md`
- `readme.txt`
- `docs/project-roadmap.md`
- `docs/system-architecture.md`

## Implementation Steps

1. Confirm both new registry records surface through existing config without a wire-contract change.
2. Verify default, source override, switching, owner-transfer retry, and legacy Elementor retention semantics.
3. Run all golden suites for positive and negative input across ZIP and fallback paths.
4. Test native Gutenberg gallery editing and Elementor Free grid editing on staging.
5. Complete full release validation, clean ZIP install/runtime smoke, changelog, and beta feedback review.

## Todo list

- [x] Verify no new REST payload fields or post meta keys.
- [x] Verify Visual Story is opt-in and defaults are unchanged.
- [ ] Verify fingerprint-triggered re-conversion and reverse switching.
- [x] Run all PHP and frontend validation commands.
- [ ] Complete staging and minor-release beta evidence.
- [ ] Update release docs and version metadata only for the approved release.

## Success Criteria

- Existing source flows continue unchanged unless a user selects Visual Story.
- Both editor outputs are editable and visually stable after re-opening the editor.
- No image metadata or source order is lost.
- No Pro dependency, custom block, new endpoint, cron work, preview, or automatic recommendation appears in the diff.

## Risk Assessment

- Risk: policy change does not re-convert unchanged sources. Mitigation: explicit fingerprint fixtures for enter/leave Visual Story.
- Risk: a registry addition creates UI ambiguity. Mitigation: concise preset description and independent picker validation.
- Risk: minor release introduces unrelated regression. Mitigation: exact-SHA release process and beta validation.

## Security Considerations

- Retain existing REST nonce/capability checks and server-side preset validation.
- Do not expose or retain source document content beyond established sync behavior.

## Next steps

After all release gates pass, mark the plan complete and consider preview/recommendation only as a separately scoped future initiative.

## Unresolved Questions

- Which representative user cohort supplies the Visual Story beta documents and editor validation?
