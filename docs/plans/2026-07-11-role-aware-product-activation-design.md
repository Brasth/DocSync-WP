# Role-Aware Product Activation Design

Date: 2026-07-11  
Status: Approved  
Scope: Product architecture and UX design; no implementation

## Product Outcome

Brasth Document Sync becomes a content-publishing workflow for agencies and in-house editorial teams, not only a Google Docs connection utility.

The product promise is:

> Google Docs is where content is written. Brasth is how it becomes reliable WordPress content.

The activation milestone is the first successfully synced WordPress draft. Setup readiness is an intermediate state, not the outcome.

## Audience Model

One product serves two contexts through shared capabilities and role-aware guidance.

### Site Owner or Agency Administrator

- Configures the site-level Google OAuth client.
- Enables required Google APIs and registers the redirect URI.
- Chooses site publishing defaults and supported post types.
- Verifies that the client site is ready for content operators.
- Maintains connection health and handles site-wide recovery.

### Content Operator or In-House Editor

- Connects a personal Google account.
- Selects accessible Google Docs.
- Creates or links WordPress targets.
- Reviews sync state and recovers failures they can resolve.
- Works primarily from Sources and post-level sync controls after activation.

These are responsibilities, not separate product editions. WordPress capabilities and current connection state determine which guidance and actions appear.

## Chosen Product Architecture

Use a shared core with role-aware entry points.

The product has three connected domains:

1. **Site connection:** site-level OAuth credentials, APIs, redirect URI, supported targets, and defaults.
2. **Personal connection:** encrypted per-user Google token and required Drive scope.
3. **Publishing sources:** linked Google Docs, WordPress targets, output policy, progress, health, and recovery.

Existing settings, user-token, and source-meta records remain authoritative. The experience derives journey state from those records instead of adding a second persisted onboarding state.

## Experience Architecture

### First-Run Activation Workspace

The first-run experience presents two explicit lanes:

- **Set up this site:** Google Cloud project, OAuth web client, redirect URI, credentials, and sync defaults.
- **Connect your Google account:** personal authorization, required scope, and account status.

A deterministic setup advisor explains one current blocker using four fields:

- `stage`: site connection, personal connection, or first source;
- `blocker`: the fact preventing progress;
- `evidence`: the setting or account state supporting that conclusion;
- `action`: one primary recovery or continuation action.

OAuth JSON import is the preferred credential path. Manual client ID and secret entry remains available. Detailed Cloud instructions and destructive controls stay progressively disclosed.

When both connections are ready, the primary action launches the existing Google Doc selection/new-source flow directly. Users should not be sent to the Posts list and asked to rediscover the entry point.

### Connection Blueprint

The memorable visual model is a restrained connection blueprint:

```text
Site configuration -> Personal Google access -> First publishing source
```

It communicates ownership and progress without becoming decorative. Navy anchors identity, blue identifies the primary action, teal indicates connected/complete states, and semantic warning/error tokens indicate blockers. The experience stays inside WordPress admin conventions.

### Post-Activation Home

After a successful first source, Sources becomes the daily product home. Setup becomes a maintenance destination.

Sources prioritizes:

1. sources needing attention;
2. active syncs;
3. healthy sources and last successful sync;
4. source-specific actions and recovery paths.

Agency administrators see site readiness, output-policy consistency, and intervention needs. Editors see personal connection state, sources they can operate, and actionable content status. Both use the same source records and components.

### Reliability and Recovery

Every failure response must answer:

1. What failed?
2. Was WordPress content changed?
3. Who can fix the problem?
4. What exact action should happen next?

Sync Activity remains bounded diagnostic history, but its presentation becomes recovery-oriented. Existing safe-write behavior, revisions, locking, background progress, unchanged skips, large-document fallback, and output-path context should be surfaced as product trust signals.

## State and Data Flow

```text
Site settings + current user account + source summary
                         |
                         v
             Derived journey/advisor state
                         |
         +---------------+----------------+
         |               |                |
   Setup workspace   Direct Doc flow   Sources home
```

