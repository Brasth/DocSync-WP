# Phase 06: Verification and Release Hardening

## Overview

Priority: P1  
Status: Pending  
Effort: 4h

Verify security, capability behavior, build quality, and release readiness.

## Files

Modify:

- [/Volumes/500GB/Projects/DocSync-WP/README.md](/Volumes/500GB/Projects/DocSync-WP/README.md)
- [/Volumes/500GB/Projects/DocSync-WP/uninstall.php](/Volumes/500GB/Projects/DocSync-WP/uninstall.php)
- [/Volumes/500GB/Projects/DocSync-WP/composer.json](/Volumes/500GB/Projects/DocSync-WP/composer.json)
- [/Volumes/500GB/Projects/DocSync-WP/package.json](/Volumes/500GB/Projects/DocSync-WP/package.json)

Create if test stack is added:

- [/Volumes/500GB/Projects/DocSync-WP/tests/](/Volumes/500GB/Projects/DocSync-WP/tests/)

## Verification Matrix

Backend:

- OAuth URL generation includes state and offline access.
- OAuth callback rejects missing/invalid state.
- Token refresh does not expose secrets.
- REST endpoints reject unauthenticated users.
- REST endpoints reject users without target post capability.
- CPT disabled in settings cannot be synced.
- Invalid Google Doc URL/file id returns clear error.
- Non-Google Docs files rejected.
- Sync lock prevents duplicate concurrent sync.

Frontend:

- Post edit meta box works in block editor and classic editor.
- List-table top action opens modal with current post type.
- Row action opens modal or syncs existing source.
- Paste URL and file ID paths both work.
- Picker missing config shows actionable setup error.
- REST failures show clear admin notices.

Build:

```sh
composer validate
composer dump-autoload -o
vendor/bin/phpcs
pnpm typecheck
pnpm build
```

Manual QA:

- Connect Google as admin.
- Link Doc to existing post.
- Create new post from list page.
- Sync linked row from inline action.
- Disconnect Google and verify sync fails cleanly.
- Test at least one public custom post type.

## Documentation

Update README with:

- Google Cloud setup steps.
- OAuth redirect URI.
- Required APIs: Drive API, Picker API.
- Scope behavior and pasted link/id limitation.
- Security/privacy notes.
- WP-Cron reliability note.

## Uninstall/Cleanup

Decide cleanup policy before implementation:

- Conservative default: remove plugin options and cron only, keep post meta.
- Full cleanup constant/filter can remove all DocSync post/user meta.
- Never delete synced posts on uninstall.

## Success Criteria

- Verification commands pass.
- README has complete setup flow.
- Security-sensitive storage reviewed.
- Manual QA covers default post and one CPT.
- Known limitations documented.

## Todo

- [ ] Run PHP validation.
- [ ] Run frontend validation.
- [ ] Manual QA post edit flow.
- [ ] Manual QA list-table flow.
- [ ] Manual QA CPT flow.
- [ ] Update README.
- [ ] Review uninstall behavior.
