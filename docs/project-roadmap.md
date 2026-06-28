# DocSync-WP Development Roadmap

Last updated: 2026-06-28

## Strategic Direction

DocSync-WP evolves from a "Google Docs -> WordPress sync" utility into a **content-publishing workflow** that turns Google Docs into publishable WordPress layouts without developer intervention.

The short-term goal is to remove the layout bottleneck that forces content authors to ask developers for help after every sync. The medium-term goal is to make the plugin the default choice for agencies and publishers who produce recurring content from Google Docs. The long-term goal is to become the publishing bridge between any content source and WordPress.

## Release Cadence Principles

The roadmap is broken into **small, frequent, independently shippable releases**. Each release is a complete, usable unit of value, not a fragment of a larger feature.

- **Patch releases (1.0.x):** every 2-4 weeks. Bug fixes, hardening, compatibility updates, and small visible improvements. These keep the plugin visible in the WordPress.org "recently updated" list without heavy feature work.
- **Minor releases (1.x.0):** every 4-6 weeks. One complete feature per release. Each is a marketing event and a reason to publish a changelog entry or blog post.
- **Major releases (x.0.0):** every 6-12 months. Reserved for breaking changes or major new capability shifts, such as Notion support or two-way sync.

Each release must pass the existing validation pipeline (`pnpm lint`, `pnpm typecheck`, `pnpm build`, and PHP checks in CI) and be reviewable on WordPress.org within 1-7 days.

## Current State

- Version 1.0.8 is prepared in the codebase. Version 1.0.9 is the active security hardening and admin UX patch line pending staging QA.
- Core sync engine is stable: Google export, media import, Gutenberg block conversion, Elementor JSON conversion, background sync, and sync logging.
- The plugin supports one-way sync from Google Docs to WordPress posts, pages, and enabled public custom post types.
- Setup now guides users through one primary next action, and Logs now supports normal search, filters, recovery hints, and safe clear actions.
- The editor decision layer (`Elementor\SyncDecider`) is in place, but the conversion is purely structural: every document becomes the same flat layout.
- Self-managed Google OAuth is the only connection mode. There is no Pro tier, no managed connector, and no layout template system.

## Strategic Themes

1. **Layout-aware output** — end users should not need a developer to make a synced post look publishable.
2. **Agency scale** — agencies should be able to migrate and manage content at volume.
3. **Writer discovery** — the plugin should be visible where content writers actually write (Google Docs), not only inside WordPress admin.
4. **Smart sync** — AI and heuristics should reduce manual choices, not add new tools.
5. **Platform expansion** — beyond Google Docs when the core workflow is proven.

## Version-by-Version Release Plan

### 1.0.x line — Momentum and hardening

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.0.5** | Released | Elementor sync support, per-post Elementor toggle, native Elementor layout conversion, cache invalidation | Elementor users can sync Docs into native layouts |
| **1.0.6** | Released | Bug fixes from 1.0.4/1.0.5 feedback, WP 6.5 compatibility, accessibility audit fixes, lazy-load Drive browser, smaller admin bundles, faster first paint | Snappier and more compatible admin experience |
| **1.0.7** | Released | PHP 8.2/8.3 deprecation cleanup, dependency updates, security audit | Future-proof, broader hosting compatibility |
| **1.0.8** | Now | First-sync setup checklist, translation infrastructure, action-oriented empty states, screenshot refresh follow-up | Smoother onboarding |
| **1.0.9** | +6 weeks | Security audit fixes, nonce hardening, role-capability review, setup next-action guidance, searchable logs, safe log clearing | Hardened plugin with clearer onboarding and troubleshooting |

### 1.1.x line — Layout foundation: backend

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.1.0** | +10 weeks | Layout Preset registry (`LayoutBlueprint`, `LayoutPresetRegistry`, `ContentRoleClassifier`), 2 Gutenberg presets (`Clean Article`, `Documentation`), site default setting, `_docsync_wp_layout_preset` post meta | Site admin sets a default layout; all synced posts look better |
| **1.1.1** | +12 weeks | Regression fixes for default sync path, golden tests for 2 Gutenberg presets | Stable preset output |
| **1.1.2** | +14 weeks | 2 Elementor presets (`Elementor Hero Page`, `Elementor Feature Block`) | Elementor users get layouts too |
| **1.1.3** | +16 weeks | Golden test suite for all 4 presets, sync logging for preset usage | QA confidence and usage analytics |

