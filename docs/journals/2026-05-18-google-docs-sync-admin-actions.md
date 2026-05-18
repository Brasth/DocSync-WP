# Journal: Google Docs Sync Admin Actions

Date: 2026-05-18

## Changes

- Added post edit DocSync meta box for enabled post types.
- Added post list Add Sync Doc action, row Link/Sync actions, and status column.
- Added shared admin REST client, Google Picker helper, and source modal for Picker/URL/file ID input.
- Replaced placeholder admin app with settings, account connection, source table, sync-one, sync-all, and pagination.
- Added account status/disconnect REST route, sync log route, schedule setting, WP-Cron runner, deactivation/uninstall cleanup.
- Hardened settings nonce checks, sync lock acquisition, source pagination, and sync-all batching after review.
- Adopted Radix Dialog/Tabs primitives for the source modal while keeping WordPress `wp-element` as runtime React.
- Removed inline PHPCS suppression comments and added a lint guard to block them in plugin source.
- Fixed GitHub workflow artifact packaging so downloaded artifacts are installer-ready for WordPress.

## Verification

Passed:
- `pnpm typecheck`
- `pnpm lint`
- `pnpm build`
- corrected `/Users/huynguyen/Downloads/docsync-wp-installable-3bd9e00.zip` has `docsync-wp/docsync-wp.php` at the ZIP root

Blocked:
- PHP syntax/composer/phpcs checks: no `php`, no `composer`, no `vendor/` in local shell.
- Manual WordPress QA: needs Composer install so `vendor/autoload.php` exists.

## Decisions

- Keep no custom log table for MVP; expose latest state from post meta.
- Keep synced posts on uninstall. Remove plugin settings, encrypted user tokens, and cron by default.
- Full post-meta cleanup requires `DOCSYNC_WP_FULL_UNINSTALL` or `docsync_wp_full_uninstall` filter.
- Allow narrow ruleset-level WPCS exceptions only when WordPress APIs cannot preserve required behavior safely.

## Unresolved Questions

None.
