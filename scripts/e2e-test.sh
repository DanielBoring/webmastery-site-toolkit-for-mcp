#!/usr/bin/env bash
set -Eeuo pipefail

export MSYS_NO_PATHCONV="${MSYS_NO_PATHCONV:-1}"

WORDPRESS_URL="${WORDPRESS_URL:-http://localhost}"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"
DEPENDENCY_POLICY="${DEPENDENCY_POLICY:-pinned}"
CONTAINER_PLUGIN_ROOT="/var/www/html/wp-content/plugins/${PLUGIN_SLUG}"
YOAST_PLUGIN_SLUG="${YOAST_PLUGIN_SLUG:-wordpress-seo}"
SEOPRESS_PLUGIN_SLUG="${SEOPRESS_PLUGIN_SLUG:-wp-seopress}"
E2E_ARTIFACTS_DIR="${E2E_ARTIFACTS_DIR:-e2e-artifacts}"
E2E_MANAGE_COMPOSE="${E2E_MANAGE_COMPOSE:-0}"
E2E_KEEP_COMPOSE="${E2E_KEEP_COMPOSE:-0}"
QA_MODE="${1:-all}"

# shellcheck source=scripts/qa-compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"

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
	echo "Installing verified WP-CLI ${WP_CLI_VERSION}..."
	compose exec -T wordpress bash "${CONTAINER_PLUGIN_ROOT}/scripts/compatibility-download.sh" \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
		"$WP_CLI_SHA512" sha512 /var/www/html/wp-cli-verified.phar
	compose exec -T wordpress install -m 755 /var/www/html/wp-cli-verified.phar /usr/local/bin/wp
	wp --info
}

baseline() {
	compose exec -T wordpress php "${CONTAINER_PLUGIN_ROOT}/scripts/compatibility-baselines.php" "$1"
}

load_dependencies() {
	WP_CLI_VERSION="$(baseline wp_cli)"
	WP_CLI_SHA512="$(baseline wp_cli_sha512)"
	if [ -n "${MCP_ADAPTER_ZIP:-}" ]; then
		if [ -z "${MCP_ADAPTER_SHA256:-}" ]; then
			echo "MCP_ADAPTER_ZIP overrides require MCP_ADAPTER_SHA256 from the same trusted release." >&2
			return 1
		fi
	else
		MCP_ADAPTER_ZIP="https://github.com/WordPress/mcp-adapter/releases/download/v$(baseline mcp_adapter)/mcp-adapter.zip"
		MCP_ADAPTER_SHA256="${MCP_ADAPTER_SHA256:-$(baseline mcp_adapter_sha256)}"
	fi
	case "$DEPENDENCY_POLICY" in
		pinned)
			YOAST_VERSION="$(baseline yoast)"
			SEOPRESS_VERSION="$(baseline seopress)"
			;;
		latest-seo)
			YOAST_VERSION=latest
			SEOPRESS_VERSION=latest
			;;
		*)
			echo "DEPENDENCY_POLICY must be pinned or latest-seo." >&2
			return 1
			;;
	esac
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
	# Apache directives must reach the container without host-shell expansion.
	# shellcheck disable=SC2016
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

configure_test_cron() {
	echo "Disabling request-triggered cron in the isolated QA installation..."
	wp config set DISABLE_WP_CRON true --raw
}

configure_application_passwords() {
	echo "Configuring E2E Application Password availability..."
	wp config set WP_ENVIRONMENT_TYPE local --type=constant
}

install_plugins() {
	local yoast_version_args=()
	local seopress_version_args=()
	if [ "$DEPENDENCY_POLICY" = "pinned" ]; then
		yoast_version_args=( --version="$YOAST_VERSION" )
		seopress_version_args=( --version="$SEOPRESS_VERSION" )
	fi
	echo "Installing E2E custom post type fixture..."
	compose exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
	compose exec -T wordpress cp "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/custom-post-types-fixture.php" /var/www/html/wp-content/mu-plugins/webmastery-mcp-e2e-cpts.php
	compose exec -T wordpress cp "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/site-kit-fixture.php" /var/www/html/wp-content/mu-plugins/webmastery-mcp-e2e-site-kit.php

	echo "Installing MCP Adapter..."
	compose exec -T wordpress bash "${CONTAINER_PLUGIN_ROOT}/scripts/compatibility-download.sh" \
		"$MCP_ADAPTER_ZIP" "$MCP_ADAPTER_SHA256" sha256 /var/www/html/mcp-adapter-verified.zip
	wp plugin install /var/www/html/mcp-adapter-verified.zip --activate --force

	echo "Installing Yoast SEO..."
	wp plugin install "$YOAST_PLUGIN_SLUG" "${yoast_version_args[@]}" --activate --force

	echo "Installing SEOPress..."
	wp plugin install "$SEOPRESS_PLUGIN_SLUG" "${seopress_version_args[@]}" --activate --force

	echo "Activating ${PLUGIN_SLUG}..."
	wp plugin activate "$PLUGIN_SLUG"
	wp plugin list --fields=name,status,version --format=table
}

run_ability_manifest() {
	echo "Running manifest-driven ability E2E tests..."
	wp eval-file "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/ability-runner.php"
	compose exec -T wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/media-download-runner.php"
}

