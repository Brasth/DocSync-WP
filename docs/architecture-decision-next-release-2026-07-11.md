# Architecture Decision: Next Release After 1.1.3

Date: 2026-07-11  
Status: Accepted

## Decision

Ship **1.1.4** next as a hardening-only release. Do not start 1.2.0 gallery, preview, bulk import, or new product capability before its gates pass.

## Why

1.1.3 materially increased workflow risk: role-aware activation, source-owner transfer, background sync, Google OAuth, media import, Gutenberg/Elementor output, and large-document fallback now interact. Existing automated fixtures are necessary but do not prove real OAuth, WP-Cron, permission, Elementor, or agency-document behavior.

The assumption that a preset gallery is the next bottleneck is unproven. Setup friction, sync reliability, or Elementor compatibility may matter more. A preview requires a safe conversion path that matches sync without writing posts/media or retaining document content. Bulk import requires durable jobs and queue controls beyond traffic-driven WP-Cron.

## Options Evaluated

| Option | Complexity | Operational risk | Outcome |
|---|---:|---:|---|
| 1.1.4 hardening | Low | Low | Accepted |
| 1.2.0 preset gallery | Medium | Medium | Conditional after 1.1.4 evidence |
| Preview | High | High | Deferred until non-mutating parity path exists |
| Bulk import | Very high | Very high | Deferred until durable-job, cron, quota, and recovery proof exists |

## Acceptance Gates

- staging validation for normal/large Docs, media, Gutenberg, Elementor, legacy Elementor, cron recovery, owner transfer, OAuth/token recovery, permissions, and skips;
- enforced release checks: existing suite, PHP 8.1, Plugin Check, readme validation, clean-install ZIP smoke;
- 5-10 real target-niche beta users with actual Google accounts and documents;
- no P0/P1 defect or content-corruption report;
- reconcile 1.1.2 published artifact/tag/SVN history.

## Consequences

- Delays UI novelty by one validation cycle.
- Reduces chance of trust-damaging content or scheduling regressions.
- Produces evidence to decide whether 1.2.0 solves a real user problem.

## Next Decision

After 1.1.4 beta results, decide whether to ship a frontend-only preset gallery or address a different proven blocker.

## Unresolved Questions

- Which niche supplies the beta users: Elementor agencies or editorial publishers?
- Is 1.1.2 publicly released in GitHub and WordPress.org/SVN despite its missing Git tag?
- What is the Google OAuth verification/security-assessment status for customer deployments using `drive.readonly`?
