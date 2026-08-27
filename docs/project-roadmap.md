# DocSync-WP Development Roadmap

Last updated: 2026-08-27

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

- Version **1.1.5** is the published release: folder watches, folder-first Setup, and honest member re-sync. WordPress.org still shows fewer than 10 active installs and zero reviews. Growth research (2026-08-27) says distribution + a thin Drive Folders ops pass beat new platform features. See `docs/research/2026-08-27-growth-elementor-automation-ux.md`.
- Version 1.1.3 shipped usability polish and the role-aware admin workspace. 1.1.4 added PHP 8.1 activate hardening plus Drive folder automation. Do not treat 1.1.4 as the next release.
- Core sync engine is stable: Google export, media import, Gutenberg block conversion, Elementor JSON conversion, background sync, and sync logging.
- The plugin supports one-way sync from Google Docs to WordPress posts, pages, and enabled public custom post types.
- Setup now separates administrator-owned site configuration from personal Google access; capability-qualified operators continue activation in Sources without receiving settings secrets. Activation is an accessible successfully completed source, not account readiness.
- Ready users open the shared Doc source flow directly from Setup or Sources. Sources is the health-first operational home, ordered by attention, syncing, then healthy records with stable URL filters and pagination.
- The Gutenberg sync path has Clean Article, Documentation, and legacy Plain Blocks; Elementor sync has separate Elementor Hero Page and Elementor Feature Block presets while legacy Elementor sources without a preset keep the existing converter.
- Self-managed Google OAuth is the only connection mode. There is no Pro tier, no managed connector, and no custom preset builder.

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
| **1.0.8** | Released | First-sync setup checklist, translation infrastructure, action-oriented empty states, screenshot refresh follow-up | Smoother onboarding |
| **1.0.9** | Released | Security audit fixes, nonce hardening, role-capability review, productized Brasth admin shell, task-first setup, compact source actions, searchable Sync Activity logs, Radix-confirmed safe log clearing | Hardened plugin with clearer onboarding and troubleshooting |

### 1.1.x line — Layout foundation: backend

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.1.0** | Released | Layout preset registry (`LayoutBlueprint`, `LayoutPresetRegistry`, `ContentRoleClassifier`, `LayoutConversionService`), 2 enhanced Gutenberg presets (`Clean Article`, `Documentation`), legacy `Plain Blocks`, site default setting, Setup dropdown, per-source/post selectors, layout fingerprints | Site admin sets a default layout; all synced posts look better |
| **1.1.1** | Released | Layout preset reliability patch, clearer preset copy, tracked edge-case fixtures, CI fixture verification | Recommended update with more predictable preset output |
| **1.1.2** | Released | 2 Elementor presets (`Elementor Hero Page`, `Elementor Feature Block`) | Elementor users get layouts too |
| **1.1.3** | Released | Elementor usability polish plus role-aware activation workspace, direct first-source flow, safe workspace bootstrap, source health ordering, and explicit owner transfer | Users reach a safely synced draft, then operate from Sources with the right responsibility and recovery context |

### 1.1.x line — Release hardening

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.1.4** | Released | PHP 8.1 activate fix plus Drive folder automation (watch a folder, inventory Docs, optional subfolders, draft/publish policy, per-folder schedule). Release-pipeline `php -l` on PHP 8.1. | Operators choose a Doc or a Drive folder; PHP 8.1 sites can activate |
| **1.1.5** | Released | Folder-first Setup, honest member re-sync on the folder schedule, cron continuation past the 20-source batch | Agency operator can start a client folder from Setup and trust the cadence |

**Mandatory 1.1.4 release gates:**

- validate normal and large-document sync, media import, Gutenberg presets, Elementor presets and legacy Elementor paths, scheduled-sync recovery, owner transfer, role isolation, OAuth callback/token recovery, REST nonce/capability failures, and unchanged-source skips on staging;
- run the full validation suite on the release path, including PHP 8.1 support, Plugin Check, official `readme.txt` validation, and a clean ZIP install smoke test;
- complete documented internal staging validation using real Google accounts, representative documents, and representative cron configurations; 1.1.4 publishes publicly after these gates pass;
- reconcile the published artifact, Git tag, and WordPress.org/SVN history for 1.1.2 before publishing another release;
- ship only fixes proven by validation or beta feedback. No gallery, preview, bulk import, new telemetry, schema changes, or new background-job behavior.

### 1.2.x line — Layout foundation: UI (conditional)

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.2.0** | After 1.1.4 evidence gate | Accessible preset gallery in the existing source modal; reuse existing preset registry and source payloads | End users understand layout choices when linking a Doc |
| **1.2.1** | Follow-up only if needed | Accessibility fixes and copy refinement from beta evidence | Stable, accessible layout selection |
| **1.2.2** | Deferred; explicit preview go/no-go required | Read-only preview only if gallery evidence proves it is needed | Users see output before syncing |
| **1.2.3** | After preview decision | Preset selector polish and usage hints based on observed workflow friction | Layout choice is clearer during repeat sync work |

`1.2.0` is not a new wizard. The existing modal already resolves source, output type, and preset. The gallery must be frontend-only over the current preset registry: no REST routes, post meta, Google API calls, WP-Cron work, or global block pattern registration. It must preserve site-default Gutenberg selection, legacy Elementor conversion, owner-transfer confirmation, and all existing source flows.