run_trash_safety() {
	echo "Running isolated enabled/disabled trash safety boots..."
	local mode
	for mode in enabled disabled; do
		compose exec -T wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/trash-safety-runner.php" "$mode"
	done
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

	wp eval-file "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/site-kit-mcp-runner.php"
}

run_php_lint() {
	echo "Running PHP syntax checks..."
	compose exec -T wordpress bash -lc "php -l /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/webmastery-site-toolkit-for-mcp.php && find /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/includes /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e -name '*.php' -print0 | xargs -0 -n1 php -l"
}

run_parent_assignment_qa() (
	# Dedicated CPTs must not affect the normal 85-ability registration audit.
	local fixture="/var/www/html/wp-content/mu-plugins/wstm-issue106-parent.php"
	local boundaries=()
	local boundary
	local status
	trap 'compose exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/wstm-issue106-parent.php' EXIT
	compose exec -T wordpress cp "${CONTAINER_PLUGIN_ROOT}/tests/e2e/parent-assignment-fixture.php" "$fixture"
	status="$(compose exec -T wordpress curl --silent --show-error --output /tmp/wstm106-cli-response --write-out '%{http_code}' "http://localhost/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/parent-assignment-runner.php")"
	if [ "$status" != "403" ]; then
		echo "Parent runner must reject non-CLI requests before bootstrap (HTTP ${status})." >&2
		exit 1
	fi
	compose exec -T wordpress grep -Fxq 'CLI only.' /tmp/wstm106-cli-response
	if [ "$QA_MODE" = "contract" ] || [ "$QA_MODE" = "all" ]; then
		boundaries+=( direct ability )
	fi
	if [ "$QA_MODE" = "e2e" ] || [ "$QA_MODE" = "all" ]; then
		boundaries+=( http )
	fi
	for boundary in "${boundaries[@]}"; do
		compose exec -T -e WSTM106_BOUNDARY="$boundary" \
			wordpress php -d memory_limit=1G "${CONTAINER_PLUGIN_ROOT}/tests/e2e/parent-assignment-runner.php"
	done
)

run_debug_log_check() {
	echo "Checking WordPress debug log..."
	if ! compose exec -T wordpress test -f /var/www/html/wp-content/debug.log; then
		echo "No debug log found"
		return 0
	fi

	compose exec -T wordpress bash -lc "cat /var/www/html/wp-content/debug.log"
	if compose exec -T wordpress bash -lc "grep -Eiq 'fatal error|parse error|uncaught|php (fatal error|parse error|warning|notice|deprecated)|deprecated|warning|notice|error' /var/www/html/wp-content/debug.log"; then
		echo "Debug log contains WordPress/PHP problem entries"
		return 1
	fi
}

main() {
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

	if [ -n "${E2E_PACKAGE_ROOT:-}${E2E_PACKAGE_ZIP:-}" ]; then
		: "${E2E_PACKAGE_ROOT:?Package runtime requires E2E_PACKAGE_ROOT}"
		: "${E2E_PACKAGE_ZIP:?Package runtime requires E2E_PACKAGE_ZIP}"
		if [ "$E2E_MANAGE_COMPOSE" != "1" ] || [ "$E2E_ARTIFACTS_DIR" != "e2e-artifacts" ]; then
			echo "Package runtime requires managed Compose and the e2e-artifacts output mount." >&2
			exit 1
		fi
		host_php scripts/release-tools.php runtime-package "$E2E_PACKAGE_ROOT" "$E2E_PACKAGE_ZIP"
		echo "Release runtime plugin root: ${E2E_PACKAGE_ROOT} (verified against ${E2E_PACKAGE_ZIP})"
	fi

	trap cleanup_compose EXIT

	rm -rf "$E2E_ARTIFACTS_DIR"
	mkdir -p "$E2E_ARTIFACTS_DIR"
	start_compose
	wait_for_wordpress_files
	load_dependencies
	install_wp_cli
	configure_test_cron
	install_wordpress
	configure_http_auth_forwarding
	configure_application_passwords
	install_plugins
	compose exec -T wordpress rm -f /var/www/html/wp-content/debug.log

	if [ "$QA_MODE" = "contract" ] || [ "$QA_MODE" = "all" ]; then
		echo "Running Ability Contract QA..."
		run_php_lint
		run_ability_manifest
		echo "Running scheduling side-effect regressions..."
		compose exec -T wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/scheduling-runner.php"
		run_trash_safety
		echo "Running comment authorization and compatibility regressions..."
		compose exec -T -e WSTM105_BOUNDARY=direct wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/comments-runner.php"
		compose exec -T -e WSTM105_BOUNDARY=ability wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/comments-runner.php"
	fi

	if [ "$QA_MODE" = "e2e" ] || [ "$QA_MODE" = "all" ]; then
		echo "Running Full MCP E2E QA..."
		run_mcp_crud
		compose exec -T -e WSTM105_BOUNDARY=http wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/comments-runner.php"
	fi

	run_parent_assignment_qa
	run_debug_log_check

	echo "Docker QA (${QA_MODE}) completed successfully"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
	main "$@"
fi
