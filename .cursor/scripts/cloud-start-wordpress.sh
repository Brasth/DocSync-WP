#!/usr/bin/env bash
set -Eeuo pipefail

wp_root="/opt/wordpress"
plugin_slug="brasth-document-sync-for-google-docs"
repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
site_url="${WP_HOME:-http://localhost:8890}"

ensure_packages() {
  if ! command -v wp >/dev/null 2>&1; then
    sudo DEBIAN_FRONTEND=noninteractive apt-get update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
      php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd php-intl \
      mariadb-server apache2 libapache2-mod-php wp-cli curl
  fi
}

ensure_mysql() {
  sudo mkdir -p /run/mysqld /var/run/mysqld /var/run/apache2 /var/lock/apache2 /var/log/apache2 /run/lock
  sudo chown mysql:mysql /run/mysqld /var/run/mysqld 2>/dev/null || true
  sudo chown www-data:www-data /var/run/apache2 /var/lock/apache2 2>/dev/null || true

  if ! mysqladmin ping --socket=/run/mysqld/mysqld.sock --silent 2>/dev/null; then
    if ! pgrep -x mariadbd >/dev/null; then
      sudo mysqld_safe --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid >/dev/null 2>&1 &
      sleep 3
    fi
  fi

  sudo mkdir -p /var/run/mysqld
  sudo ln -sf /run/mysqld/mysqld.sock /var/run/mysqld/mysqld.sock

  sudo mysql -e "
    CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY 'wordpress';
    GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'localhost';
    FLUSH PRIVILEGES;
  " 2>/dev/null || true
}

ensure_apache() {
  sudo a2enmod rewrite >/dev/null 2>&1 || true

  sudo tee /etc/apache2/sites-available/wordpress-8890.conf >/dev/null <<EOF
Listen 8890
<VirtualHost *:8890>
    ServerAdmin webmaster@localhost
    DocumentRoot ${wp_root}
    <Directory ${wp_root}>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/wordpress-error.log
    CustomLog \${APACHE_LOG_DIR}/wordpress-access.log combined
</VirtualHost>
EOF

  sudo a2ensite wordpress-8890.conf >/dev/null 2>&1 || true

  if ! curl -sf "http://localhost:8890/" >/dev/null 2>&1; then
    sudo bash -c 'source /etc/apache2/envvars && apache2ctl -k start' 2>/dev/null || \
      sudo bash -c 'source /etc/apache2/envvars && apache2ctl -k graceful' 2>/dev/null || true
  fi
}

ensure_wordpress() {
  if [[ ! -f "${wp_root}/wp-load.php" ]]; then
    mkdir -p "${wp_root}"
    wp core download --path="${wp_root}" --quiet
    wp config create \
      --path="${wp_root}" \
      --dbname=wordpress \
      --dbuser=wordpress \
      --dbpass=wordpress \
      --dbhost=localhost \
      --skip-check \
      --quiet
  fi

  mkdir -p "${wp_root}/wp-content/plugins"
  ln -sfn "${repo_root}" "${wp_root}/wp-content/plugins/${plugin_slug}"

  sudo tee "${wp_root}/.htaccess" >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF

  if ! wp --allow-root --path="${wp_root}" core is-installed >/dev/null 2>&1; then
    wp --allow-root --path="${wp_root}" core install \
      --url="${site_url}" \
      --title="Brasth Document Sync Dev" \
      --admin_user="admin" \
      --admin_password="password" \
      --admin_email="admin@example.test" \
      --skip-email
  fi

  wp --allow-root --path="${wp_root}" option update siteurl "${site_url}" --quiet
  wp --allow-root --path="${wp_root}" option update home "${site_url}" --quiet
  wp --allow-root --path="${wp_root}" rewrite structure '/%postname%/' --hard --quiet
  wp --allow-root --path="${wp_root}" rewrite flush --hard --quiet
  wp --allow-root --path="${wp_root}" plugin activate "${plugin_slug}" --quiet 2>/dev/null || true
}

ensure_packages
ensure_mysql
ensure_apache
ensure_wordpress

echo "WordPress ready at ${site_url} (admin / password)"
