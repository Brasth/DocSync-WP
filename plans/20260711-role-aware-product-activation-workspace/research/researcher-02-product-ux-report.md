# Product UX Report: Role-Aware Activation Workspace

Date: 2026-07-11 — Scope: shared product core for agencies and in-house editorial teams

## Recommendation

Build one capability-aware journey around a single activation outcome: **a Google Doc becomes a successfully synced WordPress draft**. Do not create agency/editor editions or ask users to select a persona. Derive the experience from permissions and current state, while explaining the two responsibilities clearly:

1. **Site connection** — an administrator configures the site's Google OAuth client and publishing defaults.
2. **Personal connection** — each content operator authorizes their Google account and links readable Docs.

Setup is a temporary activation workspace. After activation, **Sources is the product home**.

## Personas And Jobs

| Context | Primary actor | Job to be done | UX priority |
|---|---|---|---|
| Agency | Implementer/site admin | Configure a client site safely and hand it over | Readiness evidence, clear ownership, repeatability |
| Agency | Client editor | Connect their account and publish without understanding OAuth | No exposure to admin-only tasks; guided first source |
| In-house team | WordPress admin | Establish a controlled Google-to-WordPress pipeline | Policies/defaults, account readiness, reliability |
| In-house team | Editor/author | Turn an approved Doc into a reviewable draft and refresh it | Fast source selection, visible sync state, recovery |

Shared jobs: know what blocks progress, know who can fix it, preserve WordPress content on failure, and verify that the source is healthy. Capability checks should govern controls; labels such as “agency” or “editorial team” should only inform optional onboarding copy, not authorization.

## First-Source Activation Flow

Use one derived journey state; do not persist a second wizard state.

| State | Primary message | Primary action | Completion evidence |
|---|---|---|---|
| Credentials absent | This site needs a Google OAuth web client | Import OAuth JSON | Client ID/secret saved |
| Redirect mismatch/import issue | Google Cloud needs this exact callback | Copy URI / open credentials | URI confirmed or test passes |
| Site ready, user disconnected | Site connection ready; connect your account | Connect Google | Account and required scope shown |
| Scope obsolete | Connection lacks Drive read-only permission | Reconnect Google | Required scope granted |
| Account ready, no source | Choose the first Doc to publish | Choose Google Doc | Doc selected and target choices confirmed |
| Source queued/syncing | Creating the first synced draft | View progress | Milestone and safe wait guidance |
| First sync failed | Draft was not updated; fix this issue | Contextual recovery | Retry succeeds or actionable escalation |
| First sync succeeded | Your first source is ready | Open draft | WordPress draft and linked source exist |

The final setup CTA should open source selection directly, not route users to Posts with instructions to rediscover “Add Sync Doc.” Preserve the existing Drive browser and source configuration flow. On success, show “Open draft” and “View all sources”; default future navigation to Sources.

## Setup Information Architecture

- Header: compact connection blueprint — **Site connection → Your Google account → First source**.
- Active task: one blocker, short reason, one primary action, supporting evidence.
- Site lane: OAuth credentials, redirect URI, API requirements; admin-only.
- Personal lane: connected email, scope, connect/reconnect; current user only.
- Help: contextual disclosure beside the affected task; Google Cloud detail stays collapsed.
- Defaults and telemetry: secondary after connection readiness; telemetry remains explicit, optional, and default off.
- Destructive OAuth reset: Advanced disclosure with consequences and confirmation.

Never mark setup “complete” at account connection. Account readiness unlocks source selection; first successful sync completes activation.

## Sources As Daily Home

Prioritize operating health over a flat inventory:

1. **Needs attention**: errors, stale/stuck syncs, lost authorization, blocked downloads.
2. **In progress**: syncing sources with milestone progress and last heartbeat.
3. **Healthy**: last successful sync, output preset/type, and Google modification context.

Keep search and filters, but add a compact health summary only when sources exist. Preserve table density on desktop. Each row should answer: target, Doc, current health, last success, and next action. “Sync all changed” remains a site-level action; row actions stay contextual. Empty states must distinguish no sources, filtered empty, disconnected account, and insufficient capability.

Agency usefulness comes from clean per-site handoff and evidence, not a cross-site dashboard. In-house usefulness comes from fast recurring sync and clear exceptions. Both use the same Sources model.

## Recovery UX

Every failure must state what failed in user language, whether WordPress content changed, who can resolve it, one recommended action, and where to inspect technical detail.

Recovery mapping:

| Condition | Inline action | Secondary detail |
|---|---|---|
| Token/scope invalid | Reconnect Google | Required vs current scope |
| OAuth site config invalid | Ask administrator / open Setup | Credential test evidence |
| Download blocked | Open source permissions | Google compatibility detail |
| WP-Cron delayed/stuck | Retry / scheduling guidance | Last heartbeat and cron state |
| Large export | Continue via automatic fallback | Method shown after completion |
| Transform/import error | Retry; open Sync Activity | Safe output-path and error code |

