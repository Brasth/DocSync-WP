# Phase 2 — Agency Setup onboarding

**Goal:** After Google is connected, an agency admin starts a **client folder** automation from Setup, sees import progress there, and Setup marks activation without sending them to the Posts list.

**Depends on:** Phase 1 source/watch wire fields (`effectiveInterval` is optional here; folder watch GET already exists).

**Interfaces produced:**

```ts
type SetupSourceIntent = 'folder' | 'document';

type FolderActivationState = {
  watch: FolderWatchRecord;
};
```

- `useSetupApp()` adds `sourceIntent`, `openSourceModal(intent)`, `activationWatch`, `handleFolderWatchCreated(watch)`
- `WorkspaceResponse.sourceSummary.activated` is true when a healthy source exists **or** the user has a watch with `importedCount >= 1` and status `watching` or `importing`
- `ActivationResult` accepts either `{ kind: 'source', source }` or `{ kind: 'folder', watch }`

---

### Task 7: Folder-aware activation on `/workspace`

**Files:**
- Modify: `src/Rest/WorkspaceController.php`
- Modify: `src/Sync/FolderWatchService.php` `summarizeForUser()` if a boolean is cleaner than inferring in the controller

Keep `/workspace` least-privilege. Do **not** add watch IDs or folder names to `sourceSummary`.

```php
$folder = $this->folder_watches->summarizeForUser( $user_id );
$activated = $summary['activated']
	|| ( $folder['imported'] ?? 0 ) >= 1;

return array(
	'total'     => $summary['total'],
	'attention' => $summary['attention'],
	'syncing'   => $summary['syncing'],
	'healthy'   => $summary['healthy'],
	'activated' => $activated,
	'truncated' => $summary['truncated'],
);
```

Add `imported` (sum of `importedCount` for accessible watches) to `summarizeForUser()`. Existing `importing` / `watching` / `attention` stay.

A watch that is still `importing` with `importedCount >= 1` **does** activate Setup. A watch that failed before any import does **not**.

- [ ] **Step 1: Add `imported` to the folder summary and fold it into `activated`**
- [ ] **Step 2: `composer lint`**
- [ ] **Step 3: Commit**

```sh
git commit -m "feat: count imported folder docs toward setup activation"
```

---

### Task 8: Setup dual CTA + post-type target

**Files:**
- Modify: `resources/js/admin/features/google-setup/google-setup-task-types.ts`
- Modify: `resources/js/admin/features/google-setup/google-setup-task-state.ts`
- Modify: `resources/js/admin/features/google-setup/google-setup-active-task-panel.tsx`
- Modify: `resources/js/admin/features/google-setup/google-setup-next-action.tsx` only if a second button is cleaner as a sibling, not a rewrite
- Modify: `resources/js/admin/app/use-setup-app.ts`
- Modify: `resources/js/admin/app/setup-app.tsx`
- Modify: `resources/js/admin/features/google-setup/google-setup-first-sync-step.tsx` — unused; **delete or stop shipping** if nothing imports it. Prefer delete unused file over leaving Posts-list copy.
- Modify: `resources/css/components/setup-panel.css` — compact dual-action row

**Next action when `draft` and not activated:**

- Title: `Start client folder automation`
- Description: `Watch a Drive folder to create drafts from every Google Doc. One-off Docs stay available.`
- Primary label: `Watch a client folder` → `openSourceModal('folder')`
- Secondary label: `Choose one Google Doc` → `openSourceModal('document')`

When activated: keep `View Sources`. Add a tertiary link `Manage Drive Folders` → `admin.php?page=brasth-document-sync-for-google-docs-folders` (use `AdminPage::FOLDERS_MENU_SLUG` / existing folders page slug from `AdminPage.php`).

**Post type:** in the draft-task panel, above the CTAs, render a select of `workspace.creatablePostTypes` (labels from `workspace.availablePostTypes`). Default remains `[0]`. Pass `{ mode: 'new', postType }` into `DocSourceModal`.

```tsx
<DocSourceModal
  initialIntent={app.sourceIntent}
  isOpen={app.sourceModalOpen}
  onClose={app.closeSourceModal}
  onCompleted={app.handleSourceCreated}
  onFolderWatchCreated={app.handleFolderWatchCreated}
  target={app.sourceModalOpen && app.targetPostType ? { mode: 'new', postType: app.targetPostType } : null}
/>
```

`use-setup-app.ts`:

```ts
const [sourceIntent, setSourceIntent] = useState<SetupSourceIntent>('folder');
const [targetPostType, setTargetPostType] = useState('');

const openSourceModal = (intent: SetupSourceIntent = 'folder') => {
  setSourceIntent(intent);
  sourceModalTrigger.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  restoreModalFocus.current = true;
  setSourceModalOpen(true);
};
```

Initialize `targetPostType` from `workspace.creatablePostTypes[0]` inside `refresh()`.

Checklist item `first-draft` copy:

- Incomplete: `Publishing responsibility: watch a client folder or choose one Google Doc.`
- Complete: `Publishing responsibility complete: folder automation or a source is active.`

