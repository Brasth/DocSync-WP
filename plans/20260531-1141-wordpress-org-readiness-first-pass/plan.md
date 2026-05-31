# WordPress.org Readiness First Pass

## Status

Completed in code.

## Scope

This pass implements the production-readiness blockers that are tightly scoped and directly verifiable:

- add WordPress.org `readme.txt`
- add root `LICENSE`
- bump plugin/package metadata to `1.0.0`
- update `.distignore` so reviewer-facing frontend source and build config ship
- add privacy policy suggested content for Google OAuth, Drive, and Docs data handling
- remove legacy Google Picker settings from REST/admin config
- remove unused Markdown/CommonMark conversion code and dependency
- update project docs with what changed and what remains

## Out Of Scope

- full PHP class decomposition for every file over 200 lines
- new PHPUnit or JS test harness creation
- full admin UI i18n conversion
- generated WordPress.org screenshot artwork
- Plugin Check execution requiring a running WordPress admin or WP-CLI test site

## Verification

- `composer validate --no-check-publish`
- `composer lint`
- `pnpm lint`
- `pnpm typecheck`
- `pnpm build`
- targeted PHP syntax checks for modified PHP files
- `git diff --check`

All listed local checks passed on 2026-05-31. Official Plugin Check and readme validator still require a release test site or external validator.

## Follow-Ups

- add automated WordPress/PHPUnit and JS tests
- run official Plugin Check and readme validator in a release test site
- complete admin i18n pass
- create polished WordPress.org assets and screenshots
- split oversized classes by responsibility after behavior is covered by tests
