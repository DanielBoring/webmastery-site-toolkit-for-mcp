#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-runtime-tests-$$"
mkdir -p "$WORK/source with spaces/.github"
trap 'status=$?; if [[ "$status" != 0 ]]; then cat "$WORK/"*.log >&2; fi; rm -rf "$WORK"; exit "$status"' EXIT
cp -R scripts includes tests "$WORK/source with spaces/"
cp docker-compose*.yml webmastery-site-toolkit-for-mcp.php readme.txt LICENSE CHANGELOG.md "$WORK/source with spaces/"
cp .github/compatibility-versions.json "$WORK/source with spaces/.github/"
cd "$WORK/source with spaces"
# shellcheck source=scripts/qa-compose.sh
source scripts/qa-compose.sh
host_php tests/e2e/post-meta-authorization-runner.php --test-cleanup "$WORK/metadata-cleanup.json"
export RELEASE_ZIP
RELEASE_ZIP="$(bash scripts/build-release.sh)"
export TRACE="$WORK/commands"
export MSYS_NO_PATHCONV=1
export COMPOSE_PROJECT_NAME=release-runtime-fixture
export REQUIRE_CURRENT_PLUGIN_CHECK=1
export E2E_MANAGE_COMPOSE=1
unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
export COMPOSE_FILE=must-not-be-used.yml
IDENTITY="$(sha256sum "$RELEASE_ZIP")"

# Exercise the real wrapper, E2E main, package guards and verdict parser. Only
# external Docker operations are stubbed; no container, network or real site is used.
# shellcheck disable=SC2329 # Exported into the real orchestration subprocesses.
docker() {
	printf '%s\n' "$*" >> "$TRACE"
	[[ "$*" == "compose --project-name release-runtime-fixture -f docker-compose.yml -f docker-compose.release.yml "* ]] ||
		{ echo "Release runtime attempted checkout Compose configuration." >&2; return 91; }
	[[ "${E2E_PACKAGE_ROOT:-}" == "./build/release-runtime/webmastery-site-toolkit-for-mcp" ]] ||
		{ echo "Release runtime did not select its extracted package." >&2; return 92; }
	case "$*" in
		*" down -v --remove-orphans")
			if [[ "${FAIL_CLEANUP:-0}" == 1 && "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]; then
				echo "Fixture cleanup failure." >&2
				return 47
			fi
			;;
		*" up -d")
			( unset MSYS_NO_PATHCONV; php scripts/release-tools.php runtime-package "$E2E_PACKAGE_ROOT" "$RELEASE_ZIP" ) || return
			# Simulate Docker's nested bind placeholders; never contaminate Plugin Check.
			mkdir -p "$E2E_PACKAGE_ROOT/scripts" "$E2E_PACKAGE_ROOT/.github" "$E2E_PACKAGE_ROOT/tests" "$E2E_PACKAGE_ROOT/e2e-artifacts"
			: > "$E2E_PACKAGE_ROOT/scripts/compatibility-baselines.php"
			: > "$E2E_PACKAGE_ROOT/scripts/compatibility-download.sh"
			: > "$E2E_PACKAGE_ROOT/.github/compatibility-versions.json"
			if [[ "${MUTATE_ZIP:-0}" == 1 ]]; then
				# shellcheck disable=SC2016 # PHP variables, not shell substitutions.
				php -r '$z = new ZipArchive(); $z->open($argv[1]); $z->setArchiveComment("changed identity, same production files"); $z->close();' "$RELEASE_ZIP"
			fi
			;;
		*"compatibility-baselines.php "*)
			php scripts/compatibility-baselines.php "${@: -1}"
			;;
		*"/tests/e2e/ability-runner.php")
			case "${TAMPER_RUNTIME:-}" in
				production) printf 'changed' >> "$E2E_PACKAGE_ROOT/webmastery-site-toolkit-for-mcp.php" ;;
				file) printf 'unexpected' > "$E2E_PACKAGE_ROOT/tests/injected.php" ;;
				directory) mkdir "$E2E_PACKAGE_ROOT/vendor" ;;
				placeholder) printf 'changed' > "$E2E_PACKAGE_ROOT/scripts/compatibility-baselines.php" ;;
			esac
			;;
		*"plugin get plugin-check"*) printf '{"name":"plugin-check","version":"2.1.0","status":"active"}\n' ;;
		*"plugin check "*)
			if [[ "${CHECKER_FAILURE:-0}" == 1 ]]; then
				printf '[{"file":"readme.txt","line":0,"column":0,"type":"ERROR","code":"fixture_error","message":"Rejected"}]\n'
			else
				printf 'Success: Checks complete. No errors found.\n'
			fi
			;;
		*"docker-compose.release.yml cp "*)
			[[ "$*" == *" cp build/release-check/webmastery-site-toolkit-for-mcp/. wordpress:"* ]] ||
				{ echo "Plugin Check did not copy the pristine extraction." >&2; return 93; }
			php scripts/release-tools.php runtime-package build/release-check/webmastery-site-toolkit-for-mcp "$RELEASE_ZIP"
			;;
		*"test -f /var/www/html/wp-content/debug.log") return 1 ;;
		*"wp --allow-root core is-installed") return 1 ;;
		*"application-password create"*) printf 'fixture-password\n' ;;
		*"--write-out"*"/tests/e2e/parent-assignment-runner.php"|*"--write-out"*"/tests/e2e/post-meta-authorization-runner.php"|*"--write-out"*"/tests/e2e/error-contract-runner.php"|*"--write-out"*"/tests/e2e/metadata-batch-runner.php"|*"--write-out"*"/tests/e2e/seo-metadata-runner.php") printf '403' ;;
	esac
}
export -f docker

