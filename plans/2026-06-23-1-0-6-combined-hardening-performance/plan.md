---
title: "1.0.6 Combined Hardening and Performance"
description: "Ship the closed 1.0.5 roadmap hardening scope together with 1.0.6 performance work as a single 1.0.6 patch release."
status: completed
priority: P1
effort: 12h
branch: feature/improve-6.5-compatibility-accessibility-audit-fixes
tags: [bugfix, frontend, wordpress, performance, accessibility]
created: 2026-06-23
---

# 1.0.6 Combined Hardening and Performance

## Summary

- Treat `1.0.5` as closed and released.
- Release the former roadmap `1.0.5` hardening scope and `1.0.6` performance scope together as `1.0.6`.
- Keep public REST routes and stored metadata unchanged.
- Reduce first-load admin payload by splitting admin screens and lazy-loading the Drive browser.

## Implementation

- Update release docs and metadata to `1.0.6`.
- Split the shared admin bundle into Setup, Sources, and Logs Vite entries with screen-specific CSS.
- Keep post-sync separate, but remove Drive browser code and CSS from its initial bundle.
- Add a lazy Drive browser IIFE bundle exposed through `window.DocSyncWPDriveBrowserBundle`.
- Expose Drive browser script and stylesheet URLs through `window.DocSyncWPAdmin`.
- Remove unused `wp-data` dependency from post-sync enqueue.
- Fix accessibility findings discovered during the implementation pass.

## Feedback Triage

| Source | Repro | Expected | Actual | Severity | Status |
|---|---|---|---|---|---|
| 1.0.4/1.0.5 feedback list unavailable | N/A | Confirmed user feedback gets fixed in 1.0.6 | No concrete ticket list found in repo | P2 | Defaulting to compatibility, a11y, and performance findings |

## Verification

- `composer validate --no-check-publish`
- `composer lint`
- `vendor/bin/phpcs -i`
- `pnpm lint`
- `pnpm typecheck`
- `pnpm build`
- Confirm build manifests exist for Setup, Sources, Logs, Post Sync, and Drive Browser.
- Compare raw JS+CSS payloads against baselines:
  - admin baseline: `68,217` bytes
  - post-sync baseline: `97,901` bytes

Measured after implementation:

| Entry | Raw initial JS+CSS | Baseline | Result |
|---|---:|---:|---|
| Setup | `35,110` bytes | `68,217` bytes | 48.5% smaller |
| Sources | `32,079` bytes | `68,217` bytes | 53.0% smaller |
| Logs | `28,905` bytes | `68,217` bytes | 57.6% smaller |
| Post Sync | `77,670` bytes | `97,901` bytes | 20.7% smaller |

Lazy assets:

- Source modal styles: `3,344` bytes CSS, `82` bytes JS stub
- Drive browser: `13,283` bytes JS, `7,767` bytes CSS

## Manual QA

- Test WP 6.4 minimum, WP 6.5 target, and latest local WordPress if available.
- Test activation, setup save, OAuth connect/disconnect, Drive browse, advanced URL/file ID linking, source sync, sync all, logs filters, post editor, post list actions, Elementor enabled/disabled.
- Test normal Doc, image Doc, large Doc fallback, and blocked-download Doc.
- Run keyboard-only pass for modal focus, Escape close, tab order, live announcements, filters, tables, progress, and focus return.
