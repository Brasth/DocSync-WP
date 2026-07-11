# Sync Toast Lifecycle Design

## Status

Approved by the requester on 2026-07-11.

## Scope

Apply one consistent visual treatment to every sync lifecycle toast: queued, running, success, warning, and error. Source-selection modals and confirmation dialogs are out of scope.

## Problem

The queued toast repeats the same status in its title, message, and progress detail. Its progress bar is constrained to the text column, leaving an unbalanced empty area beside the close control.

## Approved design

- Use a two-row toast: a header/content row plus an optional progress row.
- The progress row spans the complete usable toast width below the header and message, with the toast padding retained at both sides.
- Queued state: title `Sync queued`; body `Waiting for the background worker.`; indeterminate bar; no duplicate detail label.
- Running state: title `Syncing Google Doc`; body contains the current milestone; determinate full-width progress bar with percentage detail.
- Success, warning, and error keep the same visual frame but omit the progress row when no work is active.
- Preserve the dismiss action, action buttons, screen-reader live regions, reduced-motion behavior, and narrow-screen layout.

## Verification

- Queued, running, success, warning, and error toast rendering has no duplicated status copy.
- Active progress spans the full padded toast width.
- Typecheck, lint, build, and visual inspection pass.
