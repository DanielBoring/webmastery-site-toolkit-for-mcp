#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-checker-tests-$$"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT
source "$REPO_ROOT/scripts/release-plugin-check.sh"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"
PACKAGE_ROOT="original-extracted-zip"
TRACE="$WORK/commands"

# Record arguments without invoking Docker, Compose, WP-CLI, or any network.
docker() {
	printf '%s\n' "$*" >> "$TRACE"
	if [[ "$*" == *"wordpress rm -rf "* || "$*" == *"wordpress mkdir -p "* ]]; then
		[[ "${MSYS_NO_PATHCONV:-}" == "1" ]] || { echo "Container path conversion was not disabled." >&2; return 1; }
	fi
	if [[ "$*" == *"plugin get plugin-check"* ]]; then
		printf '{"name":"plugin-check","version":"2.1.0","status":"active"}\n'
	elif [[ "$*" == *"plugin check "* ]]; then
		case "${MOCK_CHECK_RESULT:-clean}" in
			error)
				printf '[{"file":"readme.txt","line":0,"column":0,"type":"ERROR","code":"outdated_tested_upto_header","message":"Tested up to is outdated"}]\n'
				;;
			warnings)
				printf '[{"file":"includes/class-seo.php","line":726,"column":13,"type":"WARNING","code":"WordPress.DB.SlowDBQuery.slow_db_query_meta_query","message":"Possible slow query"}]\n'
				;;
			malformed) printf '{"unexpected":"shape"}\n' ;;
			truncated) printf '[{"type":"ERROR"}' ;;
			unknown) printf '[{"file":"readme.txt","line":0,"column":0,"type":"NOTICE","code":"unknown","message":"Unknown result"}]\n' ;;
			empty) printf '' ;;
			misleading) printf 'Success: Everything probably passed.\n' ;;
			*) printf 'Success: Checks complete. No errors found.\n' ;;
		esac
	fi
	return 0
}
cd "$WORK"
check_package 2.1.0
grep -Fx 'compose exec -T wordpress wp --allow-root plugin install plugin-check --version=2.1.0 --activate --force' "$TRACE"
grep -Fx 'compose cp original-extracted-zip/webmastery-site-toolkit-for-mcp/. wordpress:/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package' "$TRACE"
test -s e2e-artifacts/plugin-check-2.1.0.json
: > "$TRACE"
check_package latest
grep -Fx 'compose exec -T wordpress wp --allow-root plugin install plugin-check --activate --force' "$TRACE"
grep -Fx 'compose exec -T wordpress rm -rf /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package' "$TRACE"
grep -Fx 'compose exec -T wordpress mkdir -p /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package' "$TRACE"
grep -Fx 'compose cp original-extracted-zip/webmastery-site-toolkit-for-mcp/. wordpress:/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package' "$TRACE"
if grep -F -- '--version=' "$TRACE"; then
	echo "Latest Plugin Check must omit WP-CLI's --version option." >&2
	exit 1
fi
test -s e2e-artifacts/plugin-check-latest.json
grep -F 'plugin check webmastery-site-toolkit-for-mcp-package --slug=webmastery-site-toolkit-for-mcp' "$TRACE"
MOCK_CHECK_RESULT=warnings check_package latest
grep '"warnings": 1' e2e-artifacts/plugin-check-latest-verdict.json
for failure in error malformed truncated unknown empty misleading; do
	if MOCK_CHECK_RESULT="$failure" check_package latest; then
		echo "Plugin Check accepted ${failure} output despite successful WP-CLI exit." >&2
		exit 1
	fi
done
echo "PASS pinned/latest arguments, evidence, retained warnings and fail-closed ERROR/malformed reports"
