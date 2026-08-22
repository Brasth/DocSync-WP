#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${repo_root}"

if ! command -v composer >/dev/null 2>&1; then
  sudo apt-get update -qq
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd php-intl \
    mariadb-server apache2 libapache2-mod-php wp-cli
fi

composer install --no-interaction --prefer-dist
corepack enable >/dev/null 2>&1 || true
pnpm install --frozen-lockfile
pnpm build

echo "DocSync WP cloud install finished."
