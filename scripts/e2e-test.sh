#!/usr/bin/env bash
set +x
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
DATABASE_PRIVACY_ARTIFACTS=()
E2E_SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source=scripts/qa-compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"
# shellcheck source=scripts/destructive-retention.sh
source "$(dirname "${BASH_SOURCE[0]}")/destructive-retention.sh"
# shellcheck source=scripts/qa-http-quiet.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-http-quiet.sh"

wp() {
	qa_wp "$@"
}

start_compose() {
	wstm116_require_no_retention || return $?
	if [ "$E2E_MANAGE_COMPOSE" != "1" ]; then
		return 0
	fi

	echo "Starting Docker Compose stack..."
	export MYSQL_PORT="${MYSQL_PORT:-0}"
	export WORDPRESS_PORT="${WORDPRESS_PORT:-0}"
	compose down -v --remove-orphans || return $?
	compose up -d
}

capture_legacy_artifacts() {
	local container name status private_directory
	local summaries=(
		e2e-summary.json issue125-permission-summary.json issue125-mcp-summary.json
		mcp-crud-summary.json error-contract.json taxonomy-write-summary.json
		scheduling-regression.json
		comments-all.json comments-direct.json comments-ability.json comments-http.json
		parent-assignment-all.json parent-assignment-direct.json parent-assignment-ability.json parent-assignment-http.json
		post-meta-authorization-direct.json post-meta-authorization-ability.json post-meta-authorization-http.json
		media-download-baseline.json media-download-fixed.json patch-html-summary.json
		metadata-batch-direct.json metadata-batch-ability.json metadata-batch-http.json metadata-batch-individual.json
		seo-metadata-direct.json seo-metadata-ability.json seo-metadata-http.json seo-metadata-individual.json
		trash-safety-enabled.json trash-safety-disabled.json
		database-table-privacy-native.json database-table-privacy-http.json
	)
	container="$(compose ps -q wordpress)" || return $?
	if [[ -z "$container" ]]; then
		[[ "${#DATABASE_PRIVACY_ARTIFACTS[@]}" == 0 ]] || {
			echo "Required database privacy capture has no owned source container." >&2
			return 1
		}
		return 0
	fi
	for name in "${summaries[@]}"; do
		status=0
		compose exec -T wordpress test -e "/qa-artifacts/$name" || status=$?
		if [[ "$status" == 1 ]]; then
			if [[ " ${DATABASE_PRIVACY_ARTIFACTS[*]} " == *" $name "* ]]; then
				echo "Required database privacy artifact is missing: $name" >&2
				return 1
			fi
			continue
		fi
		[[ "$status" == 0 ]] || return "$status"
		qa_export_artifact "/qa-artifacts/$name" "$E2E_ARTIFACTS_DIR/$name" || return $?
	done
	private_directory="${WSTM_QA_PRIVATE_OUTPUT:-}/legacy"
	for name in media-server-baseline.jsonl media-server-fixed.jsonl media-server-stderr.log; do
		status=0
		compose exec -T wordpress test -s "/qa-artifacts/$name" || status=$?
		if [[ "$status" == 1 ]]; then continue; fi
		[[ "$status" == 0 && -n "${WSTM_QA_PRIVATE_OUTPUT:-}" ]] || return 1
		[[ ! -L "$private_directory" && ( ! -e "$private_directory" || -d "$private_directory" ) ]] || return 1
		if [[ ! -d "$private_directory" ]]; then
			(umask 077; mkdir "$private_directory") || return $?
		fi
		qa_export_artifact "/qa-artifacts/$name" "$private_directory/$name" || return $?
	done
	for name in fresh-install.private.json fresh-install.before.private.json fresh-install.inserts.private.json fresh-install.after.private.json; do
		status=0
		compose exec -T wordpress test -e "/qa-artifacts/$name" || status=$?
		if [[ "$status" == 1 ]]; then continue; fi
		[[ "$status" == 0 && -n "${WSTM_QA_PRIVATE_OUTPUT:-}" ]] || return 1
		[[ ! -L "$private_directory" && ( ! -e "$private_directory" || -d "$private_directory" ) ]] || return 1
		if [[ ! -d "$private_directory" ]]; then
			(umask 077; mkdir "$private_directory") || return $?
		fi
		qa_export_artifact "/qa-artifacts/$name" "$private_directory/$name" || return $?
	done
}

