# Research: Growth, Elementor, Automation Dashboard, Admin UX

Date: 2026-08-27  
Status: research for product decision (no implementation)  
Scope: How DocSync-WP grows from <10 installs; whether to chase Elementor 4.0; why Drive Folders is not an automation dashboard; why the admin UI reads as vibe-coded.

Related: `docs/project-roadmap.md`, `plans/reports/research-20260622-growth-strategy-references.md`, `plans/reports/research-20260622-competitive-strategy-references.md`, `docs/plans/2026-08-23-agency-automation-setup-design.md`, `docs/design-guidelines.md`

## Executive answers

1. **Growth is not a feature gap.** Version 1.1.5 already ships the June 2026 wedge (folder watch + layout presets + Elementor). WordPress.org still shows **fewer than 10 active installs** and **zero reviews**. More product surface will not create demand. Distribution, first-review, and one hero screenshot of a live client-folder run will.
2. **Do not chase Elementor 4.0 Atomic output now.** Keep v3 `heading` / `text-editor` / `image` / `html` / `divider` plus container-or-section wrapping. That JSON still works on Elementor 4.0 hybrid sites. Add a compatibility matrix and fixture against latest Elementor. Rewrite to `e-heading` + `$$type` props only when agencies on new Atomic-default sites report broken or uneditable layouts.
3. **The Automation dashboard is not a dashboard.** `Drive Folders` is a CRUD table with four dead count tiles. Agencies need an attention queue: next scan, stalled cron, failed Docs, import progress, one primary next action per watch.
4. **The UI is designed, not native.** Custom masthead, tokens, pills, and progress rails look like a generated SaaS shell inside `wp-admin`. Guidelines already say utilitarian WordPress admin. The fix is density, native list-table grammar, and fewer product-chrome layers — not another visual system.

## Current product reality

