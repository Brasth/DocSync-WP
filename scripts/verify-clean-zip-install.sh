#!/usr/bin/env bash
#
# Install an artifact into a disposable WordPress site and verify activation,
# the required REST routes, and the official Plugin Check static checks.
#
# Usage: bash scripts/verify-clean-zip-install.sh /path/to/plugin.zip

set -euo pipefail

PLUGIN_SLUG="brasth-document-sync-for-google-docs"
ZIP_PATH="${1:?Usage: bash scripts/verify-clean-zip-install.sh /path/to/plugin.zip}"
NETWORK_NAME="docsync-wp-release-validation-$$"
DATABASE_NAME="docsync-release-db-$$"

if [ ! -f "${ZIP_PATH}" ]; then
  echo "Release ZIP does not exist: ${ZIP_PATH}" >&2
  exit 1
fi

cleanup() {
  docker rm -f "${DATABASE_NAME}" >/dev/null 2>&1 || true
  docker network rm "${NETWORK_NAME}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "${NETWORK_NAME}" >/dev/null
docker run -d \
  --name "${DATABASE_NAME}" \
  --network "${NETWORK_NAME}" \
  -e MYSQL_DATABASE=wordpress \
  -e MYSQL_PASSWORD=wordpress \
  -e MYSQL_ROOT_PASSWORD=wordpress \
  -e MYSQL_USER=wordpress \
  mysql:8.0 \
  --default-authentication-plugin=mysql_native_password \
  >/dev/null

for _ in $(seq 1 60); do
  if docker exec "${DATABASE_NAME}" mysqladmin ping -h localhost -uwordpress -pwordpress --silent >/dev/null 2>&1; then
    break
  fi

  sleep 2
done

if ! docker exec "${DATABASE_NAME}" mysqladmin ping -h localhost -uwordpress -pwordpress --silent >/dev/null 2>&1; then
  echo "Disposable MySQL database did not become ready." >&2
  exit 1
fi

ZIP_DIR="$(cd "$(dirname "${ZIP_PATH}")" && pwd)"
ZIP_NAME="$(basename "${ZIP_PATH}")"

docker run --rm \
  --entrypoint sh \
  --network "${NETWORK_NAME}" \
  -v "${ZIP_DIR}:/release:ro" \
  wordpress:cli-2.12.0-php8.1 \
  -ec '
    set -eu
    wp core download --version=6.4 --force --allow-root
    wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost="'"${DATABASE_NAME}"'" --skip-check --allow-root
    wp core install --url=http://example.test --title="DocSync Release Validation" --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email --allow-root
    wp plugin install "/release/'"${ZIP_NAME}"'" --activate --allow-root
    wp plugin is-active "'"${PLUGIN_SLUG}"'" --allow-root
    cat > /tmp/verify-docsync-routes.php <<\PHP
<?php
$server = rest_get_server();
do_action( "rest_api_init", $server );
$routes = $server->get_routes();
foreach ( array(
  "/brasth-document-sync-for-google-docs/v1/settings",
  "/brasth-document-sync-for-google-docs/v1/oauth/google/callback",
  "/brasth-document-sync-for-google-docs/v1/sources",
  "/brasth-document-sync-for-google-docs/v1/sync-log",
  "/brasth-document-sync-for-google-docs/v1/workspace",
) as $route ) {
  if ( ! isset( $routes[ $route ] ) ) {
    fwrite( STDERR, "Missing REST route: {$route}\\n" );
    exit( 1 );
  }
}

$cleanup_dir = trailingslashit( get_temp_dir() ) . "docsync-release-cleanup-" . wp_generate_uuid4();
if ( ! wp_mkdir_p( $cleanup_dir . "/nested" ) || false === file_put_contents( $cleanup_dir . "/nested/check.txt", "cleanup" ) ) {
  fwrite( STDERR, "Could not create cleanup smoke-test directory.\n" );
  exit( 1 );
}

$extractor = new \DocSyncWP\Sync\HtmlZipPackageExtractor();
$extractor->deleteDirectory( $cleanup_dir );

if ( file_exists( $cleanup_dir ) ) {
  fwrite( STDERR, "HTML ZIP temporary directory was not deleted.\n" );
  exit( 1 );
}
PHP
    wp eval-file /tmp/verify-docsync-routes.php --allow-root
    wp plugin install plugin-check --activate --allow-root
    wp plugin check "'"${PLUGIN_SLUG}"'" --allow-root | tee /tmp/plugin-check-report.txt
    if grep -Eq "[[:space:]]ERROR[[:space:]]" /tmp/plugin-check-report.txt; then
      echo "Plugin Check reported errors." >&2
      exit 1
    fi
  '

echo "Clean ZIP installation, activation, runtime smoke, and Plugin Check passed."