### 1.2.x line — Layout foundation: UI

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.2.0** | +18 weeks | Wizard step 3 in `DocSourceModal` with preset gallery | End users pick a layout when linking a Doc |
| **1.2.1** | +20 weeks | Accessibility pass and bug fixes on the wizard | Stable, accessible wizard |
| **1.2.2** | +22 weeks | Read-only preview endpoint and preview UI inside the wizard | Users see output before syncing |
| **1.2.3** | +24 weeks | Preset selector in the post sync meta box | Per-post override without opening the wizard |

### 1.3.x line — Agency scale: bulk import

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.3.0** | +26 weeks | Bulk Drive folder import, free tier limited to 20 Docs per import | Agencies import an archive in one click |
| **1.3.1** | +28 weeks | Error recovery and bug fixes for bulk imports | Reliable bulk import |
| **1.3.2** | +30 weeks | Bulk import progress UI and history log | Users can track and retry |
| **1.3.3** | +32 weeks | Import templates: save folder + preset combinations for reuse | Repeatable client workflows |

### 1.4.x line — Pro tier launch

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.4.0** | +34 weeks | Freemius integration, license activation, in-plugin upsells | Pro tier exists |
| **1.4.1** | +36 weeks | Pro feature gates: unlimited bulk import, custom preset builder behind license | Pro features unlock |
| **1.4.2** | +38 weeks | Custom preset builder UI (JSON editor + visual form) | Agencies build brand presets |
| **1.4.3** | +40 weeks | Pro feature polish, analytics dashboard | Better admin experience |

### 1.5.x line — Writer discovery

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.5.0** | +42 weeks | Google Docs Workspace Add-on MVP: basic publish/update via WordPress application passwords | Writers publish from inside Google Docs |
| **1.5.1** | +44 weeks | Google Workspace Marketplace listing and approval | Add-on is discoverable |
| **1.5.2** | +46 weeks | Add-on preset picker and preview | Writers pick layouts from Google Docs |
| **1.5.3** | +48 weeks | Add-on sync status, history, and error display | Writers trust the tool |

### 1.6.x line — Smart sync

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.6.0** | +50 weeks | Heuristic auto-preset selection | Plugin picks the right preset automatically |
| **1.6.1** | +52 weeks | Auto-generated excerpt from Doc summary | Less manual post editing |
| **1.6.2** | +54 weeks | Alt-text suggestions for imported images | Better accessibility and SEO |
| **1.6.3** | +56 weeks | SEO title and meta description suggestions, Yoast/Rank Math compatible | Better search visibility |

### 1.7.x line — Managed OAuth (optional)

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.7.0** | +58 weeks | Managed OAuth service MVP, token broker only, no content proxy | One-click Google connect |
| **1.7.1** | +60 weeks | OAuth UI in plugin and add-on | No Google Cloud setup |
| **1.7.2** | +62 weeks | OAuth hardening, compliance docs, GDPR readiness | Enterprise-ready |

### 2.0.x line — Platform expansion

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **2.0.0** | +64 weeks | Notion source beta | Notion pages -> WordPress |
| **2.0.1** | +66 weeks | Notion bug fixes, database sync | Stable Notion integration |
| **2.1.0** | +68 weeks | Two-way sync experimental | WordPress edits can update the source |
| **2.2.0** | +70 weeks | Team workflows: approval queues, scheduled publishing, multi-author mapping | Editorial teams |

## Now / Next / Later

