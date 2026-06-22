# DocSync-WP Development Roadmap

Last updated: 2026-06-22

## Strategic Direction

DocSync-WP evolves from a "Google Docs -> WordPress sync" utility into a **content-publishing workflow** that turns Google Docs into publishable WordPress layouts without developer intervention.

The short-term goal is to remove the layout bottleneck that forces content authors to ask developers for help after every sync. The medium-term goal is to make the plugin the default choice for agencies and publishers who produce recurring content from Google Docs. The long-term goal is to become the publishing bridge between any content source and WordPress.

## Current State

- Version 1.0.3 is released on WordPress.org.
- Core sync engine is stable: Google export, media import, Gutenberg block conversion, Elementor JSON conversion, background sync, and sync logging.
- The plugin supports one-way sync from Google Docs to WordPress posts, pages, and enabled public custom post types.
- The editor decision layer (`Elementor\SyncDecider`) is in place, but the conversion is purely structural: every document becomes the same flat layout.
- Self-managed Google OAuth is the only connection mode. There is no Pro tier, no managed connector, and no layout template system.

## Strategic Themes

1. **Layout-aware output** — end users should not need a developer to make a synced post look publishable.
2. **Agency scale** — agencies should be able to migrate and manage content at volume.
3. **Writer discovery** — the plugin should be visible where content writers actually write (Google Docs), not only inside WordPress admin.
4. **Smart sync** — AI and heuristics should reduce manual choices, not add new tools.
5. **Platform expansion** — beyond Google Docs when the core workflow is proven.

## Roadmap Phases

### Phase 1: Layout Foundation (1.1.0, Q3 2026)

**Theme:** Layout-aware output.

**Goal:** A synced Google Doc can be rendered with a publishable layout using built-in presets, without changing the existing default behavior.

**Scope:**
- Introduce a `LayoutPreset` domain (`src/Sync/Layout/`):
  - `LayoutBlueprint` interface
  - `LayoutPresetRegistry` for built-in and site-defined presets
  - `ContentRoleClassifier` for heuristic role detection (`hero`, `body`, `cover`, `callout`, `feature_list`, `code_block`, `cta`, `divider`)
- Ship 4 built-in presets:
  - `Clean Article` (Gutenberg) — title + body + image
  - `Documentation` (Gutenberg) — title + description + step list + code block
  - `Elementor Hero Page` (Elementor) — heading + text-editor + image + divider
  - `Elementor Feature Block` (Elementor) — heading + icon-list + CTA spacer
- Add site-level default preset setting and per-post `_docsync_wp_layout_preset` meta override.
- Extend the source modal with a wizard step 3: **Layout Preset Gallery**.
- Add a read-only preview endpoint so users can see the converted output before syncing.
- Expose the preset selector in the post sync meta box.
- Golden tests: fixed Doc HTML fixtures produce fixed block/Elementor JSON for each preset.

**Out of scope:**
- Custom preset builder UI (moved to Phase 2 / Pro)
- Global block pattern registration (deferred to Phase 4)
- AI-assisted preset selection (deferred to Phase 4)

**Success metrics:**
- 4 built-in presets pass golden tests on 5 representative Doc fixtures each.
- Manual QA on a staging site produces publishable output for each preset without developer edits.
- No regression in the default (no-preset) sync path.

### Phase 2: Agency Scale (1.2.0, Q4 2026)

**Theme:** Agency scale and monetization.

**Goal:** Agencies can migrate content in bulk and customize presets for clients; the plugin introduces a clear Pro tier.

**Scope:**
- **Bulk Drive folder import**: import an entire Google Drive folder of Docs into WordPress posts, applying the site default preset, in the background.
- **Freemium feature gating**: introduce Free/Pro tiers using Freemius or equivalent.
  - Free: core sync, 4 built-in presets, media import, scheduled sync.
  - Pro: custom preset builder, bulk import beyond a free limit, advanced sync settings, priority support.
- **Custom preset builder** (admin UI): site admins can create and edit custom layout presets via JSON or a visual form.
- **SEO metadata integration**: exported posts can carry suggested titles, excerpts, and meta descriptions compatible with Yoast / Rank Math hooks.
- Build 3 agency case studies with testimonials.
- Apply to at least one hosting/agency partner program (WP Engine, Kinsta, Cloudways, Automattic for Agencies).

**Out of scope:**
- Google Docs Add-on (Phase 3)
- Managed OAuth service (Phase 4)

**Success metrics:**
- 3 agency case studies published.
- Pro tier generates first paid customers.
- Bulk import tested with a folder of 50+ Docs.

### Phase 3: Writer Discovery (1.3.0, Q1 2027)

**Theme:** Be visible where writers work.

**Goal:** Content writers can publish and manage their Google Docs from inside Google Docs, using the same layout presets the site admin configured.

**Scope:**
- **Google Docs Workspace Add-on** built with Google Apps Script:
  - Sidebar showing linked WordPress post(s) and sync status
  - Layout preset picker
  - "Publish to WordPress" / "Update WordPress" actions
  - Read-only preview rendered in the sidebar
- **WordPress REST API authentication** via WordPress application passwords (no extra plugin required).
- Add-on respects Free/Pro tier: basic publish is free, preset picker and preview are Pro.
- Marketplace listing optimization and review.

**Out of scope:**
- Managed OAuth (Phase 4)
- Two-way sync (Phase 5)

