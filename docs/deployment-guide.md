# DocSync-WP Deployment and Release Guide

Last updated: 2026-07-12

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
- [ ] A release commit contains the final version and changelog. Do not modify it after validation.
- [ ] Run **Validate Release Commit** manually with that commit's full lowercase 40-character SHA.
- [ ] The workflow passes all PHP 8.1 checks, fixtures, frontend checks, official readme validation, ZIP checks, clean ZIP install/runtime smoke, and Plugin Check.
- [ ] Record the successful workflow URL, validated SHA, and uploaded artifact checksum.
- [ ] `pnpm lint` passes.
- [ ] `pnpm typecheck` passes.
- [ ] `pnpm build` produces a clean `build/` directory.
- [ ] PHP validation passes in CI:
  - `composer validate --no-check-publish`
  - `composer lint`
  - `composer test:layout-fixtures`
  - `composer test:elementor-fixtures`
  - `composer test:large-doc-fallback-fixtures`
  - `composer test:telemetry-settings`
  - `vendor/bin/phpcs -i`
- [ ] Plugin Check (WordPress.org) passes in CI or locally.
- [ ] readme.txt validator passes.
- [ ] PHP compatibility check passes for declared minimum version (8.1) and current supported versions.
- [ ] No secrets, credentials, OAuth client JSON, `.env.local`, `.secrets/`, local DB dumps, or API keys in the diff.
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

For 1.1.4, the agreed internal validation matrix replaces an external beta. Record its results in `plans/reports/architecture-decision-20260712-internal-release-validation.md` before tagging.

## Local WordPress Runtime

The repository includes a disposable devcontainer runtime for release smoke tests before staging:

- WordPress URL: `http://localhost:8890`
- Admin: `admin / password`
- Plugin setup URL: `http://localhost:8890/wp-admin/admin.php?page=brasth-document-sync-for-google-docs`
- OAuth callback URL: `http://localhost:8890/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback`

On container startup, `postCreateCommand` installs Composer and pnpm dependencies and builds assets. `postStartCommand` runs `.devcontainer/scripts/bootstrap-wordpress.sh` and `.devcontainer/scripts/verify-runtime.sh` to install WordPress, activate the plugin, verify required PHP extensions, confirm route registration, and validate the local callback URL.

Use it for smoke checks only. Do not bake OAuth credentials into the image and do not commit downloaded `client_secret*.json` files.

## WordPress.org Release Steps

1. **Prepare the release branch** from the target branch, e.g., `release/1.1.4`.
2. **Create and push the final release commit** with matching plugin header, `DOCSYNC_WP_VERSION`, `readme.txt` stable tag, and changelog entry.
3. **Run Validate Release Commit** from Actions with that exact full commit SHA. Review the workflow's ZIP, checksum, and commit-provenance artifacts.
4. **Run the internal staging matrix** recorded in `plans/reports/architecture-decision-20260712-internal-release-validation.md` and record the evidence against the same commit.
5. **Create the annotated tag on the validated SHA and publish the GitHub Release immediately.** Do not use a draft release and do not retag a different commit. This immediately triggers ZIP attachment and WordPress.org deployment.
6. **Confirm release automation** finishes successfully: the ZIP is attached to GitHub and the WordPress.org SVN deployment completes.
7. **Smoke test** the WordPress.org installation on a clean site within 24 hours.
8. **Announce** the release in the changelog, blog post, newsletter, and social channels as appropriate for the release size.

Post-publication workflows cannot prevent an already published release. The manual pre-tag validation is therefore mandatory. Restrict release branch pushes and release-tag creation to maintainers who follow this sequence.

## Patch Release Steps

Patch releases follow a lighter path:

1. **Cherry-pick fixes** to a release branch or use the current release branch.
2. **Create and push the final version and changelog commit**.
3. **Run Validate Release Commit** against the exact commit SHA.
4. **Tag that validated SHA and publish immediately**; the ZIP and WordPress.org deployment workflows run automatically.
5. **Confirm automation succeeds** and run a 24-hour staging smoke test.

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
