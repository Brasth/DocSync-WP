# Report: cron service www-data identity

Status: DONE

## Summary

Scoped two-file edit so the Compose `cron` worker shares the Apache `www-data` filesystem identity for private import files (mode 0600). No containers restarted; parent must request permission before a `cron` service recreate/restart.

## Changes

### `.devcontainer/docker-compose.yml`

- Added `user: www-data` under the `cron` service only.
- Left `wordpress`, `db`, volumes, and commands unchanged.

### `.devcontainer/README.md`

- Added a short paragraph: cron runs as `www-data` like Apache REST; private imports need that shared identity; existing root-created private files need targeted ownership correction, not broad `chmod`.

## Validation

- `docker-compose -f .devcontainer/docker-compose.yml config --quiet` → exit 0 (no env dump).
- `docker compose` plugin unavailable on this host; used standalone `docker-compose` 5.5.0.
- `git diff --check -- .devcontainer/docker-compose.yml .devcontainer/README.md` → clean.
- Did not restart, stop, create, or mutate containers/data.

## Non-goals / parent follow-up

- Recreate/restart the `cron` service after review (needs parent permission).
- Targeted ownership fix for any existing root-owned private canonical files inside volumes (ops, out of this file scope).

## Unresolved questions

None.