cleanup_compose() {
	local original=$? cleanup=0
	if ! wstm116_require_no_retention; then
		if [[ "$original" != 0 ]]; then return "$original"; fi
		return 1
	fi
	if ! capture_legacy_artifacts; then
		echo "Legacy summary capture failed; owned stack retained instead of destroying evidence." >&2
		if ! wstm116_arm_retention "$WSTM_PRODUCT_RUN" "${E2E_SOURCE_IDENTITY:0:40}"; then
			if [[ "$original" != 0 ]]; then return "$original"; fi
			return 1
		fi
		if [[ "$original" != 0 ]]; then return "$original"; fi
		return 1
	fi
	if [ "$E2E_MANAGE_COMPOSE" != "1" ] || [ "$E2E_KEEP_COMPOSE" = "1" ]; then
		return 0
	fi

	echo "Stopping Docker Compose stack..."
	compose down -v --remove-orphans || cleanup=$?
	printf 'Compose cleanup: original_status=%s cleanup_status=%s\n' "$original" "$cleanup"
	if [[ "$original" != 0 ]]; then return "$original"; fi
	return "$cleanup"
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

configure_owned_url() {
	local binding port
	binding="$(compose port wordpress 80)" || return $?
	[[ "$binding" =~ ^127\.0\.0\.1:([0-9]{1,5})$ ]] || {
		echo "Owned QA HTTP must have exactly one IPv4 loopback binding." >&2
		return 1
	}
	port="${BASH_REMATCH[1]}"
	((10#$port >= 1 && 10#$port <= 65535)) || return 1
	WORDPRESS_URL="http://127.0.0.1:${port}"
	export WORDPRESS_URL
}

install_wp_cli() {
	echo "Installing verified WP-CLI ${WP_CLI_VERSION}..."
	compose exec -T wordpress mkdir -p /var/www/html/.qa-bin
	compose exec -T wordpress mkdir -p -m 0700 /qa-artifacts/parent-cache/wp-cli
	compose exec -T wordpress bash "${CONTAINER_PLUGIN_ROOT}/scripts/compatibility-download.sh" \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
		"$WP_CLI_SHA512" sha512 /var/www/html/.qa-bin/wp
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
		wp69-seo)
			YOAST_VERSION="$(baseline wp69_yoast)"
			SEOPRESS_VERSION="$(baseline wp69_seopress)"
			;;
		latest-seo)
			YOAST_VERSION=latest
			SEOPRESS_VERSION=latest
			;;
		*)
			echo "DEPENDENCY_POLICY must be pinned, wp69-seo or latest-seo." >&2
			return 1
			;;
	esac
}

