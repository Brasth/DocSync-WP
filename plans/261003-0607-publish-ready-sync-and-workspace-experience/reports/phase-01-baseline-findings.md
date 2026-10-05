# Phase 1 baseline findings

Date: 2026-10-03 | Status: PARTIAL (blocked on running WordPress + Google OAuth)

## Done (no Docker needed)
- Baseline green on `main` + uncommitted 1.1.5 bump:
  - composer: test:layout-fixtures, test:elementor-fixtures, test:large-doc-fallback-fixtures, test:telemetry-settings, test:folder-watch-update, lint
  - pnpm: typecheck, lint:js
  - scripts/validate-wordpress-readme.sh
- Static evidence for redirect links: no unwrap logic in `src/Sync`; fixtures only use `example.test` links. Not yet reproduced on a real export.

## Open (needs devcontainer + real Google account)
- [ ] Real-Doc href values, HTML ZIP path and large-doc fallback path
- [ ] Imported image filenames and alt meta
- [ ] Block editor open-and-save round-trip diff (input to Phase 7 normalization)
- [ ] Sanitized redirect-link fixture (`tests/fixtures/output-fidelity/google-redirect-links/`)
- [ ] 1.1.5 release state: working tree bumps version in 3 files, `readme.txt` changelog stops at 1.1.4

## Blockers
- Docker daemon not running (Colima socket missing).
- Needs Google OAuth client credentials and a purpose-written test Doc.

## Unresolved questions
- What ships as 1.1.5: add changelog entry, or revert the bump?
