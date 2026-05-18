# Phase 05: Central Admin App, Scheduling, Logs

## Overview

Priority: P2  
Status: Completed  
Effort: 6h

Upgrade the existing DocSync top-level admin screen into a control center and add basic scheduled sync and observability.

## Files

Create:

- [/Volumes/500GB/Projects/DocSync-WP/src/Cron/SyncCron.php](/Volumes/500GB/Projects/DocSync-WP/src/Cron/SyncCron.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/SyncLogController.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/SyncLogController.php)
- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/components/SettingsPanel.tsx](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/components/SettingsPanel.tsx)
- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/components/SourcesTable.tsx](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/components/SourcesTable.tsx)

Modify:

- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/App.tsx](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/App.tsx)
- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/config.ts](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/config.ts)
- [/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php](/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php)
- [/Volumes/500GB/Projects/DocSync-WP/docsync-wp.php](/Volumes/500GB/Projects/DocSync-WP/docsync-wp.php)

## Requirements

- Settings page for Google client id/secret, Picker API key/app id, enabled post types.
- Connected account panel for current WordPress user.
- Sources table with post title, post type, Google Doc, status, last synced, actions.
- Manual sync all changed sources.
- Optional scheduled sync interval.
- Sync logs visible enough for support.

## Scheduling Design

- Use WP-Cron for MVP.
- Register recurring event only when scheduling is enabled.
- On cron run:
  - query posts with `_docsync_wp_google_file_id`.
  - group by `_docsync_wp_sync_owner_user_id`.
  - refresh each owner's token.
  - sync changed sources in small batch.
- Unschedule on deactivation.

## Log Design

Store latest status in post meta:

- `_docsync_wp_sync_status`: idle|running|success|error
- `_docsync_wp_sync_error`
- `_docsync_wp_last_synced_at`

MVP does not need a full log table. Add custom table only if audit history becomes a requirement.

## Implementation Steps

1. Replace placeholder admin app with settings + account + source list views.
2. Add settings REST calls.
3. Add connect/disconnect Google actions.
4. Add sources REST table.
5. Add "Sync all changed" action.
6. Add schedule setting.
7. Add `SyncCron` hook registration.
8. Add deactivation cleanup for cron.

## Success Criteria

- Admin can configure Google settings from DocSync page.
- User can connect/disconnect Google.
- Sources table shows items created from post edit/list screens.
- Manual sync all works for changed docs.
- WP-Cron event is registered only when enabled.

## Todo

- [x] Replace placeholder dashboard.
- [x] Add settings panel.
- [x] Add account panel.
- [x] Add sources table.
- [x] Add sync all action.
- [x] Add WP-Cron service.
- [x] Add deactivation unschedule.

## Sync-Back Notes

- Replaced placeholder admin app with settings, account, source table, sync-one, and sync-all controls.
- Added account status/disconnect REST route.
- Added bounded, paginated source listing.
- Added schedule setting and `SyncCron`, with deactivation/uninstall unschedule.
- Added latest status/log visibility through source metadata and `/sync-log`.