install_wordpress() {
	local password
	echo "Ensuring WordPress is installed..."
	if wp core is-installed; then
		qa_quiet_install '' --verify
		return $?
	fi

	password="$(host_php -r 'echo bin2hex(random_bytes(32));')" || return $?
	[[ "$password" =~ ^[a-f0-9]{64}$ ]] || return 1
	qa_quiet_install "$password" || return $?
	wp rewrite structure '/%postname%/'
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
	if [[ "$DEPENDENCY_POLICY" == pinned || "$DEPENDENCY_POLICY" == wp69-seo ]]; then
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

run_post_meta_authorization_qa() (
	local fixture="/var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php"
	local boundaries=()
	local boundary
	local status
	trap 'compose exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php' EXIT
	compose exec -T wordpress cp "${CONTAINER_PLUGIN_ROOT}/tests/e2e/post-meta-authorization-fixture.php" "$fixture"
	status="$(compose exec -T wordpress curl --silent --show-error --output /tmp/wstm110-cli-response --write-out '%{http_code}' "http://localhost/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/post-meta-authorization-runner.php")"
	if [ "$status" != "403" ]; then
		echo "Metadata runner must reject non-CLI requests (HTTP ${status})." >&2
		exit 1
	fi
	compose exec -T wordpress grep -Fxq 'CLI only.' /tmp/wstm110-cli-response
	if [ "$QA_MODE" = "contract" ] || [ "$QA_MODE" = "all" ]; then
		boundaries+=( direct ability )
	fi
	if [ "$QA_MODE" = "e2e" ] || [ "$QA_MODE" = "all" ]; then
		boundaries+=( http )
	fi
	for boundary in "${boundaries[@]}"; do
		compose exec -T -e WSTM110_BOUNDARY="$boundary" \
			wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/post-meta-authorization-runner.php"
	done
)

run_error_contract_qa() {
	# Register the selected HTTP stage in the shell that owns final capture.
	if [ "$QA_MODE" = "e2e" ] || [ "$QA_MODE" = "all" ]; then
		DATABASE_PRIVACY_ARTIFACTS+=( database-table-privacy-http.json )
	fi
	(
	local fixture="/var/www/html/wp-content/mu-plugins/wstm-issue118-errors.php"
	local status
	trap 'compose exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/wstm-issue118-errors.php' EXIT
	compose exec -T wordpress cp "${CONTAINER_PLUGIN_ROOT}/tests/e2e/error-contract-fixture.php" "$fixture" || exit $?
	status="$(compose exec -T wordpress curl --silent --show-error --output /tmp/wstm118-cli-response --write-out '%{http_code}' "http://localhost/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/error-contract-runner.php")" || exit $?
	if [ "$status" != "403" ]; then
		echo "Error-contract runner must reject non-CLI requests (HTTP ${status})." >&2
		exit 1
	fi
	compose exec -T wordpress grep -Fxq 'CLI only.' /tmp/wstm118-cli-response || exit $?
	compose exec -T -e WSTM118_DISPOSABLE=1 wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/error-contract-runner.php" || exit $?
	if [ "$QA_MODE" = "e2e" ] || [ "$QA_MODE" = "all" ]; then
		run_database_privacy_qa http || exit $?
	fi
	)
}

run_database_privacy_qa() {
	local boundary="$1"
	local http=0
	local status
	if [ "$boundary" = "http" ]; then
		http=1
	elif [ "$boundary" != "native" ]; then
		echo "Unknown database privacy boundary: ${boundary}" >&2
		return 1
	fi
	status="$(compose exec -T wordpress curl --silent --show-error --output /tmp/wstm111-cli-response --write-out '%{http_code}' "http://localhost/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/database-table-privacy-runner.php")"
	if [ "$status" != "403" ]; then
		echo "Database privacy runner must reject non-CLI requests (HTTP ${status})." >&2
		return 1
	fi
	compose exec -T wordpress grep -Fxq 'CLI only.' /tmp/wstm111-cli-response
	DATABASE_PRIVACY_ARTIFACTS+=( "database-table-privacy-${boundary}.json" )
	compose exec -T -e WSTM111_DISPOSABLE_SITE=1 -e WSTM111_PRIVACY_HTTP="$http" \
		-e WSTM111_PRIVACY_ARTIFACT="/qa-artifacts/database-table-privacy-${boundary}.json" \
		wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/database-table-privacy-runner.php"
}

run_metadata_boundary_qa() (
	local boundaries=()
	local boundary runner status
	trap 'compose exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php /var/www/html/wp-content/mu-plugins/wstm-issue110-http.php /var/www/html/wp-content/mu-plugins/wstm-issue110-seo.php /var/www/html/wp-content/mu-plugins/wstm-issue118-errors.php' EXIT
	compose exec -T wordpress cp "${CONTAINER_PLUGIN_ROOT}/tests/e2e/post-meta-authorization-fixture.php" /var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php
	compose exec -T wordpress cp "${CONTAINER_PLUGIN_ROOT}/tests/e2e/metadata-batch-http-fixture.php" /var/www/html/wp-content/mu-plugins/wstm-issue110-http.php
	compose exec -T wordpress cp "${CONTAINER_PLUGIN_ROOT}/tests/e2e/seo-metadata-fixture.php" /var/www/html/wp-content/mu-plugins/wstm-issue110-seo.php
	compose exec -T wordpress cp "${CONTAINER_PLUGIN_ROOT}/tests/e2e/error-contract-fixture.php" /var/www/html/wp-content/mu-plugins/wstm-issue118-errors.php
	for runner in metadata-batch seo-metadata; do
		status="$(compose exec -T wordpress curl --silent --show-error --output /tmp/wstm110-boundary-cli-response --write-out '%{http_code}' "http://localhost/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/${runner}-runner.php")"
		if [ "$status" != "403" ]; then
			echo "Metadata boundary runner must reject non-CLI requests (HTTP ${status})." >&2
			exit 1
		fi
		compose exec -T wordpress grep -Fxq 'CLI only.' /tmp/wstm110-boundary-cli-response
	done
	if [ "$QA_MODE" = "contract" ] || [ "$QA_MODE" = "all" ]; then
		boundaries+=( direct ability )
	fi
	if [ "$QA_MODE" = "e2e" ] || [ "$QA_MODE" = "all" ]; then
		boundaries+=( http individual )
	fi
	for boundary in "${boundaries[@]}"; do
		for runner in metadata-batch seo-metadata; do
			compose exec -T -e WSTM110_BATCH_DISPOSABLE=1 -e WSTM110_BATCH_BOUNDARY="$boundary" \
				wordpress php -d memory_limit=1G "${CONTAINER_PLUGIN_ROOT}/tests/e2e/${runner}-runner.php"
		done
	done
	compose exec -T -e WSTM202_DISPOSABLE=1 wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/site-kit-defaults-runner.php"
)

run_destructive_safety_qa() (
	: "${COMPOSE_PROJECT_NAME:?Destructive QA requires an explicitly owned disposable Compose project}"
	wstm116_require_no_retention || exit $?
	[[ "$E2E_ARTIFACTS_DIR" =~ ^[a-zA-Z0-9_-]+$ ]] || { echo "Unsafe destructive artifact directory." >&2; exit 1; }
	local token source_sha directory container_directory acquired=0 first_failure=0 cleanup_verified=1
	local mode days boundary status
	local boundaries=()
	token="$(host_php -r 'echo bin2hex(random_bytes(16));')"
	source_sha="$(git rev-parse HEAD)"
	[[ "$token" =~ ^[a-f0-9]{32}$ && "$source_sha" =~ ^[a-f0-9]{40}$ ]] || exit 1
	directory="${E2E_ARTIFACTS_DIR}/destructive-${token}"
	container_directory="/qa-artifacts/destructive-${token}"
	mkdir "$directory" || exit $?
	compose exec -T wordpress mkdir -m 0700 "$container_directory" || exit $?
	( set -o noclobber; : > "$directory/stage.log" ) || exit $?
	stage_command() {
		compose exec -T -e WSTM116_STAGE_DISPOSABLE=1 wordpress php \
			"${CONTAINER_PLUGIN_ROOT}/tests/e2e/destructive-safety-stage.php" "$1" "$token"
	}
	# shellcheck disable=SC2329 # Invoked by this subshell's EXIT trap.
	stage_finish() {
		local original=$? restoration=0 retention=1
		trap - EXIT
		if [[ "$acquired" == 1 ]]; then
			stage_command restore 2>&1 | tee -a "$directory/stage.log" || restoration=$?
			if [[ "$restoration" == 0 && "$cleanup_verified" == 1 ]]; then
				wstm116_clear_retention "$token" "$source_sha" 2>&1 | tee -a "$directory/stage.log" && retention=0
			fi
		fi
		printf 'source=%s original_status=%s restoration_status=%s retention_status=%s owner=%s project=%s\n' "$source_sha" "$original" "$restoration" "$retention" "$token" "$COMPOSE_PROJECT_NAME" | tee -a "$directory/stage.log"
		if [[ "$original" != 0 ]]; then exit "$original"; fi
		if [[ "$restoration" != 0 ]]; then exit "$restoration"; fi
		exit "$retention"
	}
	wstm116_arm_retention "$token" "$source_sha" 2>&1 | tee -a "$directory/stage.log" || exit $?
	trap stage_finish EXIT
	stage_command acquire 2>&1 | tee -a "$directory/stage.log" || exit $?
	acquired=1
	# Audit native registration and denial persistence before any test-only MU ability.
	compose exec -T -e WSTM116_STAGE_DISPOSABLE=1 -e WSTM116_SOURCE_SHA="$source_sha" wordpress php \
		"${CONTAINER_PLUGIN_ROOT}/tests/e2e/destructive-safety-preflight.php" "$container_directory/preflight.json" "$token" \
		2>&1 | tee -a "$directory/stage.log" || exit $?
	qa_export_artifact "$container_directory/preflight.json" "$directory/preflight.json" || exit $?
	stage_command prepare 2>&1 | tee -a "$directory/stage.log" || exit $?
	if [[ "$QA_MODE" == contract || "$QA_MODE" == all ]]; then boundaries+=( direct ability ); fi
	if [[ "$QA_MODE" == e2e || "$QA_MODE" == all ]]; then boundaries+=( http individual ); fi
	[[ "${#boundaries[@]}" != 0 ]] || exit 1
	for mode in enabled disabled; do
		days=30
		[[ "$mode" != disabled ]] || days=0
		status=0
		stage_command "$mode" 2>&1 | tee -a "$directory/stage.log" || status=$?
		if [[ "$status" != 0 ]]; then
			if [[ "$first_failure" != 0 ]]; then exit "$first_failure"; fi
			exit "$status"
		fi
		for boundary in "${boundaries[@]}"; do
			status=0
			cleanup_verified=0
			compose exec -T -e WSTM116_DISPOSABLE=1 -e WSTM116_BOUNDARY="$boundary" \
				-e WSTM116_EXPECT_TRASH_DAYS="$days" -e WSTM116_STAGE_TOKEN="$token" \
				-e WSTM116_SOURCE_SHA="$source_sha" -e WSTM116_ARTIFACT="$container_directory/$mode-$boundary.json" \
				wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/destructive-safety-runner.php" \
				2>&1 | tee -a "$directory/stage.log" || status=$?
			if [[ "$first_failure" == 0 && "$status" != 0 ]]; then first_failure="$status"; fi
				if ! qa_export_artifact "$container_directory/$mode-$boundary.json" "$directory/$mode-$boundary.json"; then
					if [[ "$first_failure" != 0 ]]; then exit "$first_failure"; fi
					exit 1
				fi
			if host_php "$E2E_SCRIPT_ROOT/destructive-cleanup-proof.php" "$directory/$mode-$boundary.json" "$source_sha" "$boundary" "$days" 2>&1 | tee -a "$directory/stage.log"; then
				cleanup_verified=1
			else
				if [[ "$first_failure" != 0 ]]; then exit "$first_failure"; fi
				exit 1
			fi
		done
	done
	exit "$first_failure"
)

run_input_schema_qa() (
	: "${COMPOSE_PROJECT_NAME:?Schema QA requires an explicitly owned disposable Compose project}"
	wstm116_require_no_retention || exit $?
	[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ && "$E2E_ARTIFACTS_DIR" =~ ^[a-zA-Z0-9_-]+$ ]] || {
		echo "Invalid schema project or artifact directory identity." >&2; exit 1
	}
	[[ -d "$E2E_ARTIFACTS_DIR" && ! -L "$E2E_ARTIFACTS_DIR" ]] || {
		echo "Schema artifacts require an existing, unlinked output directory." >&2; exit 1
	}
	local token source_sha directory container_directory root production_root selected acquired=0 cleanup_verified=0
	local first_failure=0 status boundary response optin
	local boundaries=()
	case "$QA_MODE" in
		contract) boundaries=( direct permission ability ) ;;
		e2e) boundaries=( http individual ) ;;
		all) boundaries=( direct permission ability http individual ) ;;
		*) echo "Unknown schema QA mode." >&2; exit 1 ;;
	esac
	selected="$(IFS=,; echo "${boundaries[*]}")"
	root="$(cd "$E2E_SCRIPT_ROOT/.." && pwd)"
	production_root="${E2E_PACKAGE_ROOT:-$root}"
	token="$(host_php -r 'echo bin2hex(random_bytes(16));')"
	source_sha="$(cd "$root" && git rev-parse HEAD)"
	[[ "$token" =~ ^[a-f0-9]{32}$ && "$source_sha" =~ ^[a-f0-9]{40}$ ]] || {
		echo "Invalid schema owner or source identity." >&2; exit 1
	}
	directory="${E2E_ARTIFACTS_DIR}/input-schema-${token}"
	container_directory="/qa-artifacts/input-schema-${token}"
	mkdir "$directory" || exit $?
	compose exec -T wordpress mkdir -m 0700 "$container_directory" || exit $?
	( set -o noclobber; : > "$directory/stage.log" ) || exit $?
	schema_stage() {
		compose exec -T -e WSTM126_STAGE_DISPOSABLE=1 wordpress php \
			"${CONTAINER_PLUGIN_ROOT}/tests/e2e/input-schema-stage.php" "$1" "$token" "$source_sha" \
			"$COMPOSE_PROJECT_NAME" "$container_directory" "$selected"
	}
	# shellcheck disable=SC2329 # This subshell owns its EXIT trap, not its caller's.
	schema_finish() {
		local original=$? restoration=0 finalization=1 retirement=1
		trap - EXIT
		if [[ "$acquired" == 1 ]]; then
			schema_stage restore 2>&1 | tee -a "$directory/stage.log" || restoration=$?
			if [[ "$restoration" == 0 && "$cleanup_verified" == 1 ]]; then
				finalization=0
				schema_stage finalize 2>&1 | tee -a "$directory/stage.log" || finalization=$?
				if [[ "$finalization" == 0 ]]; then
					wstm116_clear_retention "$token" "$source_sha" 2>&1 | tee -a "$directory/stage.log" && retirement=0
				fi
			fi
		fi
		printf 'schema source=%s original_status=%s restoration_status=%s finalization_status=%s retention_status=%s owner=%s project=%s\n' \
			"$source_sha" "$original" "$restoration" "$finalization" "$retirement" "$token" "$COMPOSE_PROJECT_NAME" | tee -a "$directory/stage.log"
		if [[ "$original" != 0 ]]; then exit "$original"; fi
		if [[ "$restoration" != 0 ]]; then exit "$restoration"; fi
		if [[ "$finalization" != 0 ]]; then exit "$finalization"; fi
		exit "$retirement"
	}
	wstm116_arm_retention "$token" "$source_sha" 2>&1 | tee -a "$directory/stage.log" || exit $?
	trap schema_finish EXIT
	schema_stage acquire 2>&1 | tee -a "$directory/stage.log" || exit $?
	acquired=1
	# These refusals run before installing the schema opt-in or creating credentials.
	for optin in '' true; do
		if compose exec -T -e WSTM126_DISPOSABLE="$optin" wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/input-schema-runner.php" \
			> "$directory/cli-refusal-${optin:-missing}.log" 2>&1; then
			echo "Schema runner accepted missing or invalid disposable opt-in." >&2; exit 1
		fi
		grep -Fq 'Set WSTM126_DISPOSABLE=1' "$directory/cli-refusal-${optin:-missing}.log" || exit 1
	done
	response="$(compose exec -T wordpress curl --max-time 2 --silent --show-error --write-out $'\n%{http_code}' \
		"http://localhost/wp-content/plugins/${PLUGIN_SLUG}/tests/e2e/input-schema-runner.php")" || exit $?
	if [[ "$response" != $'CLI only.\n403' ]]; then
		printf '%s' "$response" | host_php -r 'echo "Unexpected schema refusal body SHA256: ",hash("sha256",stream_get_contents(STDIN)),PHP_EOL;' >> "$directory/stage.log"
		echo "Schema runner did not refuse HTTP before bootstrap." >&2; exit 1
	fi
	printf 'HTTP runner refusal: 403 CLI only; before schema opt-in and credentials.\n' >> "$directory/stage.log"
	schema_stage prepare 2>&1 | tee -a "$directory/stage.log" || exit $?
	for boundary in "${boundaries[@]}"; do
		status=0
		cleanup_verified=0
		compose exec -T -e WSTM126_DISPOSABLE=1 -e WSTM126_BOUNDARY="$boundary" \
			-e WSTM126_STAGE_TOKEN="$token" -e WSTM126_PROJECT="$COMPOSE_PROJECT_NAME" \
			-e WSTM126_JOURNAL_DIR=/tmp/wstm126-stage/invocations \
			-e WSTM126_SOURCE_SHA="$source_sha" -e WSTM126_ARTIFACT="$container_directory/$boundary.json" \
			wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/input-schema-runner.php" \
			2>&1 | tee -a "$directory/stage.log" || status=$?
		if [[ "$first_failure" == 0 && "$status" != 0 ]]; then first_failure="$status"; fi
		if ! qa_export_artifact "$container_directory/$boundary.json" "$directory/$boundary.json"; then
			if [[ "$first_failure" != 0 ]]; then exit "$first_failure"; fi
			exit 1
		fi
		if host_php "$E2E_SCRIPT_ROOT/input-schema-proof.php" cleanup "$directory/$boundary.json" "$token" "$source_sha" \
			"$COMPOSE_PROJECT_NAME" "$boundary" "$production_root" "$root" 2>&1 | tee -a "$directory/stage.log"; then
			cleanup_verified=1
		else
			if [[ "$first_failure" != 0 ]]; then exit "$first_failure"; fi
			exit 1
		fi
		if ! host_php "$E2E_SCRIPT_ROOT/input-schema-proof.php" outcome "$directory/$boundary.json" "$token" "$source_sha" \
			"$COMPOSE_PROJECT_NAME" "$boundary" "$production_root" "$root" 2>&1 | tee -a "$directory/stage.log"; then
			if [[ "$first_failure" == 0 ]]; then first_failure=1; fi
		fi
	done
	exit "$first_failure"
)

