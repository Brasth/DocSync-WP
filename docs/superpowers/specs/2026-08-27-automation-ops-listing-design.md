# 1.1.6 Automation ops + listing — design

Date: 2026-08-27  
Status: approved (user chose C)  
Related: `docs/research/2026-08-27-growth-elementor-automation-ux.md`

## Goal

Agencies can see which client folders need work on Drive Folders, and the WordPress.org listing leads with that outcome. No new Google API. No Elementor Atomic rewrite. No Pro.

## Surfaces

### Drive Folders list

- Health tiles are filters: All folders, Watching, Importing, Needs attention.
- Needs attention = `error` OR `paused` OR `failed.length > 0` OR non-empty `lastError`.
- Attention queue above the table when any watch needs attention (hidden when the active filter is Watching).
- Status pills tell the truth: Watching, Importing, Paused, Error. Never map `watching`→Synced or `paused`→Linked.
- Command row: search, then status filter, primary **Watch a client folder** on the right.
- Row primary action: Resume (paused), Fix (error/failed), Manage (importing), Scan (watching). Overflow keeps Pause/Remove/View posts.
- Folder detail title is not a second `<h1>`.
- Loading skeleton talks about folder watches, not linked sources.

### Admin chrome

- Sources, Drive Folders, and Sync Activity use a compact masthead (title + status, no version stack).
- Setup keeps the full first-run masthead.
- Submenu **Logs** becomes **Sync Activity**.

### Sources folder teaser

- Drop product-explainer copy.
- Show failed/importing/paused first.
- Link still goes to Drive Folders.

### Review prompt

- On Drive Folders only, after at least one watch has `importedCount >= 1`.
- Dismiss forever via `localStorage` key `docsync-wp-review-prompt-v1`.
- One line + WordPress.org review link + Dismiss. No activate nag.

### Listing

- Short description and tags lead with folder automation + Elementor.
- Screenshot 1 becomes the Drive Folders ops screen. Captions 2–5 stay mapped to existing files.

### Elementor

- Add a live QA checklist. No new preset. No converter change.

## Out of scope

Unified Automation home, Atomic JSON, custom presets, Docs add-on, managed OAuth, raising 50-Doc cap, compact-chrome restyle of Setup.
