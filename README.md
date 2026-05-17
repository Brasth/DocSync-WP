# DocSync WP

DocSync WP is a WordPress plugin scaffold for document synchronization features.

## Requirements

- PHP 8.1 or newer
- WordPress 6.4 or newer
- Composer
- Node.js 20 or newer
- pnpm 9 or newer

## Development

Install PHP dependencies:

```sh
composer install
```

Install frontend dependencies:

```sh
pnpm install
```

Build the WordPress admin app:

```sh
pnpm build
```

Watch frontend assets during development:

```sh
pnpm dev
```

The Vite build writes hashed assets and `build/manifest.json`. WordPress reads that manifest and enqueues the admin bundle only on the DocSync WP admin page.

## Runtime Notes

- The PHP namespace is `DocSyncWP\`.
- The plugin slug and text domain are `docsync-wp`.
- React is provided by WordPress through the `wp-element` script handle.
- Admin app source imports from `@wordpress/element`; it should not import runtime React from `react` or `react-dom`.
- The REST namespace reserved for future features is `docsync-wp/v1`.
- Google OAuth client secrets and user tokens are encrypted with WordPress salts. Rotating those salts invalidates stored DocSync WP credentials and tokens, so users must reconnect Google accounts afterward.

## Verification

```sh
composer validate
composer dump-autoload -o
vendor/bin/phpcs
pnpm typecheck
pnpm build
```

## Release Packaging

Build release artifacts from a clean checkout:

```sh
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile
pnpm build
```

Create the release ZIP from the plugin directory after dependencies and assets are present, excluding files listed in `.distignore`. The ZIP should include `vendor/`, `build/`, `docsync-wp.php`, `src/`, `uninstall.php`, and `README.md`.