expect_failure() {
	local expected="$1"
	shift
	local status=0
	"$@" > "$WORK/failure.log" 2>&1 || status=$?
	if [[ "$status" == 0 ]]; then
		echo "Expected failure: $*" >&2
		exit 1
	fi
	grep -F "$expected" "$WORK/failure.log"
	FAILURE_STATUS="$status"
}

bash scripts/release-qa.sh > "$WORK/success.log" 2>&1
[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
for runner in ability-runner media-download-runner scheduling-runner trash-safety-runner mcp-crud-runner site-kit-mcp-runner parent-assignment-runner post-meta-authorization-runner error-contract-runner metadata-batch-runner seo-metadata-runner; do
	grep -F "/tests/e2e/${runner}.php" "$TRACE" > /dev/null
done
for boundary in direct ability http individual; do
	for runner in metadata-batch seo-metadata; do
		grep -F "WSTM110_BATCH_DISPOSABLE=1 -e WSTM110_BATCH_BOUNDARY=${boundary} wordpress php -d memory_limit=1G /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/${runner}-runner.php" "$TRACE" > /dev/null
	done
done
grep -F 'rm -f /var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php /var/www/html/wp-content/mu-plugins/wstm-issue110-http.php /var/www/html/wp-content/mu-plugins/wstm-issue110-seo.php /var/www/html/wp-content/mu-plugins/wstm-issue118-errors.php' "$TRACE" > /dev/null
for boundary in direct ability http; do
	grep -F "WSTM110_BOUNDARY=${boundary}" "$TRACE" > /dev/null
done
grep -F 'rm -f /var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php' "$TRACE" > /dev/null
grep -F 'plugin install plugin-check --version=2.1.0' "$TRACE"
grep -F 'plugin install plugin-check --activate --force' "$TRACE"
grep -F 'Unchanged release archive:' "$WORK/success.log"
[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
test -f e2e-artifacts/plugin-check-latest-verdict.json
php scripts/release-tools.php runtime-package build/release-check/webmastery-site-toolkit-for-mcp "$RELEASE_ZIP"

# Negative control: restoring the old checkout-mode selection must fail this test.
cp scripts/release-qa.sh "$WORK/fixed-release-qa.sh"
sed '/^export E2E_PACKAGE_ROOT=/d; /^export E2E_PACKAGE_ZIP=/d' "$WORK/fixed-release-qa.sh" > scripts/release-qa.sh
expect_failure 'Release runtime attempted checkout Compose configuration.' bash scripts/release-qa.sh
cp "$WORK/fixed-release-qa.sh" scripts/release-qa.sh

# Package guards run before even a cleanup/start Docker call.
export E2E_PACKAGE_ROOT="./build/release-runtime/webmastery-site-toolkit-for-mcp"
export E2E_PACKAGE_ZIP="$RELEASE_ZIP"
for bad in missing-root missing changed extra checkout absent-zip mismatched-zip; do
	rm -rf build/release-runtime
	php scripts/release-tools.php extract "$RELEASE_ZIP" build/release-runtime
	: > "$TRACE"
	case "$bad" in
		missing-root) rm -rf build/release-runtime ;;
		missing) rm "$E2E_PACKAGE_ROOT/readme.txt" ;;
		changed) printf 'changed' >> "$E2E_PACKAGE_ROOT/includes/class-posts.php" ;;
		extra) mkdir "$E2E_PACKAGE_ROOT/vendor"; printf 'unpackaged' > "$E2E_PACKAGE_ROOT/vendor/autoload.php" ;;
		checkout) E2E_PACKAGE_ROOT=. ;;
		absent-zip) E2E_PACKAGE_ZIP=missing.zip ;;
		mismatched-zip) printf 'changed' >> readme.txt ;;
	esac
	expect_failure 'ERROR ' bash scripts/e2e-test.sh all
	test ! -s "$TRACE"
	E2E_PACKAGE_ROOT="./build/release-runtime/webmastery-site-toolkit-for-mcp"
	E2E_PACKAGE_ZIP="$RELEASE_ZIP"
	cp "$REPO_ROOT/readme.txt" readme.txt
