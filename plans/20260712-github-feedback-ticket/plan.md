# GitHub feedback ticket submission

## Goal
Add an authenticated feedback form to the DocSync admin shell. Submissions are validated by WordPress, relayed through a Cloudflare Worker, and created as issues in `Brasth/DocSync-WP`. GitHub credentials stay in Cloudflare Worker secrets and are excluded from installable plugin ZIPs.

## Scope
- Add `POST /wp-json/brasth-document-sync-for-google-docs/v1/feedback` with the existing REST nonce/capability guard.
- Add a reusable feedback dialog to Setup, Sources, and Logs via `AdminShell`.
- Send only the user-entered category/title/details plus non-sensitive runtime versions; do not send site URL, user identity, Google IDs, tokens, or document content.
- Add a separate `cloudflare/feedback-worker` package with strict validation, optional shared-secret protection, GitHub Issues API forwarding, safe errors, and tests.
- Document Worker deployment, `GITHUB_TOKEN` secret setup, and optional WordPress shared secret/endpoint overrides.
- Update privacy and architecture documentation.

## Security decisions
- GitHub token is only a Wrangler secret (`GITHUB_TOKEN`) and never appears in PHP/JS/plugin ZIP.
- WordPress relay requires logged-in DocSync capability and `X-WP-Nonce`.
- Worker can require `WORKER_SHARED_SECRET`; WordPress reads the matching value only from `wp-config.php` (`DOCSYNC_WP_FEEDBACK_WORKER_SECRET`).
- Limit title/details lengths, categories, payload size, and report public-issue disclosure in the UI.
- Worker never returns raw GitHub responses or secrets.

## Verification
- Worker unit tests and syntax checks.
- PHP lint/PHPCS.
- Frontend lint, typecheck, and build.
- Existing fixture tests.