| Horizon | Versions | Focus | Key Deliverables |
|---|---|---|---|
| **Now** | 1.0.8 - 1.0.9 | Momentum and hardening | Onboarding polish, troubleshooting UX, screenshot refresh, security |
| **Next** | 1.1.0 - 1.2.3 | Layout foundation | Backend presets, wizard UI, preview, per-post override |
| **Later** | 1.3.0 - 1.4.3 | Agency scale and monetization | Bulk import, Pro tier, custom preset builder |
| **Future** | 1.5.0 - 2.2.0 | Writer discovery, smart sync, expansion | Add-on, AI, managed OAuth, Notion, team workflows |

## Success Metrics by Version

| Version | Primary Metric | Target |
|---|---|---|
| 1.0.6 | Admin first-paint improvement | 20% faster |
| 1.0.8 | First synced draft path | Visible from Setup without reading README |
| 1.0.9 | Security audit + support UX | 0 critical, 0 high; setup next action and searchable logs shipped |
| 1.1.0 | 2 Gutenberg presets pass golden tests | 100% on 5 fixtures |
| 1.1.2 | 4 total presets pass golden tests | 100% on 5 fixtures |
| 1.1.3 | Manual QA publishable output | 0 developer edits for fixture set |
| 1.2.0 | Wizard step completion rate | 80% of new drafts |
| 1.3.0 | Bulk import 50 Docs | 0 failures, <5 min |
| 1.4.0 | Pro license activations | 10 paid customers |
| 1.5.0 | Google Workspace Marketplace approval | Listed |
| 1.5.2 | Active installs | 1,000+ |
| 1.6.0 | Auto-preset top-1 accuracy | 80%+ |
| 1.7.0 | Setup abandonment reduction | 30%+ |
| 2.0.0 | Notion beta users | 50 active |
| 2.2.0 | Active installs | 5,000+ |

## Release Infrastructure Checklist

To sustain this cadence, the following must be in place. See `docs/deployment-guide.md` for the full release procedure.

- [ ] `changelog/` directory where every PR adds a markdown file.
- [ ] GitHub release-drafter workflow that aggregates changelog files into `readme.txt` and release notes.
- [ ] CI pipeline runs Plugin Check, readme.txt validator, and PHP compatibility checks.
- [ ] Beta channel using GitHub pre-releases, with 5-10 agency volunteers testing each 1.x.0 release.
- [ ] Automated ZIP build and release asset upload on GitHub Release publish.
- [ ] WordPress.org SVN tagging script or workflow.

## Open Decisions

1. **Primary niche:** Elementor agencies vs. news publishers. Validate before 1.3.0 case studies.
2. **Pricing model:** per-site vs. unlimited-agency vs. usage-based. Decide before 1.4.0.
3. **Managed OAuth commitment:** build in-house vs. partner with an existing OAuth provider. Decide before 1.7.0.
4. **AI provider:** Google Gemini vs. OpenAI vs. local heuristics only. Decide before 1.6.0.
5. **Global block pattern registration:** opt-in per preset or site-wide setting. Decide before 1.6.x.

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Release cadence creates regression fatigue | Medium | Each minor release has a dedicated beta period; patch releases are limited in scope |
| Preset heuristics misclassify content | Medium | Default no-preset path remains unchanged; per-post override is easy |
| Elementor Pro widget drift | Medium | Hide Pro-only presets on sites without Pro; validate against latest Elementor in QA |
| Google Workspace Marketplace rejection | Medium | Start review early; follow UI/UX and OAuth scope guidelines strictly |
| Managed OAuth compliance burden | High | Defer until revenue model supports it; service never proxies content |
| Pro tier too generous or too limited | Medium | Define free/Pro boundary before 1.4.0 gating code is written |
| Feature scope creep into AI writer | High | Explicitly exclude generative AI from roadmap; only invisible AI is allowed |

## Related Documents

- `docs/project-overview-pdr.md` — product scope and current completion criteria
- `docs/system-architecture.md` — current architecture and sync flow, including the future Layout Preset layer
- `docs/codebase-summary.md` — current module inventory and upcoming work
- `plans/reports/research-20260622-layout-wizard-references.md` — prior art for layout presets
- `plans/reports/research-20260622-growth-strategy-references.md` — competitive and go-to-market research
- `plans/reports/research-20260622-competitive-strategy-references.md` — how to beat competitors and reach users