Do not expose raw error codes as the headline. Preserve form values and selected source after recoverable errors. Announce terminal background results without stealing focus.

## Accessibility And Responsive States

- Use semantic ordered progress with text states; color/icon alone is insufficient.
- Move focus to the active task heading only after explicit navigation, not background state refresh.
- Use an `aria-live="polite"` region for save, connection, queue, and terminal sync updates.
- Keep focus visible, logical tab order, descriptive external-link text, and 40–44px targets where practical.
- Credential errors attach to fields; journey-level errors attach to the active task.
- Honor reduced motion; progress may update numerically without animated travel.
- Mobile order: journey summary, active task, contextual help, account evidence, defaults.
- Collapse the three-stage blueprint into a compact vertical/ordered summary; never place progress after the task.
- Tables require a usable narrow-screen treatment with labels retained; horizontal scrolling must not hide the primary row action.
- Validate loading, long email/title, translation expansion, no sources, filtered empty, offline/request failure, queued, stale, success, and error states.

## Success Metrics And Privacy

North-star: **percentage of activated installs with at least one source successfully synced twice**.

Local funnel metrics can be computed from existing settings/source state:

- site credentials ready;
- personal account ready;
- first source linked;
- first sync succeeded;
- second sync succeeded within 30 days;
- median activation time and step drop-off;
- failure recovered without another failure/support path.

Remote telemetry must remain separately opt-in. If expanded later, send only coarse install-level event names, elapsed-time buckets, counts, plugin/WP/PHP versions, and consent version. Never send site URL, user/email, post/document IDs or titles, Google metadata/content, error text, or imported media. Document the new payload and retention before shipping; existing consent must not silently authorize materially broader collection.

## Release Slicing

| Slice | Scope |
|---|---|
| 1 — Activation foundation | Derived advisor state, blueprint, capability-aware copy, direct first-source CTA, accessible announcements; no REST change unless direct selection requires it |
| 2 — First-source completion | Doc selection, queued progress, failure recovery, success actions; successful draft creation completes activation |
| 3 — Sources health home | Health ordering/summary, role-aware empty states, row recovery, post-activation routing; preserve filters and polling |
| 4 — Reliability validation | Scheduling diagnostics, repeated-failure guidance, local funnel measurement, optional telemetry proposal after consent review |

## Risks And Mitigations

- **Role inference becomes authorization:** render from WordPress capabilities and server enforcement; persona affects copy only.
- **Setup grows into nested cards:** one active task plus quiet evidence/disclosures; keep the 16/12px rhythm.
- **False completion:** require successful source sync, not saved credentials or OAuth callback.
- **Cross-user confusion:** explicitly label site-wide versus “your account”; never imply one user connection serves all users.
- **Sources health requires unavailable data:** phase UI around current status/error/progress first; add contracts only for validated needs.
- **Background sync appears frozen:** show milestone, last update, safe navigation, and stale recovery.
- **Agency scope creep:** defer cross-site management; optimize reproducible per-site setup/handoff.
- **Telemetry trust regression:** keep default off, minimize payload, version consent, and retain useful local-only metrics.

## Acceptance Scenarios

1. Admin with no credentials sees one site-configuration action and can import JSON, copy the exact URI, save, and continue.
2. Editor without admin capability sees that site setup requires an administrator, with no credential fields or reset action.
3. Site-ready disconnected user connects Google and returns to the first-source task with account evidence.
4. Old-scope user receives a reconnect explanation and cannot enter a dead-end Doc browser.
5. Ready user chooses a Doc directly, confirms target/output, observes queued progress, and opens the successful draft.
6. First sync failure explains content safety, ownership, and one recovery action while preserving context.
7. Activated user lands on Sources; attention items precede healthy sources without breaking filters.
8. Empty Sources offers first-source creation; filtered empty offers reset; disconnected state offers reconnect.
9. Keyboard-only, screen-reader, and narrow-screen users can understand stage, blocker, progress, result, and recovery; agency handoff and in-house workflows share capability-governed contracts.

## Unresolved Questions

1. Can the existing `createSyncedDraftUrl` open the source modal directly, or is a small URL/action contract required?
2. Which WordPress capabilities currently gate site settings, per-user connection, source creation, bulk sync, and log access?
3. Is source ownership intentionally restrictive, or may another authorized editor recover/sync a source linked by a colleague?
4. Should post-activation navigation change globally to Sources, or only the success CTA and plugin landing route?
5. What precise event set, if any, merits a new opt-in telemetry consent version?
