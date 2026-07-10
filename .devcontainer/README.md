# Dev Container

This dev container runs a local WordPress site for Brasth Document Sync at:

```text
http://localhost:8890
```

WordPress admin:

```text
http://localhost:8890/wp-admin/
admin / password
```

Plugin setup:

```text
http://localhost:8890/wp-admin/admin.php?page=brasth-document-sync-for-google-docs
```

OAuth redirect URI:

```text
http://localhost:8890/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback
```

## Start

1. Open this repository in VS Code.
2. Run **Dev Containers: Reopen in Container**.
3. Wait for `postCreateCommand` to install Composer dependencies, install pnpm dependencies, and build Vite assets.
4. Wait for `postStartCommand` to install WordPress, activate the plugin, and run runtime verification.

The Docker Compose stack includes:

- `wordpress`: `wordpress:php8.3-apache` with Composer, WP-CLI, Node 24, pnpm 9.15.0, and plugin PHP extensions.
- `db`: `mysql:8.0`.

The MySQL service is also published to the host at `127.0.0.1:3307` for PhpStorm's Database tool window. Use this data source when PhpStorm is running on macOS:

```text
Data source: DocSync WP Dev MySQL (Host)
Host: 127.0.0.1
Port: 3307
Database: wordpress
User: wordpress
Password: wordpress
```

When PhpStorm opens this project through Remote Development, it loads the shared data source from `.idea/dataSources.xml` automatically and uses the container-only `db:3306` hostname. Enter `wordpress` as the password the first time PhpStorm requests it, then save it in the IDE password store so later devcontainer sessions connect without prompting.

Use this data source when PhpStorm is attached to the devcontainer. Inside the container, `127.0.0.1` is the WordPress container, so MySQL must be reached through the Compose service name:

```text
Data source: DocSync WP Dev MySQL (Dev Container)
Host: db
Port: 3306
Database: wordpress
User: wordpress
Password: wordpress
```

Adminer is optional and does not start with the default stack. Start it when DB inspection is needed:

```sh
docker compose -f .devcontainer/docker-compose.yml --profile tools up -d adminer
```

Adminer is then available at:

```text
http://localhost:8091
```

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
   http://localhost:8890/wp-json/brasth-document-sync-for-google-docs/v1/oauth/google/callback
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

### MySQL no space left on device

If MySQL fails with `OS errno 28` or startup logs mention `No space left on device`, recover the local Docker state from the host:

```sh
docker system df
docker container prune -f
docker builder prune -f
docker system prune -af
docker compose -f .devcontainer/docker-compose.yml down
docker volume rm docsync-wp-devcontainer_db_data
```

`docker system prune -af` removes unused Docker images, networks, containers, and build cache across Docker, so other projects may need to rebuild or redownload images afterward. The final `docker volume rm` command removes only this project's local dev database volume. It is safe for disposable dev data, but it deletes the local WordPress database contents. Avoid `docker system prune --volumes`; that can remove unrelated local Docker volumes from other projects.

After cleanup, rebuild the default stack:

```sh
docker compose -f .devcontainer/docker-compose.yml up -d --build db wordpress
docker compose -f .devcontainer/docker-compose.yml ps
docker logs docsync-wp-devcontainer-db-1
```

The DB logs should not contain `No space left on device` or `--initialize specified but the data directory has files in it`.

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
