#!/usr/bin/env bash
set -Eeuo pipefail

wp_root="/opt/wordpress"
repo_root="$(cd "$(dirname "$0")/../.." && pwd)"

if [[ -z "${GOOGLE_OAUTH_CLIENT_JSON:-}" ]]; then
  echo "GOOGLE_OAUTH_CLIENT_JSON not set; skipping OAuth site configuration."
  exit 0
fi

tmp_json="$(mktemp)"
trap 'rm -f "${tmp_json}"' EXIT
printf '%s' "${GOOGLE_OAUTH_CLIENT_JSON}" >"${tmp_json}"

wp --allow-root --path="${wp_root}" eval-file "${repo_root}/.cursor/scripts/configure-oauth-from-json.php" "${tmp_json}" >/dev/null

if wp --allow-root --path="${wp_root}" option get docsync_wp_settings --format=json 2>/dev/null | grep -q '"client_id"'; then
  echo "OAuth client configured in WordPress settings."
else
  echo "OAuth configuration did not persist." >&2
  exit 1
fi
