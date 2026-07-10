# Brasth Admin Design Guidelines

Last updated: 2026-07-10

## Purpose

Use this guide for Brasth Document Sync admin UI work. The product direction is premium utilitarian WordPress admin: compact, trustworthy, fast to scan, and polished without marketing-style decoration.

## Brand Direction

- Industrial and utilitarian, with Brasth warmth from restrained teal accents.
- Prefer clear task hierarchy over ornament.
- Keep the WordPress admin context visible. Do not build landing-page heroes, decorative card stacks, gradient backgrounds, or nested cards for admin workflows.
- Product identity should appear through the Brasth mark, navy/blue/teal roles, precise spacing, and consistent states.

## Tokens

- Navy is for identity and strong anchors.
- Blue is for primary actions, progress, and information.
- Teal is for selected, connected, and complete workflow states.
- Warning, error, success, info, syncing, and skipped states must use semantic tokens, not ad hoc colors.
- Surface roles:
  - Page background: quiet off-white green-gray.
  - Card background: white.
  - Raised surface: dialogs, toasts, command consoles.
  - Subtle surface: filters, empty panels, disclosure bodies, status blocks.
- Use `--docsync-radius-sm`, `--docsync-radius-md`, and `--docsync-radius-pill`.
- Use `--docsync-motion-fast`, `--docsync-motion-normal`, and `--docsync-motion-ease` for CSS motion.

## Typography

- Use the WordPress/system admin stack; do not load webfonts.
- Headings should be balanced and compact. Avoid hero-scale type inside panels.
- Body copy should wrap cleanly and stay short. Put procedural detail in disclosures.
- Use tabular numbers for counts, progress, dates, and summary metrics.
- Use uppercase/kicker labels sparingly for orientation, not decoration.

## Layout

- Use a 16px rhythm for page sections and 12px rhythm inside compact panels.
- Default card radius is 8px. Small controls may use 6px. Pills use full radius.
- Do not put cards inside cards. Use sections, disclosure rows, or subtle surfaces inside panels.
- Tables should preserve hierarchy: primary entity first, supporting metadata second, status/progress third, actions last.
- Command rows should start with search or the main filter, then narrow filters, with actions on the right on desktop and stacked on mobile.
- On mobile, primary task content should come before secondary progress rails or account/sidebar panels.

## Components

- Buttons keep WordPress button classes and use `AdminButton` for React surfaces.
- Primary buttons are reserved for the next or main action. Secondary buttons are for inspection, refresh, and navigation.
- Destructive actions require confirmation and must be visually subordinate until the confirmation dialog.
- Cards are for one coherent workflow or repeated records. Use ring/shadow tokens for depth.
- Tables use sticky headers only inside scroll containers and must have hover, selected, error, and warning row states.
- Pills must include a dot, status-specific color, and reduced-motion behavior for active states.
- Progress bars use tabular percent labels and should appear only for active sync states.
- Skeletons and loading states should keep layout stable.
- Empty states must distinguish no data, filtered empty, loading, and recovery paths.
- Notices and toasts use semantic colors, visible action areas, and reduced-motion progress.

## Modal And Drive Browser

- The Doc Source modal is one workflow: choose source, confirm document, choose output type when needed, choose layout, attach.
- Header order: product label, action title, concise risk statement.
- Browse, URL, and File ID modes use tabs with clear active and focus states.
- Drive toolbar order: location, search, search action, refresh, clear search.
- Breadcrumbs must wrap without pushing primary controls off screen.
- Selected Drive docs need a strong row rail and a confirmation panel before footer actions.
- Disabled/download-blocked docs must explain why inline.
- Footer primary action stays rightmost.
- Output type radio cards must make WordPress Blocks vs Elementor Layout obvious without adding a separate wizard step.
- Legacy Elementor upgrade notices should be visible but not alarmist; offer Feature Block, Hero Page, and keep-legacy actions.

## Setup Workspace

- Make the next action dominant.
- Keep checklist/progress visible but visually quiet.
- Group credential import, redirect URI copy, Google Cloud links, connection test, and sync defaults by job.
- Dirty/saved state belongs near the panel it affects.
- Long setup instructions belong in disclosures.

## Sources And Logs

- Sources filters act as a command console: search first, post type/status next, actions last.
- Sources rows show target title, Google Doc, status/progress, last sync, then actions.
- Logs filters use a simple default row and advanced filters in a disclosure.
- Log summaries appear only when entries exist and use tabular numbers.
- Warning/error log rows use a left rail plus hover tint.
- Auto-refresh is an accessible switch with a reduced-motion indicator.

## Post Sync Surfaces

- Metabox action order: Link or Change source, Sync now, Detach.
- Busy and disabled copy should be visible near the action.
- List-table actions must keep a native WordPress footprint.
- Toasts should be concise, semantic, and actionable.

## Motion And Accessibility

- Use CSS-only micro-interactions, 150-250ms.
- Do not use `transition: all`.
- Always support `prefers-reduced-motion`.
- Focus states must be visible on buttons, tabs, breadcrumbs, filters, row actions, dialog controls, and switches.
- Target 40-44px hit areas where practical inside WordPress admin density.
- Modals, tabs, tables, and switches must remain keyboard-safe and screen-reader understandable.

## Design Lab Process

- Use targeted design labs only when a surface needs comparison before implementation.
- Produce five variants: information hierarchy, layout model, density variation, interaction model, expressive Brasth direction.
- Use existing tokens and WordPress admin constraints.
- Include feedback overlay if creating a runnable lab.
- Do not start a long-running dev server from the agent.
- Delete `.claude-design/` and temporary lab routes/files after finalizing.

## Current Decisions

- No new runtime dependencies for admin polish.
- No GSAP in plugin admin.
- Keep Radix Dialog/Tabs for complex modal/tab behavior.
- Keep Vite screen-specific bundles and lazy Drive browser/modal styles.
- Keep standalone image output editor-native when possible so users can select and edit images normally in Gutenberg.
- Preserve REST, settings, post meta, and Vite entry contracts.
