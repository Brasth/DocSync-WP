#!/usr/bin/env bash
# Cloud Agent start phase: per-boot runtime reconciliation. Starts MariaDB and
# reconciles the WordPress site, then returns. Long-lived foreground processes
# (web server, cron worker, Vite watcher) run as named terminals, not here.
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Initialize the MariaDB data directory on a fresh image (no system tables yet).
if [[ ! -d /var/lib/mysql/mysql ]]; then
	echo "==> Initializing MariaDB data directory"
	sudo mariadb-install-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=socket >/dev/null
fi

echo "==> Starting MariaDB"
sudo service mariadb start || true

# Wait until the server accepts connections.
for _ in {1..60}; do
	if sudo mariadb -e "SELECT 1;" >/dev/null 2>&1; then
		break
	fi
	sleep 1
done

echo "==> Ensuring WordPress database and user"
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY 'wordpress';
CREATE USER IF NOT EXISTS 'wordpress'@'127.0.0.1' IDENTIFIED BY 'wordpress';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'localhost';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "==> Bootstrapping WordPress"
"${repo_dir}/.cursor/scripts/bootstrap-wordpress.sh"

echo "Start phase complete."
