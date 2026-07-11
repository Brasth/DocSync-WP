---
title: "Role-Aware Product Activation Workspace"
description: "Turn setup into a capability-aware path from site connection to a first successfully synced WordPress draft, then make Sources the daily home."
status: pending
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
| 01 Product gates and capability baseline | 8h | Pending | [phase-01-product-gates-and-capability-baseline.md](phase-01-product-gates-and-capability-baseline.md) |
| 02 Operational workspace contract | 12h | Pending | [phase-02-operational-workspace-contract.md](phase-02-operational-workspace-contract.md) |
| 03 Role-aware activation workspace | 16h | Pending | [phase-03-role-aware-activation-workspace.md](phase-03-role-aware-activation-workspace.md) |
| 04 First-source completion flow | 12h | Pending | [phase-04-first-source-completion-flow.md](phase-04-first-source-completion-flow.md) |
| 05 Sources health home and routing | 12h | Pending | [phase-05-sources-health-home-and-routing.md](phase-05-sources-health-home-and-routing.md) |
| 06 Reliability, accessibility, and release validation | 8h | Pending | [phase-06-reliability-accessibility-and-release-validation.md](phase-06-reliability-accessibility-and-release-validation.md) |

## Architecture Summary

Add an authenticated, least-privilege operational workspace response for role-safe readiness and accessible-source summaries. Keep `/settings` admin-only. Frontend pure state maps site readiness, current-user account, and source health to one advisor action. Reuse `DocSourceModal`, source polling, existing meta, Radix primitives, design tokens, and screen-specific Vite bundles.

## Success Criteria

- Admins and editors understand who owns each setup responsibility.
- Editors cannot receive or mutate site-wide OAuth configuration.
- Ready users choose a Doc without detouring through Posts.
- First successful or unchanged-complete sync establishes activation.
- Activated users reach Sources, with attention states ordered first.
- Existing sources, URLs, sync output, permissions, and telemetry consent remain compatible.

## References

- [Approved design](../../docs/plans/2026-07-11-role-aware-product-activation-design.md)
- [Architecture research](research/researcher-01-architecture-report.md)
- [Product UX research](research/researcher-02-product-ux-report.md)

## Unresolved Questions

- None beyond the four Phase 01 product gates; dependent phases must record their approved outcomes.
