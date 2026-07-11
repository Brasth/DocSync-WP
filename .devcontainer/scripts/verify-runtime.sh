#!/usr/bin/env bash
set -Eeuo pipefail

wp_root="/var/www/html"
plugin_slug="brasth-document-sync-for-google-docs"
site_url="${WP_HOME:-http://localhost:8890}"
rest_callback="${site_url}/wp-json/${plugin_slug}/v1/oauth/google/callback"
required_extensions=(zip dom xml mbstring openssl sodium mysqli gd curl)
wp_cli=(wp --allow-root --path="${wp_root}")

for extension in "${required_extensions[@]}"; do
	if ! php -r "exit(extension_loaded('${extension}') ? 0 : 1);"; then
		echo "Missing PHP extension: ${extension}" >&2
		exit 1
	fi
done

composer --version >/dev/null

node_version="$(node --version)"
if [[ "${node_version}" != v24.* ]]; then
	echo "Unexpected Node version: ${node_version}; expected v24.x" >&2
	exit 1
fi

pnpm_version="$(pnpm --version)"
if [[ "${pnpm_version}" != "9.15.0" ]]; then
	echo "Unexpected pnpm version: ${pnpm_version}; expected 9.15.0" >&2
	exit 1
fi

wp --allow-root --info >/dev/null

"${wp_cli[@]}" core is-installed >/dev/null

actual_site_url="$("${wp_cli[@]}" option get siteurl)"
if [[ "${actual_site_url}" != "${site_url}" ]]; then
	echo "Unexpected siteurl: ${actual_site_url}; expected ${site_url}" >&2
	exit 1
fi

permalink_structure="$("${wp_cli[@]}" option get permalink_structure)"
if [[ "${permalink_structure}" != "/%postname%/" ]]; then
	echo "Unexpected permalink structure: ${permalink_structure}" >&2
	exit 1
fi

"${wp_cli[@]}" plugin is-active "${plugin_slug}" >/dev/null
"${wp_cli[@]}" cron event list --format=count >/dev/null

actual_callback="$("${wp_cli[@]}" eval "echo rest_url('${plugin_slug}/v1/oauth/google/callback');")"
if [[ "${actual_callback}" != "${rest_callback}" ]]; then
	echo "Unexpected OAuth callback: ${actual_callback}; expected ${rest_callback}" >&2
	exit 1
fi

"${wp_cli[@]}" eval '
$server = rest_get_server();
do_action( "rest_api_init", $server );
$routes = $server->get_routes();
	$required = array(
		"/brasth-document-sync-for-google-docs/v1/settings",
		"/brasth-document-sync-for-google-docs/v1/oauth/google/callback",
		"/brasth-document-sync-for-google-docs/v1/sources",
		"/brasth-document-sync-for-google-docs/v1/sync-log",
		"/brasth-document-sync-for-google-docs/v1/workspace",
	);
foreach ( $required as $route ) {
	if ( ! isset( $routes[ $route ] ) ) {
		fwrite( STDERR, "Missing REST route: {$route}\n" );
		exit( 1 );
	}
}
'

echo "Runtime verification passed."
echo "WordPress: ${site_url}"
echo "Plugin setup: ${site_url}/wp-admin/admin.php?page=${plugin_slug}"
echo "OAuth redirect URI: ${rest_callback}"
