#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-checker-tests-$$"
mkdir -p "$REPO_ROOT/build"
mkdir "$WORK"
trap 'rm -rf "$WORK"' EXIT
source "$REPO_ROOT/scripts/release-plugin-check.sh"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"
TRACE="$WORK/commands"
export MSYS_NO_PATHCONV=1

# Record arguments without invoking Docker, Compose, WP-CLI, or any network.
compose() {
	printf '%s\n' "$*" >> "$TRACE"
	if [[ "$*" == *"wordpress mkdir "* ]]; then
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
	if [[ -n "${MOCK_FAIL_PHASE:-}" && "$*" == *"$MOCK_FAIL_PHASE"* ]]; then
		return 29
	fi
	return 0
}
cd "$WORK"
mkdir e2e-artifacts
check_package 2.1.0
grep -Fx 'exec -T -e WP_CLI_CACHE_DIR=/qa-artifacts/parent-cache/wp-cli wordpress php /var/www/html/.qa-bin/wp --url=http://localhost plugin install plugin-check --version=2.1.0 --activate --force' "$TRACE"
grep -Fx 'exec -T wordpress cp -R /qa-package-pristine/. /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package-2-1-0' "$TRACE"
test -s e2e-artifacts/plugin-check-2.1.0.json
: > "$TRACE"
check_package latest
grep -Fx 'exec -T -e WP_CLI_CACHE_DIR=/qa-artifacts/parent-cache/wp-cli wordpress php /var/www/html/.qa-bin/wp --url=http://localhost plugin install plugin-check --activate --force' "$TRACE"
grep -Fx 'exec -T wordpress mkdir /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package-latest' "$TRACE"
grep -Fx 'exec -T wordpress cp -R /qa-package-pristine/. /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package-latest' "$TRACE"
if grep -E -- 'rm -rf|--allow-root|^cp ' "$TRACE"; then
	echo "Plugin Check must not delete prior data, execute WP-CLI as root, or copy runtime helpers." >&2
	exit 1
fi
if grep -F -- '--version=' "$TRACE"; then
	echo "Latest Plugin Check must omit WP-CLI's --version option." >&2
	exit 1
fi
test -s e2e-artifacts/plugin-check-latest.json
grep -F 'plugin check webmastery-site-toolkit-for-mcp-package-latest --slug=webmastery-site-toolkit-for-mcp' "$TRACE"
cp e2e-artifacts/plugin-check-latest-results.txt prior-results.txt
if check_package latest; then
	echo "Plugin Check reused existing output." >&2
	exit 1
fi
cmp prior-results.txt e2e-artifacts/plugin-check-latest-results.txt
mkdir warnings-artifacts
export E2E_ARTIFACTS_DIR="$WORK/warnings-artifacts"
MOCK_CHECK_RESULT=warnings check_package latest
grep '"warnings": 1' "$E2E_ARTIFACTS_DIR/plugin-check-latest-verdict.json"
for failure in error malformed truncated unknown empty misleading; do
	mkdir "$WORK/$failure-artifacts"
	export E2E_ARTIFACTS_DIR="$WORK/$failure-artifacts"
	if MOCK_CHECK_RESULT="$failure" check_package latest; then
		echo "Plugin Check accepted ${failure} output despite successful WP-CLI exit." >&2
		exit 1
	fi
done
for phase in 'plugin install' 'plugin get' 'wordpress mkdir' 'wordpress cp' 'plugin check'; do
	mkdir "$WORK/failure-${phase// /-}"
	export E2E_ARTIFACTS_DIR="$WORK/failure-${phase// /-}"
	status=0
	MOCK_FAIL_PHASE="$phase" check_package latest || status=$?
	[[ "$status" == 29 ]] || { echo "Plugin Check lost an original command failure." >&2; exit 1; }
	test ! -s "$E2E_ARTIFACTS_DIR/plugin-check-latest-verdict.json"
done
# Keep host-only path conversion scoped and propagate native PHP failures exactly.
# shellcheck disable=SC2329
php() {
	[[ -z "${MSYS_NO_PATHCONV:-}" ]] || return 94
	return 23
}
mkdir native-failure-artifacts
export E2E_ARTIFACTS_DIR="$WORK/native-failure-artifacts"
status=0
check_package latest || status=$?
[[ "$status" == 23 ]]
[[ "$MSYS_NO_PATHCONV" == 1 ]]
unset -f php
echo "PASS pinned/latest arguments, evidence, retained warnings and fail-closed ERROR/malformed reports"