**Success metrics:**
- Add-on approved in Google Workspace Marketplace.
- Add-on usage correlates with higher sync frequency and lower developer-intervention requests.
- Plugin active installs reach 1,000+.

### Phase 4: Smart Sync (1.4.0, Q2 2027)

**Theme:** Reduce manual decisions with invisible intelligence.

**Goal:** The plugin chooses the right preset, suggests metadata, and keeps layouts updated with less manual input.

**Scope:**
- **Invisible AI features**:
  - Auto-preset selection based on Doc structure (heuristic + optional Gemini/LLM call)
  - Auto-generated excerpt from Doc summary
  - Alt-text suggestions for imported images
  - SEO title and meta description suggestions
- **Managed OAuth connector service** (optional Pro/SaaS): a Brasth-hosted OAuth app that removes the Google Cloud setup step. The service brokers tokens but never proxies document content.
- **Synced patterns integration**: built-in presets can optionally register as WordPress block patterns, so layout updates propagate to all posts using a preset.

**Out of scope:**
- AI content generation (do not build an AI writer)
- Notion / Confluence support (Phase 5)

**Success metrics:**
- Auto-preset selection accuracy measured against manually labeled fixture set (target: 80%+ top-1 accuracy).
- Managed OAuth reduces setup abandonment rate by 30%+.
- Synced patterns reduce manual layout maintenance for agencies.

### Phase 5: Platform Expansion (2.0.0, Q3 2027+)

**Theme:** Beyond Google Docs.

**Goal:** DocSync-WP becomes the publishing bridge for multiple content sources and advanced workflows.

**Scope:**
- **Additional sources**:
  - Notion pages and databases
  - Confluence pages
  - Dropbox Paper
  - Markdown file imports (local or cloud storage)
- **Two-way sync** (experimental): selected WordPress edits can be pushed back to the source document.
- **Team workflows**:
  - Approval queues before publishing
  - Scheduled publication from Google Docs
  - Multi-author attribution mapping
- **Enterprise features**:
  - SSO/SAML for team OAuth
  - Audit logs and compliance exports
  - Multi-site license management

**Success metrics:**
- At least one new source (Notion) reaches beta.
- Enterprise/team tier launched.
- 5,000+ active installs.

## Now / Next / Later

| Horizon | Focus | Key Deliverables |
|---|---|---|
| **Now** (Q3 2026) | Layout Foundation | 4 built-in presets, wizard step 3, preview endpoint, post meta selector, golden tests |
| **Next** (Q4 2026) | Agency Scale | Bulk import, Pro tier, custom preset builder, 3 agency case studies |
| **Later** (Q1 2027+) | Writer Discovery + Smart Sync | Google Docs Add-on, invisible AI, managed OAuth, synced patterns |
| **Future** (Q3 2027+) | Platform Expansion | Notion/Confluence, two-way sync, team workflows, enterprise tier |

## Success Metrics by Phase

| Phase | Primary Metric | Target |
|---|---|---|
| 1.1.0 | Preset golden tests pass | 100% of 4 presets on 5 fixtures |
| 1.1.0 | Manual QA publishable output | 0 developer edits needed for fixture set |
| 1.2.0 | Agency case studies | 3 published |
| 1.2.0 | First Pro revenue | 10 paid customers |
| 1.3.0 | Active installs | 1,000+ |
| 1.3.0 | Add-on marketplace approval | Listed |
| 1.4.0 | Auto-preset top-1 accuracy | 80%+ |
| 2.0.0 | Active installs | 5,000+ |
| 2.0.0 | New source beta | 1 (Notion) |

## Open Decisions

1. **Primary niche:** Elementor agencies vs. news publishers. Validate before Phase 2 case studies.
2. **Pricing model:** per-site vs. unlimited-agency vs. usage-based. Decide before Phase 2 Pro tier.
3. **Managed OAuth commitment:** build in-house vs. partner with an existing OAuth provider. Decide before Phase 4.
4. **AI provider:** Google Gemini (natural fit with Google Docs) vs. OpenAI vs. local heuristics only. Decide before Phase 4.
5. **Global block pattern registration:** opt-in per preset or site-wide setting. Decide before Phase 4.

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Preset heuristics misclassify content | Medium | Default no-preset path remains unchanged; per-post override is easy |
| Elementor Pro widget drift | Medium | Hide Pro-only presets on sites without Pro; validate against latest Elementor in QA |
| Google Workspace Marketplace rejection | Medium | Start review early; follow UI/UX and OAuth scope guidelines strictly |
| Managed OAuth compliance burden | High | Defer until revenue model supports it; service never proxies content |
| Phase 2 Pro tier too generous or too limited | Medium | Define free/Pro boundary before gating code is written |
| Feature scope creep into AI writer | High | Explicitly exclude generative AI from roadmap; only invisible AI is allowed |

## Related Documents

- `docs/project-overview-pdr.md` — product scope and current completion criteria
- `docs/system-architecture.md` — current architecture and sync flow
- `docs/codebase-summary.md` — current module inventory
- `plans/reports/research-20260622-layout-wizard-references.md` — prior art for layout presets
- `plans/reports/research-20260622-growth-strategy-references.md` — competitive and go-to-market research
- `plans/reports/research-20260622-competitive-strategy-references.md` — how to beat competitors and reach users