verify_product_identity() {
	case "$1" in
		source)
			[[ "$(host_php "$E2E_SCRIPT_ROOT/source-identity.php")" == "$2" ]] ||
				{ echo "Source changed during product QA; refusing acceptance." >&2; return 1; }
			;;
		package)
			host_php "$E2E_SCRIPT_ROOT/release-tools.php" runtime-package-after "$E2E_PACKAGE_ROOT" "$E2E_PACKAGE_ZIP"
			;;
		*)
			echo "Unknown product identity kind." >&2
			return 1
			;;
	esac
}

# PHP expressions must reach the interpreter without Bash expansion.
# shellcheck disable=SC2016
run_product_qa() (
	umask 077
	wstm116_require_no_retention || exit $?
	local directory="${WSTM_QA_PRIVATE_OUTPUT:?Owned private host output required}/product-${WSTM_PRODUCT_RUN}"
	local kind=source identity lane source_sha stage runner status cleanup_ready=0 summary_ready=0 production_root
	mkdir "$directory" || exit $?
	source_sha="$(git rev-parse HEAD)" || exit $?
	if [[ -n "${E2E_PACKAGE_ZIP:-}" ]]; then
		kind=package
		production_root="$E2E_PACKAGE_ROOT"
		identity="$(host_php -r '$hash = hash_file("sha256", $argv[1]); if (!is_string($hash)) { throw new RuntimeException("Original ZIP identity unavailable."); } echo $hash;' "$E2E_PACKAGE_ZIP")" || exit $?
	else
		production_root="$(pwd -P)"
		identity="$(host_php "$E2E_SCRIPT_ROOT/source-identity.php")" || exit $?
	fi
	compose exec -T -e WSTM_INITIAL_SITE_URL=http://localhost wordpress php \
		"$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" --verify \
		> "$directory/fresh-install-check.private.log" 2>&1 || exit $?
	lane="$(compose exec -T wordpress php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')" || exit $?
	if [[ -n "${WSTM_QA_EXPECTED_PHP:-}" && "$lane" != "$WSTM_QA_EXPECTED_PHP" ]]; then
		echo "Actual CLI PHP lane does not match the requested runtime." >&2
		exit 1
	fi
	if [[ -n "${WSTM_QA_EXPECTED_WORDPRESS:-}" ]]; then
		local wordpress_version
		wordpress_version="$(qa_wp core version)" || exit $?
		[[ "$wordpress_version" == "$WSTM_QA_EXPECTED_WORDPRESS" ]] || {
			echo "Actual WordPress version does not match the requested runtime." >&2; exit 1;
		}
	fi
	host_php "$E2E_SCRIPT_ROOT/product-context.php" "$kind" "$identity" "$lane" > "$directory/context.private.json" || exit $?
	compose exec -T wordpress php -r '
		require "/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/product-runtime-context.php";
		$bytes = stream_get_contents(STDIN, 4097);
		if (!is_string($bytes)) { throw new RuntimeException("Context input failed."); }
		$data = Wstm167_Product_Context::decode($bytes);
		Wstm167_Product_Context::validate($data, getenv("WSTM_PRODUCT_RUN"), getenv("WSTM_PRODUCT_TOKEN"), posix_geteuid(), PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION);
		umask(0077);
		$handle = fopen("/qa-artifacts/context.json", "xb");
		if ($handle === false || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fclose($handle) ||
			file_get_contents("/qa-artifacts/context.json") !== $bytes) {
			throw new RuntimeException("Exclusive context write/readback failed.");
		}
		' < "$directory/context.private.json" > "$directory/context-install.private.log" 2>&1 || exit $?
	compose exec -T wordpress php "$CONTAINER_PLUGIN_ROOT/tests/e2e/product-context-marker.php" acquire \
		> "$directory/marker-acquire.private.log" 2>&1 || exit $?
	compose exec -T wordpress php "$CONTAINER_PLUGIN_ROOT/tests/e2e/product-fixture-lifecycle.php" install \
		> "$directory/fixture-install.private.log" 2>&1 || exit $?
	wstm116_arm_retention "$WSTM_PRODUCT_RUN" "$source_sha" > "$directory/retention.private.log" 2>&1 || exit $?
	# shellcheck disable=SC2329
	product_finish() {
		local original=$? cleanup=0
		trap - EXIT
		if [[ "$cleanup_ready" == 1 ]]; then
			compose exec -T wordpress php "$CONTAINER_PLUGIN_ROOT/tests/e2e/product-fixture-lifecycle.php" remove \
				> "$directory/fixture-remove.private.log" 2>&1 || cleanup=$?
			if [[ "$cleanup" == 0 ]]; then
				compose exec -T wordpress php "$CONTAINER_PLUGIN_ROOT/tests/e2e/product-context-marker.php" retire \
					> "$directory/marker-retire.private.log" 2>&1 || cleanup=$?
			fi
			if [[ "$cleanup" == 0 ]]; then
				compose exec -T wordpress php -r '
					$_SERVER["HTTP_HOST"] = "localhost";
					require "/var/www/html/wp-load.php";
					$count = 0;
					foreach (wp_get_abilities() as $ability) {
						if (strpos($ability->get_name(), "webmastery-site-toolkit-for-mcp/") === 0) { ++$count; }
					}
					if ($count !== 85) { throw new RuntimeException("Native toolkit inventory was not restored."); }
					' > "$directory/native-inventory.private.log" 2>&1 || cleanup=$?
			fi
			if [[ "$cleanup" == 0 ]]; then
				wstm116_clear_retention "$WSTM_PRODUCT_RUN" "$source_sha" >> "$directory/retention.private.log" 2>&1 || cleanup=$?
			fi
		else
			echo "Product resource cleanup is unresolved; retaining the owned stack and private evidence." >&2
			cleanup=1
		fi
		if [[ "$original" == 0 && "$cleanup" == 0 && "$summary_ready" == 1 ]]; then
			if [[ -e "$E2E_ARTIFACTS_DIR/product-qa.summary.json" || -L "$E2E_ARTIFACTS_DIR/product-qa.summary.json" ]]; then
				echo "Product summary destination is occupied; prior evidence preserved." >&2
				cleanup=1
			else
				mv "$directory/summary.verified.private.json" "$E2E_ARTIFACTS_DIR/product-qa.summary.json" || cleanup=$?
			fi
		fi
		printf 'Product QA: original_status=%s cleanup_status=%s\n' "$original" "$cleanup"
		if [[ "$original" != 0 ]]; then exit "$original"; fi
		exit "$cleanup"
	}
	trap product_finish EXIT
	status="$(compose exec -T wordpress curl --max-time 20 --silent --show-error \
		-D /qa-artifacts/product-runtime-headers.private -o /qa-artifacts/product-runtime-body.private \
		-w '%{http_code}' -H "X-WSTM-QA-Token: $WSTM_PRODUCT_TOKEN" http://localhost/wp-json/wstm167-qa/v1/runtime \
		2> "$directory/runtime-request.private.log")" || exit $?
	qa_export_artifact /qa-artifacts/product-runtime-headers.private "$directory/runtime-headers.private" || exit $?
	qa_export_artifact /qa-artifacts/product-runtime-body.private "$directory/runtime.verified.private.json" || exit $?
	[[ "$status" == 200 ]] || { echo "Protected product runtime observation failed; private originals retained." >&2; exit 1; }
	host_php -r '
		require $argv[1];
		$bytes = Wstm_Product_Summary::read($argv[2]);
		$record = json_decode(Wstm_Product_Summary::read($argv[3]), true, 16, JSON_THROW_ON_ERROR);
		Wstm_Product_Summary::runtime($bytes, $record);
		$digest = hash_file("sha256", $argv[4] . "/includes/class-posts.php");
		if (!is_string($digest) || $record["loaded_posts_sha256"] !== $digest) {
			throw new RuntimeException("HTTP loaded Posts bytes do not match selected production.");
		}
		' "$E2E_SCRIPT_ROOT/product-summary.php" "$directory/context.private.json" "$directory/runtime.verified.private.json" \
		"$production_root" > "$directory/runtime-check.private.log" 2>&1 || exit $?
	for stage in untrusted bounded; do
		cleanup_ready=0
		if [[ "$stage" == bounded ]]; then
			compose exec -T -e WSTM_INITIAL_SITE_URL=http://localhost wordpress php \
				"$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" --verify \
				> "$directory/fresh-bounded-check.private.log" 2>&1 || exit $?
		fi
		runner=untrusted-content-runner.php
		if [[ "$stage" == bounded ]]; then runner=bounded-list-benchmark.php; fi
		status=0
		compose exec -T wordpress php "$CONTAINER_PLUGIN_ROOT/tests/e2e/$runner" \
			> "$directory/$stage.stdout.private.log" 2> "$directory/$stage.stderr.private.log" || status=$?
		printf '%s\n' "$status" > "$directory/$stage.exit.private"
		compose exec -T wordpress php /qa-parent-support/scripts/product-artifacts.php export "$stage" \
			> "$directory/$stage.capture.private.bin" 2> "$directory/$stage.export.private.log" ||
			{ if [[ "$status" != 0 ]]; then exit "$status"; fi; exit 1; }
		host_php "$E2E_SCRIPT_ROOT/product-artifacts.php" import "$stage" "$directory/$stage.capture.private.bin" \
			"$directory/$stage" > "$directory/$stage.import.private.log" 2>&1 ||
			{ if [[ "$status" != 0 ]]; then exit "$status"; fi; exit 1; }
		if [[ "$stage" == bounded ]]; then
			host_php tests/e2e/product-evidence-verifier.php --cleanup bounded "$directory/bounded" "$directory/context.private.json" \
				> "$directory/bounded-cleanup.verified.private.json" 2> "$directory/bounded-cleanup.private.log" ||
				{ if [[ "$status" != 0 ]]; then exit "$status"; fi; exit 1; }
		fi
		compose exec -T wordpress php "$CONTAINER_PLUGIN_ROOT/tests/e2e/product-live-cleanup.php" "$stage" \
			> "$directory/$stage-live.verified.private.json" 2> "$directory/$stage-live.private.log" ||
			{ if [[ "$status" != 0 ]]; then exit "$status"; fi; exit 1; }
		cleanup_ready=1
		if [[ "$status" != 0 ]]; then exit "$status"; fi
		host_php tests/e2e/product-evidence-verifier.php "$stage" "$directory/$stage" "$directory/context.private.json" \
			> "$directory/$stage.verified.private.json" 2> "$directory/$stage.verify.private.log" || exit $?
	done
	verify_product_identity "$kind" "$identity" > "$directory/identity-final.private.log" 2>&1 || exit $?
	host_php "$E2E_SCRIPT_ROOT/product-summary.php" "$directory" > "$directory/summary.verified.private.json" \
		2> "$directory/summary.private.log" || exit $?
	summary_ready=1
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
		contract|e2e|all|setup)
			;;
		*)
			echo "Unknown QA mode: ${QA_MODE}" >&2
			echo "Usage: scripts/e2e-test.sh [contract|e2e|all|setup]" >&2
			exit 1
			;;
	esac

	: "${COMPOSE_PROJECT_NAME:?Runtime QA requires an explicitly owned disposable Compose project}"
	[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "Invalid disposable Compose project name." >&2; exit 1; }
	wstm116_require_no_retention || return $?
	if [ -n "${E2E_PACKAGE_ROOT:-}${E2E_PACKAGE_ZIP:-}" ]; then
		: "${E2E_PACKAGE_ROOT:?Package runtime requires E2E_PACKAGE_ROOT}"
		: "${E2E_PACKAGE_ZIP:?Package runtime requires E2E_PACKAGE_ZIP}"
		if [ "$E2E_MANAGE_COMPOSE" != "1" ]; then
			echo "Package runtime requires managed Compose." >&2
			exit 1
		fi
		host_php scripts/release-tools.php runtime-package "$E2E_PACKAGE_ROOT" "$E2E_PACKAGE_ZIP"
		echo "Release runtime plugin root: ${E2E_PACKAGE_ROOT} (verified against ${E2E_PACKAGE_ZIP})"
	fi

	if [[ "$E2E_ARTIFACTS_DIR" != e2e-artifacts && "$E2E_ARTIFACTS_DIR" != "e2e-artifacts-${COMPOSE_PROJECT_NAME}" ]]; then
		echo "Artifact output must be e2e-artifacts or the exact project-specific output directory." >&2
		return 1
	fi
	if [[ -e "$E2E_ARTIFACTS_DIR" || -L "$E2E_ARTIFACTS_DIR" ]]; then
		echo "Artifact output already exists; preserve it and choose a fresh disposable project." >&2
		return 1
	fi
	(umask 077; mkdir "$E2E_ARTIFACTS_DIR") || return $?
	E2E_SOURCE_IDENTITY="$(host_php scripts/source-identity.php)" || return $?
	[[ "$E2E_SOURCE_IDENTITY" =~ ^[a-f0-9]{64}$ ]] || return 1
	trap cleanup_compose EXIT
	if [ -n "${E2E_PACKAGE_ROOT:-}" ]; then
		host_php scripts/release-tools.php prepare-runtime-mounts "$E2E_PACKAGE_ROOT" "$E2E_PACKAGE_ZIP" || return $?
	fi
	start_compose || return $?
	wait_for_wordpress_files
	configure_owned_url || return $?
	qa_initialize_artifacts || return $?
	load_dependencies
	install_wp_cli
	configure_test_cron
	install_wordpress
	configure_http_auth_forwarding
	configure_application_passwords
	install_plugins
	compose exec -T wordpress rm -f /var/www/html/wp-content/debug.log
	if [[ "$QA_MODE" == setup ]]; then
		echo "Owned WordPress setup complete; product acceptance tests have not run."
		return 0
	fi
	run_product_qa

	if [ "$QA_MODE" = "contract" ] || [ "$QA_MODE" = "all" ]; then
		echo "Running Ability Contract QA..."
		run_php_lint
		run_ability_manifest
		run_database_privacy_qa native
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

	run_destructive_safety_qa
	run_parent_assignment_qa
	run_post_meta_authorization_qa
	run_error_contract_qa
	run_metadata_boundary_qa
	run_input_schema_qa
	run_debug_log_check

	echo "Docker QA (${QA_MODE}) completed successfully"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
	main "$@"
fi
