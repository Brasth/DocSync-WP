#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"

bash "${repo_root}/.cursor/scripts/cloud-start-wordpress.sh"
bash "${repo_root}/.cursor/scripts/cloud-configure-oauth.sh" || true
bash "${repo_root}/.cursor/scripts/cloud-seed-demo.sh" || true

echo "DocSync WP cloud start finished."
