#!/usr/bin/env sh

set -u

wp_root="/var/www/html"

while true; do
	wp --allow-root --path="${wp_root}" cron event run --due-now --quiet || true
	sleep 5
done