- The server remains authoritative for permissions and encrypted data.
- The client derives presentation state from typed REST responses.
- Capability checks control global configuration versus personal/source actions.
- First-source activation should be derived from accessible source/sync state where practical.
- No document content, Google IDs, user email, or site URL enters product telemetry.

## Visual and Interaction Rules

- Follow the premium utilitarian Brasth admin direction.
- Keep one primary action per state.
- Use WordPress/system admin typography; do not load webfonts.
- Avoid landing-page heroes, glass effects, decorative gradients, and nested card stacks.
- Use existing design tokens, `AdminButton`, Radix dialogs/tabs, and screen-specific Vite bundles.
- Use CSS-only 150-250ms state transitions and honor reduced motion.
- Preserve visible focus, logical keyboard order, semantic headings, live status announcements, and 40-44px practical targets.
- On narrow screens, keep current stage and primary task before secondary progress or account information.

## Error Handling

The advisor and recovery UI must cover at least:

- missing site credentials;
- malformed or wrong OAuth JSON type;
- redirect URI mismatch;
- required APIs not configured or OAuth flow rejected;
- current user not connected;
- outdated Drive scope requiring reconnection;
- Google file not downloadable;
- stale or failed background sync;
- low-traffic or disabled WP-Cron reliability limitations;
- insufficient WordPress capability.

Inputs remain intact after recoverable errors. Destructive account/configuration changes require confirmation and describe retained content.

## Release Strategy

1. **Activation release:** role-aware two-lane setup, derived advisor, direct first-source flow.
2. **Sources workspace release:** health-first source home and role-aware empty/action states.
3. **Reliability release:** recovery-oriented diagnostics and contextual scheduling guidance.
4. **Team scale release:** bulk health actions and stronger site policies after usage validation.

Managed OAuth, cross-site agency control, two-way sync, and generative writing remain out of scope.

## Success Measures

Primary metric:

- percentage of activated sites with at least one source successfully synced twice.

Supporting funnel:

- site OAuth configuration completed;
- personal Google account connected with required scope;
- first source linked;
- first sync succeeded;
- second successful sync within 30 days;
- failed sync recovered without support;
- time and abandonment between activation stages.

Measurement must respect the existing opt-in telemetry boundary. Product events may first be calculated locally; any future aggregate reporting requires explicit consent and privacy review.

## Compatibility and Security Constraints

- Preserve current REST route shapes unless additive fields are justified.
- Preserve settings, user meta, source meta, encryption, and output behavior.
- Never infer authorization from visual role; server capabilities remain mandatory.
- Do not expose global OAuth actions to content-only users.
- Do not add runtime dependencies for this product polish.
- Do not create custom database tables for onboarding or analytics.
- Keep Google Docs as the one-way source of truth.

## Acceptance Criteria

- An administrator understands the distinction between site setup and personal connection without reading documentation.
- An editor cannot see or operate site-wide secrets or destructive configuration controls.
- The interface always exposes one primary next action and explains blockers with evidence.
- A ready user can launch Doc selection directly from activation.
- A successful first source transitions users toward Sources as the daily workspace.
- Failure states state content safety and a concrete recovery action.
- Existing users and sources retain behavior without migration.
- Keyboard, screen-reader, reduced-motion, narrow-layout, and long-copy checks pass.

## Approved Decisions

- First successfully synced draft is the activation milestone.
- Self-managed OAuth remains the only connection model for now.
- Agencies and in-house teams share one role-aware product.
- Sources becomes the post-activation home.
- Managed OAuth, cross-site agency control, two-way sync, and AI writing are deferred.

## Unresolved Questions

- Whether direct first-source activation should open the existing source modal on the Setup route or use a dedicated activation route that reuses the same workflow components.
- Whether locally computed funnel milestones need any persistence beyond existing settings, account, source, and sync records.
- Whether capability-limited editors should see a read-only site-readiness summary or only a concise “contact your administrator” blocker.
