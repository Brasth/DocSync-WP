# Post Editor Sync Inspector Design

## Status

Approved by the requester on 2026-07-11.

## Problem

The post editor sidebar presents source metadata, three equal full-width actions, and layout settings as one long form. It makes a routine sync operation look destructive and causes the inspector to feel heavy at its normal narrow width.

## Approved design

- Separate the sidebar into **Source**, **Actions**, and **Sync settings** sections without introducing a new panel framework.
- Show the linked Google Doc title with a compact status pill and a subdued, tabular last-sync timestamp.
- Make **Sync now** the only full-width primary action.
- Keep **Change Doc** as a compact secondary action and **Detach** as a quiet destructive text action on the same maintenance row.
- Keep layout and Elementor settings below a subtle section boundary.
- Clamp long source titles and make the native preset select resilient to narrow inspector widths.
- Preserve current permissions, sync behavior, confirmation flows, progress polling, and keyboard access.

## Verification

- Check linked, syncing, error, and unlinked source states.
- Verify the inspector at narrow and standard Gutenberg sidebar widths.
- Run frontend lint, typecheck, and build.
