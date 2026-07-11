# Phase 04 First-Source Completion Flow

## Context links

- Parent: [plan.md](plan.md)
- Previous: [phase-03-role-aware-activation-workspace.md](phase-03-role-aware-activation-workspace.md)
- Sync architecture: [../../docs/system-architecture.md](../../docs/system-architecture.md)

## Overview

- Effort: 12h
- Priority: P1
- Status: Pending
- Outcome: let a ready user choose a Doc, watch safe background creation, recover failure, and open the synced draft without leaving activation context.

## Key Insights

- `DocSourceModal` already handles new drafts, Drive browsing, inspection, output/preset choice, queueing, and completion callbacks.
- `POST /sources` returns the post ID and source state; `GET /sources/{postId}` supports polling.
- Sync ownership affects future scheduled operations and must follow the approved handoff rule.

## Requirements

- Launch the existing modal from the administrator Setup CTA or operator Sources CTA with a safe enabled post type default.
- Preserve modal selection and form context after recoverable errors.
- Poll queued source state with existing progress behavior and stale recovery semantics.
- Define activation only after `synced` or `skipped` plus successful timestamp.
- Terminal failure must state what failed, content safety, who can fix it, and one action.
- Success offers `Open draft` as primary and `View all sources` as secondary.

## Architecture

Generalize modal invocation/callback only where Setup/Sources reuse requires it; keep one source-creation implementation. Store the returned post ID in ephemeral component state, poll with the existing progress hook/API, and derive the result from server source state. Do not persist activation or copy sync state into options.

## Related code files

- `resources/js/admin/features/doc-source-modal/doc-source-modal.tsx`
- `resources/js/admin/features/doc-source-modal/use-doc-source-modal.ts`
- `resources/js/admin/app/use-source-sync-progress.ts`
- `resources/js/admin/features/google-setup/google-setup-first-sync-step.tsx`
- `resources/js/admin/api/sources-api.ts`
- `src/Rest/SourceController.php`
- `src/Sync/SourceRepository.php`
- `src/Sync/SyncService.php`

## Implementation Steps

1. Expose a reusable activation modal trigger for Setup and Sources with enabled type/default preset context.
2. On create response, retain post ID and render queued/progress state without blocking navigation.
3. Poll until synced, skipped, error, or stale; announce terminal state politely.
4. Map error codes to safe user language and approved recovery ownership.
5. Apply explicit owner-transfer confirmation if a relink/recovery path changes operational identity.
6. Render success actions using returned `editUrl` and stable Sources URL.
7. Verify normal export, unchanged skip, automatic large-doc fallback, download block, token failure, and stale cron recovery.

## Todo list

- [ ] Reuse modal from the activation CTA.
- [ ] Preserve modal focus return and recoverable input state.
- [ ] Add first-source polling and activation result mapping.
- [ ] Add safe success and failure panels/actions.
- [ ] Implement the approved source-owner handoff interaction where applicable.
- [ ] Verify no duplicate source creation or concurrent sync regression.

## Success Criteria

- A ready user reaches Doc selection in one action from Setup.
- Successful background creation opens a real synced draft and marks activation.
- Failures do not falsely claim completion or hide content-safety behavior.
- Existing post/list-table modal flows remain unchanged.

## Risk Assessment

- Risk: duplicate modal state forks behavior. Mitigation: reuse hook/components and keep Setup-specific orchestration outside the modal.
- Risk: polling looks frozen. Mitigation: show honest milestone, last update, safe navigation, and stale recovery.

## Security Considerations

- Retain document access checks, enabled-type gates, target capabilities, nonce checks, and server-side ID validation.
- Do not expose Google file IDs, raw Google responses, tokens, or another user's identity in recovery UI.

## Next steps

Promote Sources into the post-activation home with attention-first health and role-aware empty states.

## Unresolved Questions

- Product gate: exact source-owner handoff confirmation and whether receiver acceptance is required.
- Resolved: reuse the modal on the current route; do not add a dedicated activation route unless focus/history testing proves it necessary.
