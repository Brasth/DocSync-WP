#!/usr/bin/env bash
set -Eeuo pipefail

wp_root="/var/www/html"
plugin_slug="brasth-document-sync-for-google-docs"
site_url="${WP_HOME:-http://localhost:8888}"
admin_user="${WP_ADMIN_USER:-admin}"
admin_password="${WP_ADMIN_PASSWORD:-password}"
admin_email="${WP_ADMIN_EMAIL:-admin@example.test}"
db_host="${WORDPRESS_DB_HOST:-db:3306}"
db_user="${WORDPRESS_DB_USER:-wordpress}"
db_password="${WORDPRESS_DB_PASSWORD:-wordpress}"

wp_cli=(wp --allow-root --path="${wp_root}")

wait_for_wordpress_files() {
	for _ in {1..60}; do
		if [[ -f "${wp_root}/wp-load.php" ]]; then
			return 0
		fi

		sleep 1
	done

	echo "WordPress files were not initialized under ${wp_root}." >&2
	return 1
}

wait_for_database() {
	local host="${db_host%%:*}"
	local port="3306"

	if [[ "${db_host}" == *:* ]]; then
		port="${db_host##*:}"
	fi

	for _ in {1..60}; do
		if mysqladmin ping --host="${host}" --port="${port}" --user="${db_user}" --password="${db_password}" --silent >/dev/null 2>&1; then
			return 0
		fi

		sleep 1
	done

	echo "MySQL did not become available at ${db_host}." >&2
	return 1
}

set_config_constant() {
	local name="$1"
	local value="$2"
	local raw="${3:-true}"
	local args=(config set "${name}" "${value}" --quiet)

	if [[ "${raw}" == "true" ]]; then
		args+=(--raw)
	fi

	"${wp_cli[@]}" "${args[@]}"
}

wait_for_wordpress_files
wait_for_database

set_config_constant WP_DEBUG true
set_config_constant WP_DEBUG_LOG true
set_config_constant WP_DEBUG_DISPLAY false
set_config_constant SCRIPT_DEBUG true
set_config_constant WP_ENVIRONMENT_TYPE local false

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

if "${wp_cli[@]}" user get "${admin_user}" >/dev/null 2>&1; then
	"${wp_cli[@]}" user update "${admin_user}" \
		--role=administrator \
		--user_pass="${admin_password}" \
		--quiet
else
	"${wp_cli[@]}" user create "${admin_user}" "${admin_email}" \
		--role=administrator \
		--user_pass="${admin_password}" \
		--quiet
fi

"${wp_cli[@]}" rewrite structure '/%postname%/' --hard --quiet
"${wp_cli[@]}" rewrite flush --hard --quiet

if ! "${wp_cli[@]}" plugin is-active "${plugin_slug}" >/dev/null 2>&1; then
	"${wp_cli[@]}" plugin activate "${plugin_slug}"
fi

echo "WordPress is ready at ${site_url}."
echo "Admin login: ${admin_user} / configured local password"
echo "Plugin setup: ${site_url}/wp-admin/admin.php?page=${plugin_slug}"
