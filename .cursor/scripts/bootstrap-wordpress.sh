#!/usr/bin/env bash
# Idempotent WordPress runtime bootstrap for the Cloud Agent environment.
# Downloads WordPress core (if missing), writes wp-config, installs the site,
# symlinks this repository as the active plugin, and activates it. Safe to run
# on every boot: each step is guarded so re-runs converge without error.
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

wp_root="${DOCSYNC_WP_ROOT:-/var/www/html}"
plugin_slug="brasth-document-sync-for-google-docs"
site_url="${WP_HOME:-http://localhost:8890}"
admin_user="${WP_ADMIN_USER:-admin}"
admin_password="${WP_ADMIN_PASSWORD:-password}"
admin_email="${WP_ADMIN_EMAIL:-admin@example.test}"
db_host="${WORDPRESS_DB_HOST:-127.0.0.1:3306}"
db_name="${WORDPRESS_DB_NAME:-wordpress}"
db_user="${WORDPRESS_DB_USER:-wordpress}"
db_password="${WORDPRESS_DB_PASSWORD:-wordpress}"

wp_cli=(wp --allow-root --path="${wp_root}")

wait_for_database() {
	local host="${db_host%%:*}"
	local port="3306"
	[[ "${db_host}" == *:* ]] && port="${db_host##*:}"

	for _ in {1..60}; do
		if mysqladmin ping --protocol=TCP --host="${host}" --port="${port}" \
			--user="${db_user}" --password="${db_password}" --silent >/dev/null 2>&1; then
			return 0
		fi
		sleep 1
	done

	echo "MySQL did not become available at ${db_host}." >&2
	return 1
}

wait_for_database

# Ensure the webroot exists and is owned by the current user.
if [[ ! -d "${wp_root}" ]]; then
	sudo mkdir -p "${wp_root}"
	sudo chown -R "$(id -un):$(id -gn)" "$(dirname "${wp_root}")"
fi

# WordPress core.
if [[ ! -f "${wp_root}/wp-load.php" ]]; then
	"${wp_cli[@]}" core download --version=latest
fi

# Plugin symlink -> live repository checkout.
mkdir -p "${wp_root}/wp-content/plugins"
ln -sfn "${repo_dir}" "${wp_root}/wp-content/plugins/${plugin_slug}"

# wp-config.php.
if [[ ! -f "${wp_root}/wp-config.php" ]]; then
	"${wp_cli[@]}" config create \
		--dbname="${db_name}" \
		--dbuser="${db_user}" \
		--dbpass="${db_password}" \
		--dbhost="${db_host}" \
		--skip-check
	"${wp_cli[@]}" config set WP_DEBUG true --raw
	"${wp_cli[@]}" config set WP_DEBUG_LOG true --raw
	"${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw
	"${wp_cli[@]}" config set SCRIPT_DEBUG true --raw
	"${wp_cli[@]}" config set WP_ENVIRONMENT_TYPE local
fi

# Core install / site URL reconciliation.
if ! "${wp_cli[@]}" core is-installed >/dev/null 2>&1; then
	"${wp_cli[@]}" core install \
		--url="${site_url}" \
		--title="Brasth Document Sync Dev" \
		--admin_user="${admin_user}" \
		--admin_password="${admin_password}" \
		--admin_email="${admin_email}" \
		--skip-email
else
	"${wp_cli[@]}" option update siteurl "${site_url}" --quiet
	"${wp_cli[@]}" option update home "${site_url}" --quiet
fi

"${wp_cli[@]}" rewrite structure '/%postname%/' --hard --quiet
"${wp_cli[@]}" rewrite flush --hard --quiet

if ! "${wp_cli[@]}" plugin is-active "${plugin_slug}" >/dev/null 2>&1; then
	"${wp_cli[@]}" plugin activate "${plugin_slug}"
fi

echo "WordPress ready at ${site_url}"
echo "Admin: ${admin_user} / ${admin_password}"
echo "Plugin setup: ${site_url}/wp-admin/admin.php?page=${plugin_slug}"
