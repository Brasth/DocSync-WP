# Journey 2 private storage isolation

Date: 2026-10-10  
Scope: `tests/journey-2-tests.php`, `tests/fixtures/journey-2-fixtures.php` only.

## Outcome

DONE. Suite isolates `PrivateAssetStore` under an owned `/tmp/journey-2-private-*` root and cleans only that root.

## Changes

- `journey-2-fixtures.php`: helpers `dswp_j2_allocate_private_storage`, `dswp_j2_cleanup_private_storage`, path guards. Refuse web/uploads roots and non-`/tmp/journey-2-private-*` constants.
- `journey-2-tests.php`: allocate + define `DOCSYNC_WP_PRIVATE_STORAGE_DIR` before any `PrivateAssetStore` construct; shutdown/finally cleanup of owned root only. Mode-aware assertions for `outsideWebroot` materialize/release and create-session storage mode.

## Verification

Command (twice):

```sh
docker exec -w /var/www/html/wp-content/plugins/brasth-document-sync-for-google-docs \
  docsync-wp-devcontainer-wordpress-1 \
  wp eval-file tests/journey-2-tests.php --allow-root --path=/var/www/html
```

| Run | Result | PASS | FAIL | mode |
|-----|--------|------|------|------|
| 1 (after fix) | ALL PASS, exit 0 | 146 | 0 | outsideWebroot |
| 2 | ALL PASS, exit 0 | 146 | 0 | outsideWebroot |

Also asserted:

- private storage isolated under `/tmp/journey-2-private-*`, mode 0700
- OAuth settings, Google tokens, and harness users rolled back
- post-run: no leftover `/tmp/journey-2-private-*` in container
- site `uploads/docsync-wp-private` left untouched (pre-existing dir remains)

## Gaps

- Pre-existing `uploads/docsync-wp-private/680f278aa8fe02355e65c621a45d5462` from earlier non-isolated runs was not deleted (by design: cleanup owns only the journey-2 `/tmp` root).
- Admin session `c04167f7-0b06-4171-be52-73ef0a1d83f5` not mutated by this suite path; OAuth/user snapshot equality passed. No user-data deletion performed.

## Non-goals honored

No product, runner, OAuth, or account-settings changes.