Replace every remaining “Open the Posts list, choose Add Sync Doc” string in google-setup files.

- [ ] **Step 1: Task-state + panel copy + dual CTA**
- [ ] **Step 2: Intent + post-type wiring in setup app**
- [ ] **Step 3: Delete unused first-sync step if unreferenced (`rg GoogleSetupFirstSyncStep`)**
- [ ] **Step 4: `pnpm typecheck && pnpm lint`**
- [ ] **Step 5: Commit**

```sh
git commit -m "feat: make Setup folder-first with post type picker for agencies"
```

---

### Task 9: Folder activation panel

**Files:**
- Create: `resources/js/admin/features/activation/folder-activation-result.tsx` if `activation-result.tsx` would exceed a clean split; otherwise extend `activation-result.tsx` with a discriminated prop
- Modify: `resources/js/admin/app/use-setup-app.ts`
- Modify: `resources/js/admin/app/setup-app.tsx`
- Modify: `resources/js/admin/api/folder-watch-api.ts` — reuse `getFolderWatch(id)` if it exists; add it if missing
- Modify: `resources/css/components/setup-panel.css`

On `onFolderWatchCreated(watch)`:

1. Store `activationWatch`
2. `refresh()` workspace
3. Poll `GET /folders/{id}` on the same cadence as `BackgroundSyncPoller` (2s → 5s → 15s, 10 min warning)

Panel states:

| Watch | Copy | Actions |
|-------|------|---------|
| `importing`, imported 0 | Creating drafts from this client folder | View Drive Folders |
| `importing`, imported ≥ 1 | Folder automation is active. More Docs are still importing. | Open Drive Folders, View Sources |
| `watching`, imported ≥ 1 | Folder automation is active. Member Docs will re-sync on this folder's schedule. | Open Drive Folders, View Sources |
| `error` | Folder automation stopped. WordPress content already imported was not removed. | Open Drive Folders (retry lives there) |

Do not invent a second failed-file list on Setup.

Poller: extract `useFolderWatchPoller(watchId, { onStatus, onTimeout })` next to the source poller if `setup-app.tsx` would grow past a thin composition file. Keep Setup as composition only.

- [ ] **Step 1: handleFolderWatchCreated + poller**
- [ ] **Step 2: Folder activation panel**
- [ ] **Step 3: `pnpm typecheck && pnpm lint && pnpm build`**
- [ ] **Step 4: Commit**

```sh
git commit -m "feat: show folder import activation progress on Setup"
```

---

### Task 10: Drive Folders + Sources handoff copy

**Files:**
- Modify: `resources/js/admin/features/folder-watches/folder-watches-view.tsx` empty state — already folder-intent; align nouns to “Watch a client folder”
- Modify: `resources/js/admin/features/sources/sources-folder-watches.tsx` — one line that Setup now starts folder automation
- Modify: `resources/js/admin/features/folder-watches/folder-watch-detail-page.tsx` — member re-sync sentence from Phase 1 stays; add “Started from Setup” is **not** required (no provenance field)
- Modify: `README.md` Setup paragraph — folder-first activation
- Create: `changelog/20260823-agency-automation-setup.md` when implementation lands (not in this plan-only PR)

README sentence to replace:

> When the site and personal connections are ready, **Choose Google Doc** opens the existing source workflow…

New:

> When the site and personal connections are ready, Setup offers **Watch a client folder** as the primary action and **Choose one Google Doc** as the one-off path. A folder watch creates drafts, queues imports, and counts as activation after the first imported Doc. The folder schedule governs both new-Doc discovery and member re-sync.

- [ ] **Step 1: Align empty states and README**
- [ ] **Step 2: `pnpm lint`**
- [ ] **Step 3: Commit**

```sh
git commit -m "feat: align agency copy for folder-first Setup and Drive Folders"
```

Do not use `chore`/`docs` prefixes if the only remaining edits are under `.claude`. README/changelog here are product copy for the feature.

---

### Phase 2 acceptance

- Fresh admin, connections ready: primary button is **Watch a client folder**; modal opens with `initialIntent="folder"`.
- Secondary **Choose one Google Doc** still creates one source and uses existing `ActivationResult`.
- Changing the Setup post-type select changes the modal `target.postType` and the watch's creation-fixed type.
- Creating a folder watch from Setup shows import progress without visiting Posts.
- After first imported Doc, next action is **View Sources** and Drive Folders is linked.
- Editors without `manage_options` never see Setup. They keep Sources / Drive Folders.
- `pnpm typecheck && pnpm lint && pnpm build`, `composer lint`.

---

### Manual verification (implementation PR, not this plan PR)

1. Setup → Watch a client folder → confirm inventory → Start folder sync → panel shows importing → first draft exists.
2. Drive Folders detail: interval hourly, site daily → member source `effectiveInterval` hourly.
3. Post metabox: set one member to Off → it drops out of the next site tick.
4. `wp cron event list` shows `docsync_wp_scan_folder`, `docsync_wp_sync_sources`, and continue events when more than 20 sources are due.
