# Phase 01: Foundation and Data Model

## Overview

Priority: P1  
Status: Completed  
Effort: 6h

Create the backend foundation for settings, per-user Google token storage, post-linked source metadata, capabilities, and REST wiring.

## Context Links

- Research: [../../docs/research/google-docs-wordpress-sync.md](../../docs/research/google-docs-wordpress-sync.md)
- Bootstrap: [/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php](/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php)
- Admin assets: [/Volumes/500GB/Projects/DocSync-WP/src/Assets/AssetRegistry.php](/Volumes/500GB/Projects/DocSync-WP/src/Assets/AssetRegistry.php)

## Requirements

- Store site-level Google app settings.
- Store OAuth tokens per WordPress user, never in frontend config.
- Store one Google Doc sync source per post for MVP.
- Support `post` and enabled public custom post types.
- Gate actions with target post capabilities, not only `manage_options`.

## Data Model

Site option `docsync_wp_settings`:

- `client_id`
- `encrypted_client_secret`
- `picker_api_key`
- `picker_app_id`
- `scope_mode`: `drive_file` initially
- `enabled_post_types`
- `default_post_status`: `draft`
- `default_export_format`: `markdown`

User meta `_docsync_wp_google_token`:

- encrypted `refresh_token`
- `access_token`
- `expires_at`
- `scope`
- `google_account_email`
- `connected_at`

Post meta:

- `_docsync_wp_google_file_id`
- `_docsync_wp_google_doc_url`
- `_docsync_wp_google_title`
- `_docsync_wp_google_modified_time`
- `_docsync_wp_google_version`
- `_docsync_wp_last_hash`
- `_docsync_wp_last_synced_at`
- `_docsync_wp_sync_owner_user_id`
- `_docsync_wp_export_format`
- `_docsync_wp_sync_status`
- `_docsync_wp_sync_error`

## Files

Create:

- [/Volumes/500GB/Projects/DocSync-WP/src/Settings/SettingsRepository.php](/Volumes/500GB/Projects/DocSync-WP/src/Settings/SettingsRepository.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Security/EncryptionService.php](/Volumes/500GB/Projects/DocSync-WP/src/Security/EncryptionService.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Auth/TokenStore.php](/Volumes/500GB/Projects/DocSync-WP/src/Auth/TokenStore.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Sync/SourceRepository.php](/Volumes/500GB/Projects/DocSync-WP/src/Sync/SourceRepository.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/RestServiceProvider.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/RestServiceProvider.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/SettingsController.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/SettingsController.php)

Modify:

- [/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php](/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Assets/AssetRegistry.php](/Volumes/500GB/Projects/DocSync-WP/src/Assets/AssetRegistry.php)

## Implementation Steps

1. Register service classes in `Plugin::boot()`.
2. Add `rest_api_init` hook via `RestServiceProvider`.
3. Add `SettingsRepository` with `get()`, `save()`, defaults, sanitize.
4. Add `EncryptionService` using OpenSSL when available.
5. Add `TokenStore` around user meta.
6. Add `SourceRepository` around post meta.
7. Add helper methods:
   - `get_enabled_post_types()`
   - `user_can_sync_post( int $post_id, int $user_id )`
   - `user_can_create_synced_post( string $post_type, int $user_id )`
8. Update asset config with REST nonce, current user id, enabled post types, picker settings presence.

## Success Criteria

- REST settings endpoint reads/saves settings for admins only.
- No secrets exposed in `window.DocSyncWPAdmin`.
- Post-type validation rejects disabled or non-existing types.
- Capability checks are reusable by later controllers.

## Risks

- Encryption based on WordPress salts means salt rotation invalidates stored tokens. Document this behavior.
- Existing frontend config type must be updated with no runtime React dependency.

## Todo

- [x] Add settings repository.
- [x] Add encryption service.
- [x] Add token store.
- [x] Add source repository.
- [x] Add REST service provider.
- [x] Wire services in plugin bootstrap.
- [x] Update admin config shape.
