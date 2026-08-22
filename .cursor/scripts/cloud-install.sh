#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${repo_root}"

# Cloud Agent install runs as ubuntu. /usr/local/bin is root-owned, so
# Composer and other system binaries must be installed with sudo.
ensure_system_packages() {
  sudo DEBIAN_FRONTEND=noninteractive apt-get update -qq
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd php-intl \
    mariadb-server apache2 libapache2-mod-php unzip curl
}

ensure_composer() {
  if command -v composer >/dev/null 2>&1; then
    return 0
  fi

  curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer
  sudo install -m 0755 /tmp/composer /usr/local/bin/composer
  rm -f /tmp/composer
}

ensure_system_packages
ensure_composer

composer install --no-interaction --prefer-dist
corepack enable >/dev/null 2>&1 || true
pnpm install --frozen-lockfile
pnpm build

echo "DocSync WP cloud install finished."
