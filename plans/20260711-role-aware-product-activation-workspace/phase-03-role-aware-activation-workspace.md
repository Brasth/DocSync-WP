# Phase 03 Role-Aware Activation Workspace

## Context links

- Parent: [plan.md](plan.md)
- Previous: [phase-02-operational-workspace-contract.md](phase-02-operational-workspace-contract.md)
- Design guide: [../../docs/design-guidelines.md](../../docs/design-guidelines.md)

## Overview

- Effort: 16h
- Priority: P1
- Status: Implementation Complete; Manual Accessibility/Responsive QA Pending
- Outcome: replace generic setup completion with a capability-aware blueprint for administrators and a safe activation continuation in Sources for content operators.

## Key Insights

- The memorable model is responsibility, not decoration: Site connection → Your Google account → First source.
- Existing setup state is a good seam but currently treats account-ready as “first draft.”
- Editors need a waiting/continuation state in Sources without access to the administrator-only Setup surface.

## Requirements

- Create a pure `ActivationState`/advisor mapper for site needs admin, account disconnected, scope outdated, ready for source, syncing, activated, and needs attention.
- Show one blocker, evidence, primary action, and contextual help.
- Keep JSON import preferred; manual credentials, Cloud instructions, defaults, telemetry, and destructive reset progressively disclosed.
- Keep Setup administrator-only. Render site-wide and personal responsibilities there for administrators, and render only safe readiness/personal/first-source guidance in Sources for content operators.
- Preserve dirty form state, inline errors, keyboard order, live announcements, reduced motion, and mobile task-first order.

## Architecture

Compose workspace facts, current-user account state, admin settings when authorized, and source summary through `use-setup-app` and `use-sources-app`. Keep state mapping in a shared pure feature module with exhaustive typed cases. Recompose existing Setup and Sources components rather than introducing another route or design system. Use current tokens, `AdminButton`, WordPress controls, Radix disclosures/dialogs where already appropriate, and CSS-only transitions.

## Related code files

- `resources/js/admin/app/setup-app.tsx`
- `resources/js/admin/app/use-setup-app.ts`
- `resources/js/admin/app/sources-app.tsx`
- `resources/js/admin/app/use-sources-app.ts`
- `resources/js/admin/features/google-setup/google-setup-task-state.ts`
- `resources/js/admin/features/google-setup/google-setup-task-types.ts`
- `resources/js/admin/features/google-setup/settings-panel.tsx`
- `resources/js/admin/features/google-setup/account-panel.tsx`
- `resources/js/admin/features/google-setup/google-setup-active-task-panel.tsx`
- `resources/css/components/setup-settings.css`
- `resources/css/components/setup-panel.css`

## Implementation Steps

1. Define exhaustive advisor inputs, states, evidence, and permitted primary actions.
2. Add deterministic table verification with the existing toolchain; do not add a test runtime solely for this mapper.
3. Recompose Setup header as a semantic three-stage connection blueprint.
4. Keep the site lane and maintenance actions in Setup; add a safe readiness/personal/first-source continuation above Sources for operators.
5. Move secondary instructions/defaults/telemetry/destructive actions behind clear disclosures.
6. Add field-level and journey-level error placement plus polite announcements.
7. Validate long translations, long account email, loading/offline, 375/768/1440 widths, and reduced motion.

## Todo list

- [x] Add pure activation/advisor types and mapper.
- [x] Cover the typed advisor states with deterministic, exhaustive branch mapping.
- [x] Implement semantic blueprint and active task hierarchy.
- [x] Implement administrator Setup and operator Sources variants from the shared mapper.
- [x] Preserve credential import/manual/test/recovery behavior in the implementation.
- [x] Add responsive, focus, live-region, and reduced-motion styles.

## Validation Evidence

- Frontend lint, typecheck, and production build pass; static review found one primary advisor action per typed state.
- Focus-return, terminal-result focus, semantic headings, and reduced-motion rules are implemented and passed final static review.
- Browser validation for keyboard/screen-reader behavior, long content, reduced motion, and 375/768/1440 layouts remains open because WordPress was unavailable.

## Success Criteria

- Every supported state exposes exactly one primary next action.
- Editors cannot access Setup and never receive site secrets or global reset controls through Sources.
- Account readiness unlocks first-source selection but is not called activation.
- Admins understand site-wide versus personal ownership without documentation.

## Risk Assessment

- Risk: nested panels recreate clutter. Mitigation: one active task, quiet evidence rows, and disclosures instead of cards inside cards.
- Risk: client mapper drifts from backend facts. Mitigation: map only typed facts; unknown states fail to a safe refresh/support message.

## Security Considerations

- Conditional rendering never replaces REST permissions.
- Avoid placing imported OAuth secret values in announcements, logs, or error detail.
- External Google Cloud links require descriptive labels and safe target attributes.

## Next steps

Connect the ready-for-source advisor action to the existing Doc source modal and poll through first completion.

## Unresolved Questions

- Resolved: use WordPress/system typography and existing Brasth tokens; no webfont, new icon library, or runtime dependency.
- None in implementation. Editor waiting-state wording still requires browser/content acceptance during manual QA.
