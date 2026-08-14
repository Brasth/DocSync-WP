# Feedback Worker

This Worker receives validated feedback from the WordPress plugin and creates issues in `Brasth/DocSync-WP`. The GitHub token is never stored in the plugin repository or installer ZIP.

## Deploy

From this directory:

```sh
wrangler login
wrangler secret put GITHUB_TOKEN
wrangler secret put WORKER_SHARED_SECRET
wrangler deploy
```

`GITHUB_TOKEN` should be a fine-grained token limited to `Brasth/DocSync-WP` with **Issues: Read and write** only. `WORKER_SHARED_SECRET` is optional, but recommended. Configure the same value in each WordPress site's `wp-config.php`:

```php
define( 'DOCSYNC_WP_FEEDBACK_ENDPOINT', 'https://feedback.example.com/v1/issues' );
define( 'DOCSYNC_WP_FEEDBACK_WORKER_SECRET', 'the-same-worker-secret' );
```

Use a custom domain or route for the Worker in Cloudflare. The plugin's default endpoint is `https://feedback.brasth.com/v1/issues`; use the endpoint constant or the `docsync_wp_feedback_endpoint` filter for staging.

The WordPress route remains authenticated with the normal REST nonce and DocSync capability check. The Worker also validates payload size and fields, and never returns raw GitHub API responses.

## Verify

```sh
npm test
npm run lint
```
