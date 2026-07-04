# Dev Container

This dev container runs a local WordPress site for Brasth Document Sync at:

```text
http://localhost:8888
```

WordPress admin:

```text
http://localhost:8888/wp-admin/
admin / password
```

Plugin setup:

```text
http://localhost:8888/wp-admin/admin.php?page=brasth-document-sync-for-google-docs
```

OAuth redirect URI:

```text
http://localhost:8888/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback
```

## Start

1. Open this repository in VS Code.
2. Run **Dev Containers: Reopen in Container**.
3. Wait for `postCreateCommand` to install Composer dependencies, install pnpm dependencies, and build Vite assets.
4. Wait for `postStartCommand` to install WordPress, activate the plugin, and run runtime verification.

The Docker Compose stack includes:

- `wordpress`: `wordpress:php8.3-apache` with Composer, WP-CLI, Node 24, pnpm 9.15.0, and plugin PHP extensions.
- `db`: `mysql:8.0`.
- `adminer`: local DB inspection at `http://localhost:8081`.

Data persists in Docker named volumes. The current repository is mounted as the active plugin at:

```text
/var/www/html/wp-content/plugins/brasth-document-sync-for-google-docs
```

## Commands

Run these inside the dev container from the plugin directory:

```sh
composer validate --no-check-publish
composer lint
composer test:layout-fixtures
composer test:elementor-fixtures
composer test:telemetry-settings
pnpm lint
pnpm typecheck
pnpm build
pnpm dev
wp --allow-root --path=/var/www/html plugin status brasth-document-sync-for-google-docs
wp --allow-root --path=/var/www/html cron event list
```

Runtime checks can be rerun at any time:

```sh
.devcontainer/scripts/bootstrap-wordpress.sh
.devcontainer/scripts/verify-runtime.sh
```

## Google OAuth Smoke Test

Do not copy OAuth credentials into the Docker image and do not commit them.

1. In Google Cloud, add this Authorized redirect URI:

   ```text
   http://localhost:8888/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback
   ```

2. Download the OAuth Web application JSON locally.
3. Open the plugin Setup screen in WordPress admin.
4. Import the OAuth JSON through the plugin UI.
5. Connect a Google account.
6. Browse Drive or inspect a Google Doc URL/file ID.
7. Link a Doc to a post, trigger background sync, and confirm synced content, imported media, progress state, and Sync Activity logs.

Local secret paths ignored by Git:

```text
client_secret*.json
.secrets/
.env.local
```

## Troubleshooting

If WordPress is not ready, rerun:

```sh
.devcontainer/scripts/bootstrap-wordpress.sh
```

If activation fails, confirm dependencies and assets exist:

```sh
composer install
pnpm install --frozen-lockfile
pnpm build
```

If REST callback URLs are wrong, confirm the site URL:

```sh
wp --allow-root --path=/var/www/html option get siteurl
wp --allow-root --path=/var/www/html option get home
```

To reset the local WordPress and database state, stop the dev container and remove the Compose volumes for this project.
