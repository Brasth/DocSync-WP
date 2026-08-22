#!/usr/bin/env bash
# Cloud Agent install phase: idempotent dependency refresh and asset build.
# Runs after the repository is checked out. Must terminate; no long-lived
# processes belong here. System packages (PHP, Composer, WP-CLI, MariaDB) are
# baked into the environment base image / snapshot, not installed here.
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${repo_dir}"

echo "==> composer install"
composer install --no-interaction --prefer-dist

echo "==> pnpm install"
CI=1 pnpm install --frozen-lockfile --reporter=append-only

echo "==> pnpm build"
pnpm build

echo "Install phase complete."
