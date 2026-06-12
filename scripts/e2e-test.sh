#!/usr/bin/env bash
set -Eeuo pipefail

WORDPRESS_URL="${WORDPRESS_URL:-http://localhost}"
PLUGIN_SLUG="unlock-mcp-potential"
MCP_ADAPTER_ZIP="https://github.com/WordPress/mcp-adapter/releases/download/v0.5.0/mcp-adapter.zip"
E2E_ARTIFACTS_DIR="${E2E_ARTIFACTS_DIR:-e2e-artifacts}"

compose() {
	if docker compose version >/dev/null 2>&1; then
		docker compose "$@"
	else
		docker-compose "$@"
	fi
}

wp() {
	compose exec -T wordpress wp --allow-root "$@"
}

wait_for_wordpress_files() {
	local max_attempts=60
	local attempt=1

	echo "Waiting for WordPress files..."
	while [ "$attempt" -le "$max_attempts" ]; do
		if compose exec -T wordpress test -f /var/www/html/wp-load.php; then
			echo "WordPress files are ready"
			return 0
		fi

		echo "Attempt ${attempt}/${max_attempts}..."
		attempt=$(( attempt + 1 ))
		sleep 2
	done

	echo "WordPress files were not ready in time"
	return 1
}

install_wp_cli() {
	echo "Ensuring WP-CLI is installed..."
	compose exec -T wordpress bash -lc 'if ! command -v wp >/dev/null 2>&1; then curl -fsSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp; fi'
	wp --info
}

install_wordpress() {
	echo "Ensuring WordPress is installed..."
	if wp core is-installed; then
		echo "WordPress is already installed"
		return 0
	fi

	wp core install \
		--url="$WORDPRESS_URL" \
		--title="MCP E2E" \
		--admin_user=admin \
		--admin_password=password123 \
		--admin_email=admin@test.local \
		--skip-email
}

install_plugins() {
	echo "Installing MCP Adapter..."
	wp plugin install "$MCP_ADAPTER_ZIP" --activate --force

	echo "Activating ${PLUGIN_SLUG}..."
	wp plugin activate "$PLUGIN_SLUG"
	wp plugin list --fields=name,status,version --format=table
}

run_ability_manifest() {
	echo "Running manifest-driven ability E2E tests..."
	wp eval-file "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/ability-runner.php"
}

run_php_lint() {
	echo "Running PHP syntax checks..."
	compose exec -T wordpress bash -lc "php -l /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/unlock-mcp-potential.php && find /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/includes -name '*.php' -print0 | xargs -0 -n1 php -l"
}

run_debug_log_check() {
	echo "Checking WordPress debug log..."
	if ! compose exec -T wordpress test -f /var/www/html/wp-content/debug.log; then
		echo "No debug log found"
		return 0
	fi

	compose exec -T wordpress bash -lc "cat /var/www/html/wp-content/debug.log"
	if compose exec -T wordpress bash -lc "grep -Eiq 'fatal error|parse error|uncaught|error' /var/www/html/wp-content/debug.log"; then
		echo "Debug log contains fatal/error-level entries"
		return 1
	fi
}

echo "================================"
echo "E2E Test Suite: WordPress MCP Abilities"
echo "================================"

rm -rf "$E2E_ARTIFACTS_DIR"
mkdir -p "$E2E_ARTIFACTS_DIR"
wait_for_wordpress_files
install_wp_cli
install_wordpress
install_plugins
compose exec -T wordpress rm -f /var/www/html/wp-content/debug.log
run_php_lint
run_ability_manifest
run_debug_log_check

echo "E2E QA completed successfully"
