#!/usr/bin/env bash
set -Eeuo pipefail

export MSYS_NO_PATHCONV="${MSYS_NO_PATHCONV:-1}"

WORDPRESS_URL="${WORDPRESS_URL:-http://localhost}"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"
MCP_ADAPTER_ZIP="https://github.com/WordPress/mcp-adapter/releases/download/v0.5.0/mcp-adapter.zip"
YOAST_PLUGIN_SLUG="${YOAST_PLUGIN_SLUG:-wordpress-seo}"
SEOPRESS_PLUGIN_SLUG="${SEOPRESS_PLUGIN_SLUG:-wp-seopress}"
E2E_ARTIFACTS_DIR="${E2E_ARTIFACTS_DIR:-e2e-artifacts}"
E2E_MANAGE_COMPOSE="${E2E_MANAGE_COMPOSE:-0}"
E2E_KEEP_COMPOSE="${E2E_KEEP_COMPOSE:-0}"
QA_MODE="${1:-all}"

compose() {
	local project_args=()
	if [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then
		project_args=( --project-name "$COMPOSE_PROJECT_NAME" )
	fi

	docker compose "${project_args[@]}" "$@"
}

wp() {
	compose exec -T wordpress wp --allow-root "$@"
}

start_compose() {
	if [ "$E2E_MANAGE_COMPOSE" != "1" ]; then
		return 0
	fi

	echo "Starting Docker Compose stack..."
	export MYSQL_PORT="${MYSQL_PORT:-0}"
	export WORDPRESS_PORT="${WORDPRESS_PORT:-0}"
	compose down -v --remove-orphans
	compose up -d
}

cleanup_compose() {
	if [ "$E2E_MANAGE_COMPOSE" != "1" ] || [ "$E2E_KEEP_COMPOSE" = "1" ]; then
		return 0
	fi

	echo "Stopping Docker Compose stack..."
	compose down -v --remove-orphans
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

configure_http_auth_forwarding() {
	echo "Configuring E2E HTTP Authorization header forwarding..."
	compose exec -T wordpress bash -lc 'cat > /var/www/html/.htaccess <<'"'"'HTACCESS'"'"'
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
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
HTACCESS'
}

configure_application_passwords() {
	echo "Configuring E2E Application Password availability..."
	wp config set WP_ENVIRONMENT_TYPE local --type=constant
}

install_plugins() {
	echo "Installing E2E custom post type fixture..."
	compose exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
	compose exec -T wordpress cp "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/custom-post-types-fixture.php" /var/www/html/wp-content/mu-plugins/webmastery-mcp-e2e-cpts.php

	echo "Installing MCP Adapter..."
	wp plugin install "$MCP_ADAPTER_ZIP" --activate --force

	echo "Installing Yoast SEO..."
	wp plugin install "$YOAST_PLUGIN_SLUG" --activate --force

	echo "Installing SEOPress..."
	wp plugin install "$SEOPRESS_PLUGIN_SLUG" --activate --force

	echo "Activating ${PLUGIN_SLUG}..."
	wp plugin activate "$PLUGIN_SLUG"
	wp plugin list --fields=name,status,version --format=table
}

run_ability_manifest() {
	echo "Running manifest-driven ability E2E tests..."
	wp eval-file "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/ability-runner.php"
}

create_application_password() {
	local user="$1"
	local name="$2"

	wp user application-password create "$user" "$name" --porcelain | tail -n 1 | tr -d '[:space:]'
}

ensure_user() {
	local user="$1"
	local email="$2"
	local role="$3"

	if wp user get "$user" >/dev/null 2>&1; then
		wp user set-role "$user" "$role" >/dev/null
		return 0
	fi

	wp user create "$user" "$email" --role="$role" --user_pass=password123 >/dev/null
}

ensure_mcp_crud_users() {
	echo "Ensuring MCP CRUD E2E users..."
	ensure_user editor_test editor@test.local editor
	ensure_user subscriber_test subscriber@test.local subscriber
}

run_mcp_crud() {
	echo "Running protocol-level MCP CRUD E2E tests..."

	local editor_password
	local subscriber_password
	local endpoint

	ensure_mcp_crud_users
	editor_password="$(create_application_password editor_test "MCP CRUD E2E Editor")"
	subscriber_password="$(create_application_password subscriber_test "MCP CRUD E2E Subscriber")"
	endpoint="${MCP_CRUD_ENDPOINT:-http://localhost/wp-json/mcp/mcp-adapter-default-server}"

	compose exec -T \
		-e MCP_CRUD_ENDPOINT="$endpoint" \
		-e MCP_CRUD_EDITOR_USER="editor_test" \
		-e MCP_CRUD_EDITOR_PASSWORD="$editor_password" \
		-e MCP_CRUD_SUBSCRIBER_USER="subscriber_test" \
		-e MCP_CRUD_SUBSCRIBER_PASSWORD="$subscriber_password" \
		wordpress php "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/mcp-crud-runner.php"
}

run_php_lint() {
	echo "Running PHP syntax checks..."
	compose exec -T wordpress bash -lc "php -l /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/webmastery-site-toolkit-for-mcp.php && find /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/includes /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e -name '*.php' -print0 | xargs -0 -n1 php -l"
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
echo "Docker QA Suite: WordPress MCP Abilities"
echo "================================"

case "$QA_MODE" in
	contract|e2e|all)
		;;
	*)
		echo "Unknown QA mode: ${QA_MODE}" >&2
		echo "Usage: scripts/e2e-test.sh [contract|e2e|all]" >&2
		exit 1
		;;
esac

trap cleanup_compose EXIT

rm -rf "$E2E_ARTIFACTS_DIR"
mkdir -p "$E2E_ARTIFACTS_DIR"
start_compose
wait_for_wordpress_files
install_wp_cli
install_wordpress
configure_http_auth_forwarding
configure_application_passwords
install_plugins
compose exec -T wordpress rm -f /var/www/html/wp-content/debug.log

if [ "$QA_MODE" = "contract" ] || [ "$QA_MODE" = "all" ]; then
	echo "Running Ability Contract QA..."
	run_php_lint
	run_ability_manifest
fi

if [ "$QA_MODE" = "e2e" ] || [ "$QA_MODE" = "all" ]; then
	echo "Running Full MCP E2E QA..."
	run_mcp_crud
fi

run_debug_log_check

echo "Docker QA (${QA_MODE}) completed successfully"
