# Background Sync Runner and Recovery Design

## Status

Approved by the requester on 2026-07-11.

## Problem

The local WordPress container is publicly served at `http://localhost:8890`, while Apache listens on port 80 inside the container. WP-Cron calls the configured site URL, so its loopback request cannot reach port 8890. A source is queued successfully but its job remains due and never runs.

The UI stores queued work as `syncing` with a `queued` step. Existing polling presents that state as active sync work, which makes a blocked queue look like a broken progress display. A due event that stays pending also prevents stale recovery indefinitely.

## Options considered

1. Change the local site URL to port 80. Rejected: browser access and Google OAuth redirect URLs require port 8890.
2. Rely on WP-Cron loopback and document the limitation. Rejected: it leaves the normal local workflow non-functional.
3. Add a development-only WP-CLI cron runner, retain the public site URL, and make queue/recovery states explicit. Chosen: it is isolated to the dev stack and mirrors production's recommended real-cron model.

## Design

### Development runtime

Add a `cron` Compose service based on the existing WordPress image. It shares the WordPress and plugin volumes, waits for the database, and repeatedly runs due WordPress cron events via WP-CLI. It does not expose a port or change production packaging.

### Sync recovery

Treat a matching source event as evidence of pending work only while it is not overdue beyond the stale threshold. Once the queue heartbeat is stale, recovery must record a safe, actionable error even if the event remains in WordPress's cron store.

### UI state

Keep the existing `syncing` storage status for compatibility. Use `syncStep === 'queued'` in the presentation layer to show **Sync queued** and an indeterminate progress indicator; show percentage progress only after work starts. The existing long-running timeout remains a secondary safeguard.

## Verification

- Compose config includes the isolated cron worker.
- A queued source event is consumed by the worker in the local stack.
- A deliberately overdue queued event becomes a safe stale-sync error.
- Queue and active progress render distinct labels and indicators.
- PHP lint, frontend lint/typecheck/build, runtime verification, and diff checks pass.

## Scope boundaries

- No changes to production OAuth callback URLs or public site URLs.
- No new database tables, queues, or external services.
- No changes to production sync output, ownership, or Google permissions.
