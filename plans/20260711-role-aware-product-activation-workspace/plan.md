---
title: "Role-Aware Product Activation Workspace"
description: "Turn setup into a capability-aware path from site connection to a first successfully synced WordPress draft, then make Sources the daily home."
status: in_progress
priority: P1
effort: 68h
branch: main
tags: [activation, setup, sources, roles, oauth, ux]
created: 2026-07-11
---

# Role-Aware Product Activation Workspace

## Goal

Serve agencies and in-house editorial teams through one capability-aware workflow. Activation ends when an accessible Google Doc is successfully synced into a WordPress draft; account readiness alone is not completion.

## Approved Product Direction

- Keep one product and one shared source model; do not ask users to choose a persona.
- Separate site-wide configuration from the current user's Google connection.
- Derive journey state from settings, account, and accessible source records; persist no wizard state.
- Open the existing Doc source workflow directly when connections are ready.
- Make Sources the post-activation operational home.
- Preserve self-managed OAuth, one-way sync, server authorization, and opt-in telemetry.
- Add no runtime dependency or storage migration.

## Product Gates

These decisions must close in Phase 01 before dependent behavior ships:

1. **Source-owner handoff:** explicit transfer is recommended; another editor must not silently replace the scheduled-sync identity.
2. **Editor readiness summary:** show site-ready/not-ready plus safe publishing defaults; never expose credentials, scheduling, or telemetry configuration.
3. **Landing behavior:** keep Setup URL and submenu; top-level plugin route resolves to Setup for unconfigured admins and Sources for activated/operational users.
4. **Telemetry consent:** activation telemetry remains local-only in this release; any remote funnel payload requires a separately approved, versioned consent change.

## Phase Overview

| Phase | Effort | Status | File |
| --- | ---: | --- | --- |
| 01 Product gates and capability baseline | 8h | Complete | [phase-01-product-gates-and-capability-baseline.md](phase-01-product-gates-and-capability-baseline.md) |
| 02 Operational workspace contract | 12h | Implementation complete; runtime QA pending | [phase-02-operational-workspace-contract.md](phase-02-operational-workspace-contract.md) |
| 03 Role-aware activation workspace | 16h | Implementation complete; manual a11y/responsive QA pending | [phase-03-role-aware-activation-workspace.md](phase-03-role-aware-activation-workspace.md) |
| 04 First-source completion flow | 12h | Implementation complete; runtime E2E pending | [phase-04-first-source-completion-flow.md](phase-04-first-source-completion-flow.md) |
| 05 Sources health home and routing | 12h | Implementation complete; runtime compatibility QA pending | [phase-05-sources-health-home-and-routing.md](phase-05-sources-health-home-and-routing.md) |
| 06 Reliability, accessibility, and release validation | 8h | In progress; sync-toast lifecycle polish implemented, runtime/manual QA pending | [phase-06-reliability-accessibility-and-release-validation.md](phase-06-reliability-accessibility-and-release-validation.md) |

## Architecture Summary

Add an authenticated, least-privilege operational workspace response for role-safe readiness and accessible-source summaries. Keep `/settings` admin-only. Frontend pure state maps site readiness, current-user account, and source health to one advisor action. Reuse `DocSourceModal`, source polling, existing meta, Radix primitives, design tokens, and screen-specific Vite bundles.

## Success Criteria

- Admins and editors understand who owns each setup responsibility.
- Editors cannot receive or mutate site-wide OAuth configuration.
- Ready users choose a Doc without detouring through Posts.
- First successful or unchanged-complete sync establishes activation.
- Activated users reach Sources, with attention states ordered first.
- Existing sources, URLs, sync output, permissions, and telemetry consent remain compatible.

## Validation Status

Implementation and automated verification are complete. Composer validation/lint, all tracked PHP fixture suites, telemetry settings verification, frontend lint/typecheck/build, touched/new PHP syntax checks, and `git diff --check` pass. The build emits exactly the six expected manifests: Setup, Sources, Logs, Post Sync, Doc Source Modal, and Drive Browser, each with one JavaScript entry and `style.css`. Final code review scored 9.6/10 with zero critical findings.

Release acceptance remains open because the WordPress service was unavailable: port 8890 returned HTTP 000 and only the database and Adminer services were running. Runtime role/nonce/cross-post isolation, end-to-end activation/recovery, and browser keyboard/screen-reader/reduced-motion checks at 375/768/1440 were not executed. Overall status remains `in_progress` until those release-validation items pass.

The local background-sync reliability patch now adds a development-only WP-CLI cron worker, retains the browser and OAuth URL at port 8890, presents queued work separately from active sync progress, and recovers a stale scheduled event into a retryable error. It does not close release acceptance.

The approved sync-toast lifecycle polish is implemented in the shared `SyncToastStack`: every toast now uses a content row plus a separate full-width, padded progress row. The queued state renders `Sync queued`, `Waiting for the background worker.`, and an indeterminate bar without repeated status copy; active work renders its milestone with determinate progress. Success, warning, and error retain the same frame without a progress row. The existing dismiss control and narrow-screen layout remain in that component and its stylesheet. Browser visual QA for this polish is still pending, so it does not close release acceptance.

## References

- [Approved design](../../docs/plans/2026-07-11-role-aware-product-activation-design.md)
- [Architecture research](research/researcher-01-architecture-report.md)
- [Product UX research](research/researcher-02-product-ux-report.md)

## Unresolved Questions

- When can the WordPress devcontainer be started to complete runtime, role-isolation, end-to-end, accessibility, and responsive acceptance?
