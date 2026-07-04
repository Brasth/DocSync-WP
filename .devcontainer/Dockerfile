FROM composer:2 AS composer

FROM wordpress:php8.3-apache

ENV COMPOSER_ALLOW_SUPERUSER=1 \
	WP_CLI_ALLOW_ROOT=1 \
	WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		ca-certificates \
		curl \
		default-mysql-client \
		git \
		gnupg \
		less \
		libcurl4-openssl-dev \
		libfreetype6-dev \
		libjpeg62-turbo-dev \
		libonig-dev \
		libpng-dev \
		libzip-dev \
		unzip \
		zip; \
	docker-php-ext-configure gd --with-freetype --with-jpeg; \
	docker-php-ext-install -j"$(nproc)" curl gd mbstring mysqli pdo_mysql zip; \
	rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	install -d -m 0755 /etc/apt/keyrings; \
	curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg; \
	chmod a+r /etc/apt/keyrings/nodesource.gpg; \
	echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" > /etc/apt/sources.list.d/nodesource.list; \
	apt-get update; \
	apt-get install -y --no-install-recommends nodejs; \
	corepack enable; \
	corepack prepare pnpm@9.15.0 --activate; \
	npm cache clean --force; \
	rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp; \
	chmod +x /usr/local/bin/wp; \
	wp --allow-root --info

WORKDIR /var/www/html/wp-content/plugins/brasth-document-sync-for-google-docs
