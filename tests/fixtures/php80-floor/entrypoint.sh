#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "${PHP80_WP_CONFIG_SHA256:-}" =~ ^[a-f0-9]{64}$ ]] || { echo "Original config pin required." >&2; exit 1; }
[[ "${PHP80_BINARY_SHA256:-}" =~ ^[a-f0-9]{64}$ ]] || { echo "Original ELF pin required." >&2; exit 1; }
printf '%s  /usr/local/bin/php\n' "$PHP80_BINARY_SHA256" | sha256sum --check --strict
test "$(/usr/local/bin/php -r 'echo PHP_VERSION;')" = 8.0.30
/usr/local/bin/php -r 'exit(extension_loaded("mysqli") ? 0 : 1);'
test -f /run/php80-floor/wp-config.php
test ! -L /run/php80-floor/wp-config.php
printf '%s  /run/php80-floor/wp-config.php\n' "$PHP80_WP_CONFIG_SHA256" | sha256sum --check --strict
test ! -e /var/www/html/wp-config.php
test ! -L /var/www/html/wp-config.php
cp /run/php80-floor/wp-config.php /var/www/html/wp-config.php
chmod 600 /var/www/html/wp-config.php
printf '%s  /var/www/html/wp-config.php\n' "$PHP80_WP_CONFIG_SHA256" | sha256sum --check --strict
exec /usr/local/bin/php -S 0.0.0.0:80 -t /var/www/html /opt/php80-floor/router.php
