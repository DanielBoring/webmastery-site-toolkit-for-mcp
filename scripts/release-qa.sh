#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"

php_run() {
	if command -v php >/dev/null 2>&1; then
		php "$@"
		return $?
	fi

	if ! command -v docker >/dev/null 2>&1; then
		echo "PHP is required. Install PHP or Docker to run PHP through the Composer image." >&2
		exit 1
	fi

	local mount_path
	if command -v cygpath >/dev/null 2>&1; then
		mount_path="$(cygpath -w "$(pwd)")"
	else
		mount_path="$(pwd)"
	fi

	docker run --rm -v "${mount_path}:/app" -w /app composer:2 php "$@"
}

ZIP_FILE="$(bash scripts/build-release.sh "$VERSION")"
php_run scripts/validate-release-package.php "$ZIP_FILE" "$VERSION"

if [ "${SKIP_PLUGIN_CHECK:-0}" = "1" ]; then
	echo "Skipping WordPress Plugin Check because SKIP_PLUGIN_CHECK=1."
	exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required for WordPress Plugin Check. Set SKIP_PLUGIN_CHECK=1 to run package validation only." >&2
	exit 1
fi

export E2E_MANAGE_COMPOSE=1
export E2E_KEEP_COMPOSE=1
trap 'docker compose down -v --remove-orphans >/dev/null 2>&1 || true' EXIT

bash scripts/e2e-test.sh contract
docker compose exec -T wordpress wp --allow-root plugin install plugin-check --activate --force
docker compose exec -T wordpress rm -rf "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}-package"
docker compose cp "build/${PLUGIN_SLUG}" "wordpress:/var/www/html/wp-content/plugins/${PLUGIN_SLUG}-package"
docker compose exec -T wordpress wp --allow-root plugin check "${PLUGIN_SLUG}-package" --slug="$PLUGIN_SLUG"
