# Architecture Decision: Internal 1.1.4 Validation

**Date:** 2026-07-12  
**Status:** Publication deferred

## Problem

No external beta feedback exists. The team cannot prove that preset selection is the primary user problem, but known release and workflow risks remain after 1.1.3.

## Decision

Run and ship **1.1.4 as an internal hardening release** before considering 1.2.0. Do not add a preset gallery, preview, bulk import, or other product capability to 1.1.4.

Internal validation replaces the currently unavailable external feedback only for reliability and release-readiness evidence. It does **not** prove demand for a preset gallery.

## Options Evaluated

| Option | Time | Risk | Decision |
| --- | --- | --- | --- |
| Internal 1.1.4 validation, then reassess 1.2.0 | 1-2 focused weeks | Low | Selected |
| Release 1.2.0 without evidence | Appears shorter | High | Rejected |
| Wait indefinitely for external feedback | Unbounded | Medium | Rejected |

## Why

- 1.1.3 joins OAuth, capability-qualified access, source-owner transfer, background sync, media import, Gutenberg/Elementor conversion, and large-document fallback. These contracts need end-to-end validation before another UI change complicates diagnosis.
- A gallery is an unproven UX hypothesis. Building it without evidence violates YAGNI and adds UI/test surface without reducing a demonstrated risk.
- A scoped hardening release isolates failures, keeps rollback simple, and protects WordPress content integrity.

## Internal 1.1.4 Validation Scope

### Release integrity

- Reconcile the 1.1.2 GitHub release asset/tag, WordPress.org SVN tag, and changelog history.
- Enforce PHP 8.1 compatibility, Plugin Check, official `readme.txt` validation, existing fixture suites, and a clean ZIP install/activation/runtime smoke test.
- Validate the WordPress.org deployment dry run against a staged deployable artifact, not the repository checkout.
- Before tagging, run `Validate Release Commit` against the immutable final release SHA and retain its workflow URL, ZIP checksum, and provenance artifact with the staging record.

### Workflow reliability

- Validate normal and oversized Google Docs, image-heavy imports, Gutenberg presets, Elementor presets, and legacy Elementor conversion.
- Validate unchanged-source skips and layout fingerprint behavior.
- Validate OAuth/token recovery, role isolation, nonce/capability failures, explicit owner transfer, background sync completion, and stale WP-Cron recovery.

### Upgrade safety

- Test representative upgrades from supported prior releases.
- Confirm no unintended reconversion, changed sync owner, lost preset selection, or WordPress content overwrite.

## Release Gates

Block release for any P0/P1 defect, content corruption, authorization boundary failure, unrecoverable queued sync, failed artifact smoke test, or missing release provenance.

Ship 1.1.4 publicly only when all internal checks pass and each failed scenario has a recorded fix or explicit accepted deferral. The annotated tag must point to the exact SHA that passed `Validate Release Commit`; publish immediately after tagging.

## 1.2.0 Decision Gate

After 1.1.4, run five internal or trusted WordPress-user task sessions using representative Google Docs. Ask participants to choose an intended output layout without coaching.

- If selection is consistently correct, retain the existing selector; do not build a gallery.
- If participants repeatedly hesitate or select incorrectly while sync itself is reliable, approve a frontend-only gallery.

Any approved gallery must reuse the existing preset registry and source payloads, preserve the Gutenberg site default, legacy Elementor conversion, and owner-transfer confirmation, and add no REST routes, post meta, Google calls, WP-Cron work, telemetry, or preview.

## Consequences

- UI novelty is delayed by one focused validation cycle.
- Reliability is improved without coupling unrelated regression sources.
- 1.1.4 is a public release after internal validation. The roadmap's external-beta requirement is replaced for this hardening release; internal QA must not be presented as external user evidence.

## Success Criteria

- All internal 1.1.4 gates pass.
- No P0/P1, content-loss, or authorization defects found.
- Generated ZIP installs, activates, and exposes required runtime routes on a clean WordPress site.
- A subsequent 1.2.0 decision is based on observed layout-selection behavior, not assumption.

## References

- `docs/project-roadmap.md`
- `docs/architecture-decision-next-release-2026-07-11.md`
- `docs/system-architecture.md`
- `docs/deployment-guide.md`

## Unresolved Questions

- Who owns the five layout-selection task sessions after 1.1.4?

## 2026-07-12 Release Evidence And Deferral

### Exact-SHA validation

- Release branch: `release/1.1.4`
- Validated commit: `cb9ab9800b92e085c61b8bd42be1c485d93e7a4e`
- Validation workflow: [Validate Release Commit #29182033696](https://github.com/Brasth/DocSync-WP/actions/runs/29182033696)
- Generated ZIP SHA-256: `d86333c5bccafb81a110eb931687166acf3f18b09a633334dfe73671eb12e1d6`
- CI validation passed exact-SHA metadata, PHP 8.1 checks, fixture suites, frontend checks, official readme validation, ZIP validation, clean-install smoke, and provenance upload.

### Accepted deferral

The internal staging matrix has not been run against the validated commit. Specifically, normal and oversized Google Docs, image-heavy imports, editor and legacy Elementor conversion, OAuth and authorization recovery, owner transfer, background-sync recovery, unchanged-source skips, fingerprint behavior, and upgrade safety remain unrecorded.

`1.1.4` must remain untagged and unpublished until these scenarios are completed and recorded. This deferral does not authorize bypassing the release gates in this decision or `docs/deployment-guide.md`.

### Historical 1.1.2 reconciliation

- Historical release commit: `b29956b4d2bca729a0c3ed3e7dbe64eb6b86fc57` (merged PR #64).
- The commit contains matching 1.1.2 plugin, package, and `readme.txt` version metadata. Its Build Snapshot ZIP and CodeQL workflows passed.
- Annotated tag `1.1.2` now points to that commit.
- GitHub Release: [1.1.2](https://github.com/Brasth/DocSync-WP/releases/tag/1.1.2)
- Release ZIP SHA-256: `ed092e2f522b4f749e5f97e55945546782ed28e7ffc4d5dfc682d012584d8d84`
- [Build Release ZIP (Tag) #29182385895](https://github.com/Brasth/DocSync-WP/actions/runs/29182385895) passed.
- [Deploy to WordPress.org SVN #29182385903](https://github.com/Brasth/DocSync-WP/actions/runs/29182385903) passed.
- WordPress.org now lists 1.1.2 as the current stable version. A subsequent 1.1.3 deployment attempt completed without changing the listing because the deployment action will not republish an existing version.
- The owner accepted 1.1.2 as the current stable version. Do not publish deferred 1.1.4 solely to correct this history issue.
