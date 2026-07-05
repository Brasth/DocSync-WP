# DocSync-WP Deployment and Release Guide

Last updated: 2026-07-05

This guide describes how to release DocSync-WP on WordPress.org and GitHub. It supports the release cadence defined in `docs/project-roadmap.md`:

- **Patch releases (1.0.x):** every 2-4 weeks, bug fixes and hardening.
- **Minor releases (1.x.0):** every 4-6 weeks, one complete feature.
- **Major releases (x.0.0):** every 6-12 months, breaking changes or major capability shifts.

## Release Types

| Type | Version | Example | Content | Beta required |
|---|---|---|---|---|
| Patch | 1.0.x | 1.0.6 | Bug fixes, hardening, compatibility, small visible improvements | No |
| Minor | 1.x.0 | 1.1.0 | One complete, independently usable feature | Yes |
| Major | x.0.0 | 2.0.0 | Breaking changes or major new capabilities | Yes, extended |

Every release must be independently usable and revertible. Half-features do not ship.

## Pre-release Checklist

Before tagging a release, verify all of the following:

- [ ] Version strings updated in:
  - `brasth-document-sync-for-google-docs.php` plugin header
  - `readme.txt` stable tag and changelog
  - `composer.json` version (if present)
  - `package.json` version
- [ ] Changelog entry added to `readme.txt`.
- [ ] `pnpm lint` passes.
- [ ] `pnpm typecheck` passes.
- [ ] `pnpm build` produces a clean `build/` directory.
- [ ] PHP validation passes in CI:
  - `composer validate --no-check-publish`
  - `composer lint`
  - `composer test:layout-fixtures`
  - `composer test:elementor-fixtures`
  - `composer test:large-doc-fallback-fixtures`
  - `vendor/bin/phpcs -i`
- [ ] Plugin Check (WordPress.org) passes in CI or locally.
- [ ] readme.txt validator passes.
- [ ] PHP compatibility check passes for declared minimum version (8.1) and current supported versions.
- [ ] No secrets, credentials, or API keys in the diff.
- [ ] If optional telemetry ships, `https://docsyncwp.com/privacy-policy` exists and matches `readme.txt` disclosure.
- [ ] Uninstall behavior is unchanged unless explicitly intended.
- [ ] No untracked files will be included in the ZIP unless intended.

## Changelog Process

Every pull request must add a file under `changelog/` named `YYYYMMDD-brief-description.md`. Example:

```markdown
Significance: minor
Type: added

Add Layout Preset registry with 2 Gutenberg presets.
```

Valid significance values: `patch`, `minor`, `major`.
Valid type values: `added`, `changed`, `deprecated`, `removed`, `fixed`, `security`.

The release workflow aggregates these files into `readme.txt` and the GitHub release notes, then deletes the `changelog/` directory for the release commit.

## Beta Testing

Each minor and major release must go through a beta period:

1. Create a GitHub pre-release with a beta tag, e.g., `1.1.0-beta.1`.
2. Recruit 5-10 beta testers from the target niche (Elementor agencies or news publishers).
3. Provide a test plan with the release candidate and a feedback deadline.
4. Collect and triage feedback in a dedicated issue or project board.
5. Ship the final release only after the beta exits with no critical blockers.

Patch releases do not require a beta period but should be tested on a staging site before tagging.

## WordPress.org Release Steps

1. **Prepare the release branch** from the target branch, e.g., `release/1.1.0`.
2. **Run the release checklist** (see above).
3. **Update `readme.txt`** with the stable tag and changelog.
4. **Create a GitHub Release** with the tag, e.g., `1.1.0`. The `Build Release ZIP` workflow will run automatically and attach the installable ZIP.
5. **Download the release ZIP** and verify it contains the expected files.
6. **Upload to WordPress.org SVN**:
   - Update `trunk/` with the release files.
   - Copy `trunk/` to `tags/1.1.0/`.
   - Update `readme.txt` stable tag to `1.1.0`.
   - Commit to SVN.
7. **Smoke test** the plugin from WordPress.org on a clean install within 24 hours.
8. **Announce** the release in the changelog, blog post, newsletter, and social channels as appropriate for the release size.

## Patch Release Steps

Patch releases follow a lighter path:

1. **Cherry-pick fixes** to a release branch or use the current release branch.
2. **Update version strings** to `1.0.x`.
3. **Add changelog entry** to `readme.txt`.
4. **Run CI checks**.
5. **Tag and release** on GitHub; the ZIP workflow attaches the artifact.
6. **Upload to SVN** `trunk/` and `tags/1.0.x/`.
7. **No beta required**, but a 24-hour staging smoke test is recommended.

## Rollback Procedure

If a release introduces a regression:

1. **Assess severity** within 2 hours of the report.
2. **For critical regressions** (data loss, security issue, fatal error):
   - Revert the SVN `readme.txt` stable tag to the previous version.
   - Optionally delete the SVN tag directory for the bad release.
   - Communicate the rollback to users via the plugin changelog and support channels.
3. **For non-critical regressions**:
   - Ship a patch release within 48 hours.
   - Do not change the stable tag; let the patch replace the bad release.
4. **Document the incident** in the project journal or a post-mortem file.

## Release Automation

The following automation should be in place to sustain the cadence:

- **GitHub release-drafter** or equivalent that aggregates `changelog/` files into release notes.
- **GitHub Actions workflow** that builds the release ZIP and attaches it to the GitHub Release.
- **CI checks** for JS lint, TypeScript, PHP lint, PHPCS, Plugin Check, and readme.txt validation.
- **SVN tagging script** to reduce manual upload errors.

## Optional Telemetry Worker

The optional anonymous active-install telemetry service is a separate Cloudflare Worker package under `cloudflare/telemetry-worker/`. It is not part of the installable WordPress plugin ZIP.

Deployment checklist:

- Create a Cloudflare D1 database for telemetry.
- Update `cloudflare/telemetry-worker/wrangler.toml` with the production D1 database ID.
- Apply D1 migrations from `cloudflare/telemetry-worker/migrations/`.
- Set `ADMIN_TOKEN` as a Worker secret; do not commit it.
- Deploy the Worker at `https://telemetry.brasth.com`.
- Confirm `GET /health` returns `{ "ok": true }`.
- Confirm `GET /v1/summary?window=30d` requires `Authorization: Bearer <ADMIN_TOKEN>`.
- Confirm the daily scheduled Worker cleanup deletes rows older than 90 days.

Before publishing a WordPress.org release that includes telemetry, verify the privacy policy at `https://docsyncwp.com/privacy-policy` describes the telemetry endpoint, opt-in/default-off behavior, payload, non-storage of IP/user-agent/request headers, and 90-day retention.

## Related Documents

- `docs/project-roadmap.md` — phased release plan and feature schedule
- `docs/system-architecture.md` — architecture and sync flow
- `docs/codebase-summary.md` — module inventory and build setup
- `docs/project-overview-pdr.md` — product scope and completion criteria
