# Administrator onboarding implementation

Date: 2026-10-10 · Status: complete · Docs impact: minor

Implemented the four Claude-based Journey 1 views, real settings/account state, a safe operator directory, unsaved-change protection, and persistent successful-import activation. Existing source modal, pollers, defaults and telemetry consent remain functional. Desktop measurements: 300px rail / 40px gap, 706px task cards, 760px source area, two 580px maintenance cards. Native WordPress chrome, live values and accurate privacy/verification copy differ from prototype examples.

Verification passed:
- Typecheck, JS lint, screenshot lint, blueprint lint, 117 setup journey assertions, seven-entry production build, host no-inline-PHPCS-ignore guard, and `git diff --check`; final recorded checks had empty stderr.
- Full `composer lint`; standalone `php scripts/verify-setup-settings.php` and `php scripts/verify-setup-connections.php`.
- `wp eval-file scripts/verify-setup-activation-history.php` on WordPress 6.4 and 7.1.3; earlier telemetry, schedule resolver and folder-watch verifiers passed.
- Browser fixtures: failed-save input retention, navigation Cancel, unsaved-default guard, disconnect confirmation, three connection states and error/retry, Doc/folder mode, progress and recovery. 390px and 720px viewport checks had no horizontal overflow.

The container lacks `rg`; the PHP-ignore guard ran with host `rg` instead. Browser fixtures and viewport override were removed. The isolated WordPress 6.4 fixture and its 12 tables were removed. No new dev server was started.

Actual screenshot: `/tmp/docsync-journey-preview/onboarding-live.jpg`. Synthetic maintenance preview: `/tmp/docsync-journey-preview/maintenance-fixture.jpg`. Real Google OAuth consent and a live Doc import were not exercised. No independent post-write review, screen-reader audit, real 200% browser zoom or performance-budget test is claimed. No commit or deployment performed.

Unresolved questions: none.