Preview is not assumed. It may proceed only when real-user evidence shows the gallery cannot make preset choices intelligible, and only when it is a bounded non-mutating path with conversion parity, rate limits, capability checks, cache/invalidation rules, timeouts, cleanup, and no document-content retention.

### 1.3.x line — Agency scale: bulk import

| Version | Target Date | Scope | User-visible value |
|---|---|---|---|
| **1.3.0** | After bulk-operability proof | Bulk Drive folder import with durable jobs, recovery, progress/history, quota-aware retries, and documented real-server-cron requirement; free/Pro boundary decided before public scope | Agencies import an archive safely |
| **1.3.1** | Production findings | Fixes and operational tuning for proven bulk-import failures | Reliable bulk import |
| **1.3.2** | After stable import operation | Import templates: save folder + preset combinations for reuse | Repeatable client workflows |

Bulk import cannot rely on traffic-driven WP-Cron alone. Before 1.3.0, prove durable/resumable job state, per-document idempotency, global and per-owner concurrency limits, quota-aware exponential backoff, partial-failure recovery, cancellation, cleanup, and operator-visible queue/progress/error state. Validate the performance target against representative normal, image-heavy, fallback, permission, and rate-limit cases with real server cron.

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
| **Now** | After 1.1.5 | Distribution + thin Automation ops | Listing/screenshots/reviews; Drive Folders attention queue; Elementor 3/4 compatibility QA, not Atomic rewrite |
| **Next** | Conditional 1.2.0 | Layout selection clarity | Bounded accessible preset gallery if evidence shows current selector friction |
| **Later** | 1.2.2 - 1.4.3 | Preview, agency scale, and monetization | Preview only with parity/operability proof; bulk import only with durable-job and commercial gates |
| **Future** | 1.5.0 - 2.2.0 | Writer discovery, smart sync, expansion | Add-on, AI, managed OAuth, Notion, team workflows |

## Success Metrics by Version

| Version | Primary Metric | Target |
|---|---|---|
| 1.0.6 | Admin first-paint improvement | 20% faster |
| 1.0.8 | First synced draft path | Visible from Setup without reading README |
| 1.0.9 | Security audit + productized admin UI | 0 critical, 0 high; branded shell, task-first setup, compact source actions, and searchable Sync Activity shipped |
| 1.1.0 | 2 Gutenberg presets pass golden tests | 100% on 5 fixtures |
| 1.1.1 | Layout reliability fixture suite | 100% on tracked Clean Article, Documentation, Plain Blocks, and source override fixtures |
| 1.1.2 | 4 total publishable presets pass golden tests | 100% on Gutenberg and Elementor fixtures |
| 1.1.3 | Publishable output and first-source activation | 0 developer edits for fixture set; explicit output choice visible; first successful draft reachable directly; role-safe workspace contract verified |
| 1.1.4 | Release and sync reliability | Zero P0/P1 internal-validation defects; all mandatory release gates pass; documented internal results reviewed |
| 1.2.0 | Layout-selection clarity | At least 70-80% of beta users select an intended preset unaided; no added Google or cron work |
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

- [ ] Reconcile GitHub, WordPress.org/SVN, and changelog history for every published version, including 1.1.2.
- [ ] `changelog/` directory where every PR adds a markdown file.
- [ ] GitHub release-drafter workflow that aggregates changelog files into `readme.txt` and release notes.
- [ ] CI pipeline runs Plugin Check, readme.txt validator, PHP 8.1 compatibility, clean-install ZIP smoke tests, and the existing PHP/JS fixture suites before a release is published.
- [ ] Beta channel using GitHub pre-releases, with 5-10 agency volunteers testing each 1.x.0 release.
- [ ] Automated ZIP build and release asset upload on GitHub Release publish.
- [ ] WordPress.org SVN tagging script or workflow.

## Open Decisions

1. **Primary niche:** Elementor agencies vs. news publishers. Validate with the 1.1.4 beta before 1.2.0 scope and before 1.3.0 case studies.
2. **Pricing model:** per-site vs. unlimited-agency vs. usage-based. Decide before defining a public bulk-import limit or writing any Pro feature gate.
3. **Managed OAuth commitment:** build in-house vs. partner with an existing OAuth provider. Decide before 1.7.0.
4. **AI provider:** Google Gemini vs. OpenAI vs. local heuristics only. Decide before 1.6.0.
5. **Global block pattern registration:** opt-in per preset or site-wide setting. Decide before 1.6.x.

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Recent feature velocity masks release regressions | High | Feature-freeze 1.1.4; enforce staging, beta, clean-install, and release-path checks before another feature release |
| Assumed preset-selector friction is not the real activation blocker | Medium | Validate OAuth, sync reliability, and real-user task completion before approving a gallery or preview |
| Release cadence creates regression fatigue | Medium | Each minor release has a dedicated beta period; patch releases are limited in scope |
| Preview diverges from actual sync or exposes Doc content | High | Defer until a non-mutating, parity-tested, capability-safe conversion boundary exists |
| Bulk import overloads WP-Cron, quotas, or media processing | High | Require durable jobs, real server cron, throttling, idempotency, recovery, and observability before public release |
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
- `docs/research/2026-08-27-growth-elementor-automation-ux.md` — post-1.1.5 growth, Elementor 4.0, Automation dashboard, admin UX
