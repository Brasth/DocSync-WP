# Agency Automation Setup Implementation Plan

> Canonical plan lives in the repo plan folder. This file is the Superpowers path alias.

**Canonical files:**

- Design: [docs/plans/2026-08-23-agency-automation-setup-design.md](../../plans/2026-08-23-agency-automation-setup-design.md)
- Plan: [plans/260823-agency-automation-setup/plan.md](../../../plans/260823-agency-automation-setup/plan.md)
- Phase 1: [plans/260823-agency-automation-setup/phase-01-honest-folder-schedule.md](../../../plans/260823-agency-automation-setup/phase-01-honest-folder-schedule.md)
- Phase 2: [plans/260823-agency-automation-setup/phase-02-agency-setup-onboarding.md](../../../plans/260823-agency-automation-setup/phase-02-agency-setup-onboarding.md)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement the canonical plan task-by-task.

**Goal:** Agencies can start a client-folder automation from Setup and that folder schedule actually re-syncs member Docs.

**Architecture:** `SourceScheduleResolver` + `_docsync_wp_next_sync_at` + SyncCron continuation, then folder-first Setup activation.

**Tech Stack:** PHP 8.1, WordPress 6.4+, WP-Cron, existing REST and React admin.