done
E2E_MANAGE_COMPOSE=0 expect_failure 'Package runtime requires managed Compose' bash scripts/e2e-test.sh all
E2E_ARTIFACTS_DIR=other-output expect_failure 'Package runtime requires managed Compose' bash scripts/e2e-test.sh all
test ! -s "$TRACE"
unset E2E_PACKAGE_ZIP
expect_failure 'requires E2E_PACKAGE_ZIP' bash scripts/e2e-test.sh all
unset E2E_PACKAGE_ROOT
export E2E_PACKAGE_ZIP="$RELEASE_ZIP"
expect_failure 'requires E2E_PACKAGE_ROOT' bash scripts/e2e-test.sh all
unset E2E_PACKAGE_ZIP

CHECKER_FAILURE=1 expect_failure 'Plugin Check reported 1 ERROR' bash scripts/release-qa.sh
: > "$TRACE"
FAIL_CLEANUP=1 expect_failure 'Fixture cleanup failure.' bash scripts/release-qa.sh
[[ "$FAILURE_STATUS" == 47 ]]
# shellcheck disable=SC2329 # Exported into the real checker subprocess.
php() {
	if [[ "${2:-}" == checker-verdict ]]; then
		[[ -z "${MSYS_NO_PATHCONV:-}" ]] || return 94
		echo "Fixture native host PHP failure." >&2
		return 23
	fi
	command php "$@"
}
export -f php
expect_failure 'Fixture native host PHP failure.' bash scripts/release-qa.sh
[[ "$FAILURE_STATUS" == 23 ]]
unset -f php
for tamper in production file directory placeholder; do
	TAMPER_RUNTIME="$tamper" expect_failure 'ERROR ' bash scripts/release-qa.sh
	[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
	php scripts/release-tools.php runtime-package build/release-check/webmastery-site-toolkit-for-mcp "$RELEASE_ZIP"
done
MUTATE_ZIP=1 expect_failure 'Original release ZIP identity changed during QA.' bash scripts/release-qa.sh

# Source-mode commands stay unchanged, including caller-supplied Compose settings.
docker() { printf '%s\n' "$*"; }
output="$(compose config)"
[[ "$output" == 'compose --project-name release-runtime-fixture config' ]]
echo 'PASS release orchestration, pristine checker, original ZIP identity, all runners, package guards and old-behavior negative control'
