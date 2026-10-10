# Journey 2 Google + matching harness wiring

Date: 2026-10-10 · Worker job: `20261010T155933Z-18659-63306de589424e2bb7454c9f5901509c`

## Owned files

- `tests/journey-2-google-tests.php` — copied from `/private/tmp/journey-2-google-retained.php`
- `tests/journey-2-matching-tests.php` — copied from `/private/tmp/docsync-matching-harness/harness.php`
- `scripts/test-journey-2.mjs` — extended to invoke both harnesses

## Changes

### Google harness

- Comment path updated to `tests/journey-2-google-tests.php` (no fixed `/tmp/plugin` paths present).
- Protected token/option `WARNING` increments `j2_fail`.
- Ends nonzero when `j2_fail > 0`; prints `PASS N FAIL N`.
- Before-setter contracts: live boot already injects optional deps, so harness clears `source_repository` / `source_batch` via reflection immediately before those two assertions, then re-injects as before. Assertions unchanged.
- HTTP mocks, scoped testdata cleanup, in-memory options preserved. No real Google.

### Matching harness

- Usage comment points at new filename.
- `ABSPATH` stub `/tmp/` kept (WP stub, not plugin path).
- Plugin dir from PHP argv; exit already strict on `$failures > 0`.
- Runner invokes twice: normal php, then `disable_functions=mb_strtolower` (233 / 235 checks).

### Runner

- Default full run: WP-CLI import + WP-CLI Google + php matching (normal + mb disabled).
- Same host/container Docker detection as before (`docker compose` then `docker-compose` fallback).
- `--ui-only`: 22 UI/crop tests; skips all four PHP suite labels.
- Fail-closed counters: requires `PASS N FAIL N`, `FAILURES: N`, `N failures`, or `ALL PASS`; also fails on protected WARNING lines.

## Verification

Full run inside wordpress container (host `node_modules` has linux-only esbuild):

```text
ℹ tests 22
ALL PASS
PHP tests/journey-2-tests.php: passed (exit 0, fail 0)
PASS 157 FAIL 0
PHP tests/journey-2-google-tests.php: passed (exit 0, fail 0)
233 checks, 0 failures
PHP tests/journey-2-matching-tests.php: passed (exit 0, fail 0)
235 checks, 0 failures
PHP tests/journey-2-matching-tests.php (disable mb_strtolower): passed (exit 0, fail 0)
EXIT:0
```

`--ui-only` inside container: 22 tests, all four PHP suites skipped, EXIT:0.

Google isolation line: `tokens unchanged`.

## Limits

- Host-side `node scripts/test-journey-2.mjs` still fails UI bundling when `node_modules` only has `@esbuild/linux-arm64`. PHP suites still run after that UI failure. Run inside the wordpress container (or reinstall host esbuild) for a green full exit on macOS host.
- `tests/*` remains gitignored except fixtures; harness files are workspace-local like `journey-2-tests.php`.

## Unresolved questions

- None for this scoped brief.