| Signal | Fact |
|---|---|
| Version | 1.1.5 (`brasth-document-sync-for-google-docs.php`, `readme.txt`) |
| WordPress.org | Listed; last modified 2026-08-23; **<10 active installs**; **0 reviews**; no listing screenshots in-repo (`assets/` has banner + icon only) |
| Site | [docsyncwp.com](https://docsyncwp.com/) explains setup, presets, Drive Folders |
| Core loop | Self-managed Google OAuth → Drive browser or folder watch → HTML ZIP import → Gutenberg or Elementor presets → WP-Cron |
| Agency increment | Folder-first Setup (`Watch a client folder` / `Choose one Google Doc`); watch interval now drives discovery **and** member re-sync |
| Caps | 10 watches / 50 Docs / depth 3 (later agency plan) |
| Monetization | All free. No Pro. No managed OAuth |
| Roadmap docs | `docs/project-roadmap.md` still says 1.1.3 current / 1.1.4 next. Stale |

Shipped differentiators vs June 2026 research:

- Gutenberg presets: Clean Article, Documentation, Plain Blocks
- Elementor presets: Hero Page, Feature Block + legacy converter
- Drive folder watches with edit/pause/scan/inventory
- Role-aware Setup / Sources / Sync Activity
- Large-doc Docs API fallback

The June 22 research said: ship presets, then folder import, then a Docs add-on. **Presets and folder watches are in.** The remaining gap is users, not the engine.

## Competitive landscape (2026-08)

| Product | Position | Installs / proof | Weak vs DocSync | Strong vs DocSync |
|---|---|---|---|---|
| **GoPublish** | Docs add-on first. "One-click publish. No plugin needed." | WP.org **100+**, 2 five-star reviews, Marketplace + G2 4.8, claims 10k+ users | No native Elementor layout, no WordPress-side folder watch, hosted SaaS, 10 exports/month on free | Lives where writers write; SEO meta + Yoast/Rank Math; Sheets bulk; two-way sync; screenshots show the result |
| **WordPress.com for Google Docs** | Official Automattic add-on | Brand trust | .com / Jetpack only | Zero Google Cloud setup |
| **Yoast Google Docs** | SEO workflow, Pro hook | Huge install base | Not a layout/sync product | Discovery inside an SEO plugin |
| **DocSync-WP** | Self-hosted sync + layouts + folder automation | **<10**, 0 reviews | Elementor JSON, Media Library, scheduled folder re-sync, no SaaS middleman | Setup tax (BYO Google Cloud), no writer-side add-on, no SEO fields, UI looks like an internal tool |

Implication unchanged from June: do not compete on "publish from Docs." Compete on **"client Drive folder → publishable WordPress layout, on a schedule, on self-hosted WP."**

GoPublish FAQ says page builders are "compatible" because it writes post content. That is not Elementor layout conversion. Keep saying that, but only after people can find and finish Setup.

## How to grow (now)

The first 1,000 installs do not come from WordPress.org search. Plugins with empty reviews and `<10` installs lose directory ranking to anything with social proof. Growth work, in order:

### 1. Make the listing look like a finished product

- Tags today: `google-docs, google-drive, content-sync, editorial-workflow, blocks`. Missing the searches people actually use: `elementor`, `google-docs-to-wordpress` style phrases, content automation. GoPublish already owns `content automation` / `google docs` / `wordpress publishing`.
- Short description is accurate and generic. Lead with the agency outcome: watch a Drive folder, land drafts, keep them on a schedule, optional Elementor layout.
- Listing screenshots should show **result first**: a client folder with imported/pending/failed counts, then a synced Elementor/Gutenberg draft, then Setup. Settings last. Repo has no screenshot set.
- Ask for a review after first successful folder import, not on activate.

### 2. Own one persona for 90 days

Still the open roadmap decision: Elementor agencies vs news publishers. At `<10` installs, pick **Elementor / content agencies** because:

- Folder watch is already the product
- Elementor presets are the only unique output vs GoPublish
- Agency buyers can become case studies and the first 5 reviews

News publishers can wait. Solo bloggers will bounce on Google Cloud OAuth.

### 3. Manual distribution, not more modules

- 3 free folder migrations for agencies in exchange for a review + named case study
- One long-tail guide: "Watch a Google Drive folder into WordPress (Elementor or blocks)"
- Founder answers on WP.org / agency Slack / Facebook groups where people still paste from Docs
- Keep shipping small patches so "last updated" stays fresh. That is a ranking signal.

### 4. Do not spend the next release on Pro, AI, Notion, or a Docs add-on

Those are later-roadmap items. They do not move `<10 → 100`. A Docs add-on without reviews and without a trusted folder-ops UI is a worse GoPublish.

OAuth setup remains the activation tax. Managed OAuth is still a compliance/revenue decision, not a growth hack, until there is revenue.

## Should we follow Elementor updates?

**Compatibility: yes. Feature-chase: no.**

### What we emit today

`src/Sync/Elementor/` writes **Elementor 3.x widget JSON**:

- Widgets: `heading`, `text-editor`, `image`, `html`, `divider` (`WidgetFactory`)
- Layout: Flexbox `container` when the container experiment / post data says so; else legacy `section` + `column` (`LayoutBuilder`, `CompatibilityChecker`)
- Presets: Hero Page, Feature Block; blueprint `schemaVersion` is `'3'`
- Detection: `class_exists('\Elementor\Plugin')`, `_elementor_edit_mode`, `experiments->is_feature_active('container')`
- No Atomic Editor sniff. No `e-heading` / `e-paragraph`. No `$$type` props. No Pro widgets (correct)

Fixtures prove JSON shape, not live Elementor 4 editor editability.

### What Elementor 4.0 changed (2026)

- Atomic Editor is **default for new installs** (April 2026 rollout; developer update 2026-03-19)
- Existing sites stay on v3 unless the admin enables Atomic
- v3 widgets and Atomic elements **coexist on the same page**
- Atomic JSON is a different schema: `widgetType: e-heading`, settings wrapped in `{ "$$type": "...", "value": ... }`, styles in a separate `styles` map
- Atomic still missing many v3 widgets (accordions, galleries, carousels). Rich text is weaker. Agencies will run hybrid for a long time

### Fragility

| Change | Risk to DocSync |
|---|---|
| Elementor 3.x → 4.0 on **existing** agency sites | Low. v3 JSON remains valid |
| New sites with Atomic default | Medium. Synced v3 widgets still render; they look "legacy" in the panel and may not use Classes/Variables |
| Container experiment removal | Medium. We already default to containers |
| Settings/control key drift on `heading` / `text-editor` / `image` | Medium. Needs a live Elementor smoke, not more goldens |
| Cache / CSS file regeneration | Already handled in `PostUpdater`; keep testing |
| Pro widget temptation | High product risk. Do not emit Pro-only widgets |
| Full Atomic preset rewrite | High cost. New factory, new styles map, new fixtures, dual output forever |

### Recommendation

| Do now | Do later | Do not do |
|---|---|---|
| QA latest Elementor 3.32/4.x with both Atomic off and on | Optional `Elementor Atomic Article` preset when 3+ agencies ask | Rewrite both presets to Atomic as a growth bet |
| Record Elementor version in Sync Activity (already have output path) | Map heading/text to `e-heading`/`e-paragraph` behind a source flag | Bricks/Divi/Oxygen in the same train |
| Hide or label "legacy-looking" output only if Atomic-on sites complain | Partner / kit export if agencies need brand tokens | Pro Forms, Interactions, Components |

Elementor remains a **positioning** advantage, not the growth engine. A second builder is dilution at this install count.

## Automation dashboard audit

Surfaces today:

| Screen | Job | What it actually is |
|---|---|---|
| Setup | Site OAuth + first folder/Doc | Task rail + credential forms. After ready, still a setup workspace |
| Sources | Daily source health | Health tiles + optional folder strip + source table |
| Drive Folders | Folder watches | Four count tiles + search + status filter + table |
| Folder detail | Edit one watch | The only page that feels operational |
| Sync Activity | Diagnostics | Event table. Not an ops home |

`FolderWatchesView` tiles: Watches, Watching, Importing, Needs attention. None are clickable. Filter labels disagree with tiles (`watching` option label is **Synced**; tile says **Watching**). Search is client-side only.

Missing for an agency ops dashboard:

- Attention queue sorted by failed / stalled / importing, not folder name
- Next scan **and** next member re-sync as first-class, trusted copy
- Failed-Doc inbox with retry, not a count buried in `3 imported · 0 pending · 1 failed`
- Per-client identity (today: Drive folder name + owner + post type tags)
- Throughput: last successful scan, last cron tick, docs waiting on the 50-cap
- One primary action per row (Scan now **or** Fix failures), overflow for Pause/Remove
- Empty state is decent; populated state is a spreadsheet

`SourcesFolderWatches` copy still explains the product ("Setup can start folder automation. Manage watches here.") instead of showing what is on fire.

Design already written in `docs/plans/2026-08-23-agency-automation-setup-design.md`: success is "this client folder is on rails." The list page does not show rails. The detail page almost does.

## UI / UX: does it look vibe-coded?

Yes, in the way people mean that joke: **a custom product chrome generated on top of WordPress, consistent with itself, foreign to `wp-admin`.**

Not amateur. Tokens, Radix dialogs, skeletons, confirmations, and `docs/design-guidelines.md` are real. The vibe is "SaaS dashboard dropped into admin," which the guidelines already forbid (`landing-page heroes`, `decorative card stacks`, `nested cards`).

### What reads as generated

1. **Masthead on every screen** (`AdminShell`): logo, product name, screen title, version, status chip. WordPress already has the admin header and submenu. This is a second product.
2. **Count tiles that are not filters.** Sources health and Drive Folders health look like a dashboard and behave like decoration.
3. **Noun soup.** Watch / folder / source / member Doc / client folder / Synced vs Watching vs Healthy. Operators cannot form a mental model.
4. **Setup never becomes small.** After activation it is still a branded workspace with rail + side panels (account, telemetry, defaults). Maintenance should collapse to a short settings screen.
5. **Four homes.** Setup, Sources, Drive Folders, Sync Activity split one job: "is publishing on rails?"
6. **Custom page background and card radius** (`--docsync-page-bg`, 8px cards, teal/navy roles) fight `wp-admin` chrome. Fine for a modal. Heavy for list screens.
7. **"Create issue" footer** on every page. Good for us, odd for an agency first-run.
8. **Row action overflow** (Scan / Pause / Resume / Remove / Edit) plus a second "Watch another folder" toolbar. Native WP would be row hover actions + one primary button.

### What is already good

- Task-first Setup next action
- Folder empty state copy
- Destructive confirms
- Cron stall banner
- Detail page header with imported / pending / failed
- Screen-split Vite bundles, no webfonts, reduced-motion tokens

### Design target

Premium utilitarian WordPress: WP list-table grammar, one attention strip, one primary action, Brasth mark only in the plugin menu / compact header, not a full masthead stack. Use existing tokens. Do not invent a new look.

## Three approaches

### A. Distribution first (recommended for growth)

Ship listing, screenshots, one agency guide, review-ask after first folder import. No new engine. Fastest path to 10–100 installs.

- Pros: matches the real bottleneck; cheap
- Cons: agencies who install still hit a weak ops screen

### B. Thin Automation ops on Drive Folders (recommended product slice)

Keep the screen. Make tiles into filters. Add an Attention list above the table (errors, paused, importing, cron stall). Per-row primary action + overflow. Align labels. Use this screen as the .org screenshot.

- Pros: small surface; proves "on rails"; listing asset
- Cons: Sources / Logs still overlap

### C. Chase Elementor 4 Atomic + full UI redesign

New Atomic factory + new visual system + unified Automation home.

- Pros: future-proof, prettier demo
- Cons: dual schema forever; no users to validate; highest vibe-code risk

**Recommendation:** A + a thin B in the same release. C is later.

## Proposed next release (if approved)

Working name: **1.1.6 Automation ops + listing**. No new Google API. No Pro. No Atomic rewrite.

1. Drive Folders attention queue + clickable health + noun/label cleanup
2. Compact admin chrome on operational screens (keep full Setup masthead only during first-run)
3. WordPress.org screenshot set + tag/short-description rewrite
4. Elementor 3.x/4.x compatibility checklist in QA (not a new preset)
5. In-plugin review prompt after first healthy folder import (WP.org guidelines: not nagging)

Out of scope: custom preset builder, Docs add-on, managed OAuth, Bricks/Divi, raising 50-Doc cap, CPT watch storage.

## Unresolved questions

1. Confirm primary persona for 90 days: Elementor agencies vs news publishers.
2. Will the team do 3 manual agency migrations, or stay product-led only?
3. Is 1.1.6 allowed to change admin chrome, or listing-only?
4. Any live Elementor 4 Atomic-default site already using the plugin?
5. Pricing still deferred — keep all-free through the first 100 installs?
