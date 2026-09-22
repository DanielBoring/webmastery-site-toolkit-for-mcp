#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/e2e-test.sh
source "$root/scripts/e2e-test.sh"

compose() {
	case "$*" in
		*curl*"/tests/e2e/database-table-privacy-runner.php")
			printf '%s' "${WSTM111_MOCK_HTTP_STATUS:-403}"
			;;
		*"/tests/e2e/database-table-privacy-runner.php")
			assert_cron_isolated
			if [[ "$*" != "exec -T -e WSTM111_DISPOSABLE_SITE=1 -e WSTM111_PRIVACY_HTTP="* ]]; then
				echo 'Database privacy QA must explicitly opt in to disposable-site execution.' >&2
				exit 1
			fi
			;;
		*curl*"/tests/e2e/metadata-batch-runner.php"|*curl*"/tests/e2e/seo-metadata-runner.php")
			printf '%s' "${WSTM110_MOCK_HTTP_STATUS:-403}"
			;;
		*"/tests/e2e/metadata-batch-runner.php"|*"/tests/e2e/seo-metadata-runner.php")
			assert_cron_isolated
			if [[ "$*" != "exec -T -e WSTM110_BATCH_DISPOSABLE=1 -e WSTM110_BATCH_BOUNDARY="* ]]; then
				echo 'Metadata boundary QA must explicitly opt in to disposable-site execution.' >&2
				exit 1
			fi
			;;
		*curl*"/tests/e2e/error-contract-runner.php")
			printf '%s' "${WSTM118_MOCK_HTTP_STATUS:-403}"
			;;
		*"/tests/e2e/error-contract-runner.php")
			assert_cron_isolated
			if [[ "$*" != "exec -T -e WSTM118_DISPOSABLE=1 wordpress php "* ]]; then
				echo 'Error-contract QA must explicitly opt in to disposable-site execution.' >&2
				exit 1
			fi
			;;
	esac
}
wp() { printf '%s\n' "$*"; }
MCP_ADAPTER_ZIP=https://example.invalid/adapter.zip
MCP_ADAPTER_SHA256=unused-by-mocked-transport

DEPENDENCY_POLICY=pinned
YOAST_VERSION=28.5
SEOPRESS_VERSION=10.2
output="$(install_plugins)"
grep -Fx 'plugin install wordpress-seo --version=28.5 --activate --force' <<< "$output"
grep -Fx 'plugin install wp-seopress --version=10.2 --activate --force' <<< "$output"

DEPENDENCY_POLICY=latest-seo
YOAST_VERSION=latest
SEOPRESS_VERSION=latest
output="$(install_plugins)"
grep -Fx 'plugin install wordpress-seo --activate --force' <<< "$output"
grep -Fx 'plugin install wp-seopress --activate --force' <<< "$output"
if grep -q -- '--version=' <<< "$output"; then
	echo 'Floating SEO must omit --version rather than request a release literally named latest.' >&2
	exit 1
fi
assert_cron_isolated() {
	if [ "$cron_configured" != 1 ]; then
		echo 'Request-triggered cron must be disabled before bootstrap and fixtures.' >&2
		exit 1
	fi
}

# Exercise main's ordering without Docker, network, or filesystem mutations.
rm() { :; }
mkdir() { :; }
start_compose() { :; }
cleanup_compose() { :; }
wait_for_wordpress_files() { :; }
load_dependencies() { :; }
install_wp_cli() { :; }
wp() {
	if [ "$*" != 'config set DISABLE_WP_CRON true --raw' ]; then
		echo "Unexpected bootstrap command: $*" >&2
		exit 1
	fi
	cron_configured=$(( cron_configured + 1 ))
}
install_wordpress() { assert_cron_isolated; }
configure_http_auth_forwarding() { assert_cron_isolated; }
configure_application_passwords() { assert_cron_isolated; }
install_plugins() { assert_cron_isolated; }
run_php_lint() { assert_cron_isolated; }
run_ability_manifest() { assert_cron_isolated; }
run_trash_safety() { assert_cron_isolated; }
run_mcp_crud() { assert_cron_isolated; }
run_parent_assignment_qa() { assert_cron_isolated; }
run_post_meta_authorization_qa() {
	assert_cron_isolated
	metadata_stage_calls=$(( metadata_stage_calls + 1 ))
}
run_debug_log_check() { assert_cron_isolated; }
for QA_MODE in contract e2e all; do
	cron_configured=0
	metadata_stage_calls=0
	main >/dev/null
	assert_cron_isolated
	if [ "$metadata_stage_calls" != 1 ]; then
		echo "Metadata authorization QA must run exactly once in ${QA_MODE} mode." >&2
		exit 1
	fi
done
if output="$(WSTM118_MOCK_HTTP_STATUS=200 run_error_contract_qa 2>&1)"; then
	echo 'Error-contract QA must reject an HTTP-accessible runner.' >&2
	exit 1
fi
grep -Fx 'Error-contract runner must reject non-CLI requests (HTTP 200).' <<< "$output"
if output="$(WSTM110_MOCK_HTTP_STATUS=200 run_metadata_boundary_qa 2>&1)"; then
	echo 'Metadata boundary QA must reject an HTTP-accessible runner.' >&2
	exit 1
fi
grep -Fx 'Metadata boundary runner must reject non-CLI requests (HTTP 200).' <<< "$output"
if output="$(WSTM111_MOCK_HTTP_STATUS=200 run_database_privacy_qa native 2>&1)"; then
	echo 'Database privacy QA must reject an HTTP-accessible runner.' >&2
	exit 1
fi
grep -Fx 'Database privacy runner must reject non-CLI requests (HTTP 200).' <<< "$output"
echo 'Compatibility dependency policy and QA bootstrap tests passed without Docker or network.'
