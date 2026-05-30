# DocSource Modal Density + Inline Editor Sync

## Status
- Phase 1: Complete
- Phase 2: Complete
- Verification: Complete

## Goal
Improve the Google Doc link modal so users see more Drive items immediately, and stop reloading post/custom post type editor screens after sync completes.

## Scope
- Move source tabs into the modal header.
- Remove duplicate Drive location row styling and keep location as compact breadcrumb.
- Tighten Drive listing spacing while preserving accessible controls.
- Add a permission-checked REST content fetch for a synced post.
- Apply synced content into the open editor through WordPress editor APIs instead of `window.location.reload()`.

## Files
- `resources/js/admin/features/doc-source-modal/doc-source-modal.tsx`
- `resources/js/admin/features/drive-browser/drive-browser-panel.tsx`
- `resources/js/admin/features/post-sync/post-meta-box-app.tsx`
- `resources/js/admin/features/post-sync/post-editor-content.ts`
- `resources/js/admin/api/sources-api.ts`
- `resources/js/admin/api/types.ts`
- `resources/css/components/doc-source-modal.css`
- `resources/css/components/drive-browser.css`
- `resources/css/components/drive-browser-table.css`
- `src/Rest/SourceController.php`

## Validation
- `pnpm typecheck` passed
- `pnpm build` passed
- `pnpm lint` passed
- `composer lint` passed
- `php -l src/Rest/SourceController.php` passed
- `git diff --check` passed
