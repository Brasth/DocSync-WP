# Cloud Agent base image for Brasth Document Sync (WordPress plugin).
# Provides the stable system toolchain: PHP 8.3 + required extensions, Composer,
# WP-CLI, MariaDB, Node.js, and pnpm. Repository dependency installation and the
# asset build run later in the install phase; the WordPress runtime is bootstrapped
# in the start phase. Do not copy the repository into the image: Cursor checks out
# the requested revision separately.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    WP_CLI_ALLOW_ROOT=1 \
    WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

# System packages: PHP 8.3 with the extensions the plugin runtime and tests need
# (zip, dom/xml, mbstring, sodium, mysqli, gd, curl), MariaDB, and general tooling.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        ripgrep \
        sudo \
        unzip \
        zip \
        default-mysql-client \
        mariadb-server \
        mariadb-client \
        php8.3-cli \
        php8.3-common \
        php8.3-mysql \
        php8.3-xml \
        php8.3-mbstring \
        php8.3-zip \
        php8.3-gd \
        php8.3-curl \
        php8.3-bcmath \
        php8.3-intl; \
    rm -rf /var/lib/apt/lists/*

# Node.js 22.x (repository requires >=20) and pnpm 9.15.0 via Corepack.
RUN set -eux; \
    install -d -m 0755 /etc/apt/keyrings; \
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg; \
    chmod a+r /etc/apt/keyrings/nodesource.gpg; \
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends nodejs; \
    corepack enable; \
    corepack prepare pnpm@9.15.0 --activate; \
    rm -rf /var/lib/apt/lists/*

# Composer (from the official composer image) and WP-CLI.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN set -eux; \
    curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp; \
    chmod +x /usr/local/bin/wp; \
    php /usr/local/bin/wp --allow-root --info

# The `ubuntu` user (uid 1000) ships with the base image; grant passwordless sudo
# so the start phase can manage the MariaDB service.
RUN set -eux; \
    echo 'ubuntu ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/ubuntu; \
    chmod 0440 /etc/sudoers.d/ubuntu

USER ubuntu
WORKDIR /home/ubuntu
