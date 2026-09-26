#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-runtime-tests-$$"
export WORK
if [[ "$(uname -s)" != Linux ]]; then
	echo 'BLOCKED: positive authority and source/original-ZIP outer mocks require native POSIX; native-Windows refusal is separate.' >&2
	exit 78
fi
WSTM108_MOCK_MATRIX_PHP="$(readlink -e -- "$(command -v php)")" || {
	echo 'BLOCKED: cannot resolve the pinned native matrix PHP executable.' >&2
	exit 78
}
WSTM108_MOCK_PHP="${WSTM108_HOST_PHP-$WSTM108_MOCK_MATRIX_PHP}"
for php_binary in "$WSTM108_MOCK_MATRIX_PHP" "$WSTM108_MOCK_PHP"; do
	[[ -n "$php_binary" && "$php_binary" == /* && "$php_binary" == "$(readlink -e -- "$php_binary")" \
		&& -f "$php_binary" && -x "$php_binary" && "${php_binary##*/}" =~ ^php([0-9]+(\.[0-9]+)?)?$ \
		&& "$(stat -c %u -- "$php_binary")" == 0 ]] \
		&& (( ( 8#$(stat -c %a -- "$php_binary") & 0022 ) == 0 )) || {
		echo 'BLOCKED: host and matrix PHP must be canonical trusted native PHP executables; explicit selection has no fallback.' >&2
		exit 78
	}
done
export WSTM108_HOST_PHP="$WSTM108_MOCK_PHP"
readonly WSTM108_HOST_PHP WSTM108_MOCK_PHP WSTM108_MOCK_MATRIX_PHP
"$WSTM108_MOCK_PHP" -r 'require $argv[1]; Wstm108_Export::require_host();' "$REPO_ROOT/scripts/untrusted-export.php"
mkdir -p "$WORK/source with spaces/.github"
trap 'status=$?; if [[ "$status" != 0 ]]; then echo "Failed release-runtime evidence retained at $WORK" >&2; else rm -rf "$WORK"; fi; exit "$status"' EXIT
cp -R scripts includes tests "$WORK/source with spaces/"
cp docker-compose*.yml webmastery-site-toolkit-for-mcp.php readme.txt LICENSE CHANGELOG.md "$WORK/source with spaces/"
cp .github/compatibility-versions.json "$WORK/source with spaces/.github/"
cp -R .github/workflows "$WORK/source with spaces/.github/"
mkdir -p "$WORK/authority" "$WORK/docker-data" "$WORK/bin" "$WORK/untrusted-private"
chmod 700 "$WORK/authority" "$WORK/untrusted-private"
cp tests/unit/fixtures/untrusted-docker "$WORK/bin/docker"
cp tests/unit/fixtures/untrusted-host-process "$WORK/bin/php"
cp tests/unit/fixtures/untrusted-host-process "$WORK/bin/bash"
chmod 700 "$WORK/bin/docker" "$WORK/bin/php" "$WORK/bin/bash"
export WSTM108_MOCK_ONLY=1 WSTM108_MOCK_ROOT="$WORK" WSTM108_MOCK_CHECKOUT="$WORK/source with spaces"
export WSTM108_MOCK_ORIGIN="$REPO_ROOT"
export WSTM108_MOCK_LIVE="$WORK/untrusted-private" WSTM108_HOST_AUTHORITY_ROOT="$WORK/authority"
WSTM108_MOCK_BASH="$(command -v bash)"
export WSTM108_MOCK_PHP WSTM108_MOCK_MATRIX_PHP WSTM108_MOCK_BASH
export PATH="$WORK/bin:$PATH"
php tests/unit/fixtures/untrusted-host-boundaries.php install-mock
export GITHUB_OUTPUT="$WORK/authority/synthetic-publication.private"
export GITHUB_RUN_ID=123 GITHUB_RUN_ATTEMPT=1 GITHUB_JOB=synthetic-package
( umask 077; set -o noclobber; : > "$GITHUB_OUTPUT" )
cd "$WORK/source with spaces"
# The untrusted stage binds its real producer to committed source bytes, not the
# ancestor checkout. This disposable Git fixture is never pushed or published.
git init --quiet
git config --local core.autocrlf false
git -c core.autocrlf=false add scripts includes tests .github docker-compose*.yml webmastery-site-toolkit-for-mcp.php readme.txt LICENSE CHANGELOG.md
git -c user.name='QA fixture' -c user.email='qa@example.test' -c core.autocrlf=false commit --quiet -m 'Create isolated runtime fixture' -m 'Co-authored-by: Copilot App <223556219+Copilot@users.noreply.github.com>'
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
# external Docker operations and explicit copied endpoint/peer/executable and
# interruption sites are synthetic; no container, network or real site is used.
# shellcheck disable=SC2329 # Exported into the real orchestration subprocesses.
docker() {
	case "$*" in
		*"untrusted-content-stage.php "*|*"untrusted-content-runner.php")
			command php tests/unit/fixtures/untrusted-docker.php "$@"
			return
			;;
	esac
	printf '%s\n' "$*" >> "$TRACE"
	if [[ "${SOURCE_MODE:-0}" == 1 ]]; then
		[[ "$*" == "compose --project-name release-runtime-fixture "* && "$*" != *"docker-compose.release.yml"* ]] || return 90
	else
		[[ "$*" == "compose --project-name release-runtime-fixture -f docker-compose.yml -f docker-compose.release.yml "* ]] ||
			{ echo "Release runtime attempted checkout Compose configuration." >&2; return 91; }
		[[ "${E2E_PACKAGE_ROOT:-}" == "./build/release-runtime/webmastery-site-toolkit-for-mcp" ]] ||
			{ echo "Release runtime did not select its extracted package." >&2; return 92; }
	fi
	case "$*" in
		*" down -v --remove-orphans")
			rm -f "$WORK/private-backup" "$WORK/readonly-probe" "$WORK/retained-attachment" "$WORK/retained-file" "$WORK/retained-marker"
			rm -f "$WSTM108_MOCK_LIVE/private-state" "$WSTM108_MOCK_LIVE/private-journal" "$WSTM108_MOCK_LIVE/probe" "$WSTM108_MOCK_LIVE/loader" "$WSTM108_MOCK_LIVE/actor" "$WSTM108_MOCK_LIVE/attachment" "$WSTM108_MOCK_LIVE/file" "$WSTM108_MOCK_LIVE/original-wire.private" "$WSTM108_MOCK_LIVE/original-wire-metadata.private.json"
			if [[ "${FAIL_CLEANUP:-0}" == 1 && "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]; then
				echo "Fixture cleanup failure." >&2
				return 47
			fi
			;;
		*" up -d")
			[[ "${SOURCE_MODE:-0}" != 1 ]] || return 0
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
		*"destructive-safety-stage.php acquire "*)
			printf 'private-config-fixture\n' > "$WORK/private-backup"
			printf 'owned-readonly-probe\n' > "$WORK/readonly-probe"
			printf 'stage-acquired\n' >> "$TRACE"
			;;
		*"destructive-safety-stage.php restore "*)
			[[ "${FAIL_STAGE_RESTORE:-0}" != 1 ]] || return 47
			rm -f "$WORK/private-backup" "$WORK/readonly-probe"
			;;
		*"destructive-safety-runner.php")
			local arg artifact source boundary days complete=true cleanup=true
			for arg in "$@"; do
				case "$arg" in
					WSTM116_ARTIFACT=*) artifact="${arg#*=}"; artifact="${artifact#*/webmastery-site-toolkit-for-mcp/}" ;;
					WSTM116_SOURCE_SHA=*) source="${arg#*=}" ;;
					WSTM116_BOUNDARY=*) boundary="${arg#*=}" ;;
					WSTM116_EXPECT_TRASH_DAYS=*) days="${arg#*=}" ;;
				esac
			done
			if [[ "${FAIL_RUNNER_CLEANUP:-0}" != 0 ]]; then
				printf 'owned-attachment\n' > "$WORK/retained-attachment"
				printf 'original-file-bytes\n' > "$WORK/retained-file"
				printf 'owned-upload-proof\n' > "$WORK/retained-marker"
				complete=false
				cleanup='"refused attachment cleanup"'
			fi
			if [[ "${FAIL_RUNNER_CLEANUP:-0}" == missing ]]; then
				printf '{"completed":true,"source_sha":"%s","boundary":"%s","trash_days":%s,"cleanup":{"fixture":true}}\n' "$source" "$boundary" "$days" > "$artifact"
			else
				printf '{"completed":true,"cleanup_complete":%s,"source_sha":"%s","boundary":"%s","trash_days":%s,"cleanup":{"fixture":%s}}\n' "$complete" "$source" "$boundary" "$days" "$cleanup" > "$artifact"
			fi
			[[ "${FAIL_RUNNER:-0}" != 1 ]] || return 44
			[[ "${FAIL_RUNNER_CLEANUP:-0}" == 0 ]] || return 45
			;;
		*"compatibility-baselines.php "*)
			php scripts/compatibility-baselines.php "${@: -1}"
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
		*"test -f /var/www/html/wp-content/debug.log")
			# Tamper after untrusted provenance so the final package guard owns this check.
			case "${TAMPER_RUNTIME:-}" in
				production) printf 'changed' >> "$E2E_PACKAGE_ROOT/webmastery-site-toolkit-for-mcp.php" ;;
				file) printf 'unexpected' > "$E2E_PACKAGE_ROOT/tests/injected.php" ;;
				directory) mkdir "$E2E_PACKAGE_ROOT/vendor" ;;
				placeholder) printf 'changed' > "$E2E_PACKAGE_ROOT/scripts/compatibility-baselines.php" ;;
			esac
			return 1
			;;
		*"wp --allow-root core is-installed") return 1 ;;
		*"application-password create"*) printf 'fixture-password\n' ;;
		*"--write-out"*"/tests/e2e/parent-assignment-runner.php"|*"--write-out"*"/tests/e2e/post-meta-authorization-runner.php"|*"--write-out"*"/tests/e2e/error-contract-runner.php"|*"--write-out"*"/tests/e2e/metadata-batch-runner.php"|*"--write-out"*"/tests/e2e/seo-metadata-runner.php"|*"--write-out"*"/tests/e2e/database-table-privacy-runner.php") printf '403' ;;
	esac
}
export -f docker

expect_failure() {
	local expected="$1"
	shift
	local status=0
	"$@" > "$WORK/failure.log" 2>&1 || status=$?
	if [[ "$status" == 0 ]]; then
		printf 'FAIL expected nonzero child exit; child_exit=%s\n' "$status" >&2
		exit 1
	fi
	grep -F "$expected" "$WORK/failure.log" || {
		local oracle_status=$?
		printf 'FAIL missing expected failure diagnostic; child_exit=%s\n' "$status" >&2
		exit "$oracle_status"
	}
	FAILURE_STATUS="$status"
}

(
	unset COMPOSE_PROJECT_NAME
	: > "$TRACE"
	expect_failure 'Package runtime requires an explicitly owned disposable Compose project' bash scripts/release-qa.sh
	test ! -s "$TRACE"
	expect_failure 'Runtime QA requires an explicitly owned disposable Compose project' bash scripts/e2e-test.sh all
	test ! -s "$TRACE"
	SKIP_PLUGIN_CHECK=1 CI=false GITHUB_ACTIONS=false bash scripts/release-qa.sh > "$WORK/offline.log" 2>&1
	grep -F 'runtime QA NOT RUN' "$WORK/offline.log"
	test ! -s "$TRACE"
)
COMPOSE_PROJECT_NAME='invalid name' expect_failure 'Invalid disposable Compose project name.' bash scripts/release-qa.sh
test ! -s "$TRACE"
mkdir -m 700 -- "$WORK/first-package-diagnostic"
package_status=0
WSTM108_MOCK_DIAGNOSTIC=1 bash scripts/release-qa.sh > "$WORK/success.log" 2>&1 || package_status=$?
if (( package_status != 0 )); then
	diagnostic_status=0
	(
		umask 077
		set -o noclobber
		exec "$WSTM108_MOCK_PHP" "$REPO_ROOT/tests/unit/fixtures/untrusted-runtime-diagnostic.php" \
			read "$WORK/first-package-diagnostic" "$package_status" \
			> "$WORK/first-package-diagnostic/readback.stdout.private" \
			2> "$WORK/first-package-diagnostic/readback.stderr.private"
	) || diagnostic_status=$?
	diagnostic_code=''
	if ! {
		if [[ "$diagnostic_status" == 0 && ! -s "$WORK/first-package-diagnostic/readback.stderr.private" \
			&& -f "$WORK/first-package-diagnostic/readback.stdout.private" \
			&& ! -L "$WORK/first-package-diagnostic/readback.stdout.private" ]] \
			&& (( $(wc -c < "$WORK/first-package-diagnostic/readback.stdout.private") <= 3 )) \
			&& IFS= read -r -n 3 diagnostic_code < "$WORK/first-package-diagnostic/readback.stdout.private" \
			&& [[ "$diagnostic_code" =~ ^(0|[1-9]|[12][0-9]|3[0-6])$ ]] \
			&& (( $(wc -c < "$WORK/first-package-diagnostic/readback.stdout.private") == ${#diagnostic_code} + 1 )); then
			if [[ "$diagnostic_code" == 0 ]]; then
				printf 'WSTM108 synthetic diagnostic: unmapped; original_exit=%s\n' "$package_status"
			else
				printf 'WSTM108 synthetic diagnostic: topology_code=%s; original_exit=%s\n' "$diagnostic_code" "$package_status"
			fi
		elif [[ "$diagnostic_status" == 2 ]]; then
			printf 'WSTM108 synthetic diagnostic: not-observed; original_exit=%s\n' "$package_status"
		else
			printf 'WSTM108 synthetic diagnostic: refused; reader_exit=%s; original_exit=%s\n' "$diagnostic_status" "$package_status"
		fi
	} 2>/dev/null; then
		printf '%s\n' 'WSTM108 synthetic diagnostic reporting failed; original exit retained.' >&2 2>/dev/null || :
	fi
	exit "$package_status"
fi
[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
for runner in ability-runner media-download-runner scheduling-runner trash-safety-runner mcp-crud-runner site-kit-mcp-runner parent-assignment-runner post-meta-authorization-runner error-contract-runner metadata-batch-runner seo-metadata-runner destructive-safety-runner database-table-privacy-runner; do
	grep -F "/tests/e2e/${runner}.php" "$TRACE" > /dev/null
done
[[ "$(grep -c 'destructive-safety-runner.php$' "$TRACE")" == 8 ]]
for days in 30 0; do
	for boundary in direct ability http individual; do
		grep -E "WSTM116_DISPOSABLE=1 -e WSTM116_BOUNDARY=${boundary} -e WSTM116_EXPECT_TRASH_DAYS=${days} .*WSTM116_ARTIFACT=.* wordpress php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/destructive-safety-runner.php$" "$TRACE" > /dev/null
	done
done
grep -F 'destructive-safety-stage.php restore ' "$TRACE" > /dev/null
for boundary in native http; do
	http=0
	[[ "$boundary" != http ]] || http=1
	grep -F "WSTM111_DISPOSABLE_SITE=1 -e WSTM111_PRIVACY_HTTP=${http} -e WSTM111_PRIVACY_ARTIFACT=/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/e2e-artifacts/database-table-privacy-${boundary}.json wordpress php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/database-table-privacy-runner.php" "$TRACE" > /dev/null
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
for operation in ' up -d' ' exec ' ' cp ' ' down -v --remove-orphans'; do
	grep -F "compose --project-name release-runtime-fixture -f docker-compose.yml -f docker-compose.release.yml${operation}" "$TRACE" >/dev/null
done
test -f e2e-artifacts/plugin-check-latest-verdict.json
command php scripts/release-tools.php runtime-package build/release-check/webmastery-site-toolkit-for-mcp "$RELEASE_ZIP"

# Exercise both complete outer paths; simulated down really destroys the private fixtures.
# shellcheck disable=SC2030,SC2031 # Each fault injection is intentionally isolated to its subprocess.
for outer in package source; do
	for failure in restoration attachment missing-proof ordinary combined success teardown ordinary-teardown; do
		php tests/unit/fixtures/untrusted-release-proof.php isolate
		export WSTM108_MOCK_LIVE="$WORK/retention-$outer-$failure"
		mkdir -m 700 "$WSTM108_MOCK_LIVE"
		rm -f build/wstm116-retention-release-runtime-fixture
		: > "$TRACE"
		status=0
		(
			export FAIL_STAGE_RESTORE=0 FAIL_RUNNER=0 FAIL_RUNNER_CLEANUP=0 FAIL_CLEANUP=0
			case "$failure" in
				restoration) export FAIL_STAGE_RESTORE=1 ;;
				attachment) export FAIL_RUNNER_CLEANUP=1 ;;
				missing-proof) export FAIL_RUNNER_CLEANUP=missing ;;
				ordinary) export FAIL_RUNNER=1 ;;
				combined) export FAIL_RUNNER=1 FAIL_STAGE_RESTORE=1 ;;
				teardown) export FAIL_CLEANUP=1 ;;
				ordinary-teardown) export FAIL_RUNNER=1 FAIL_CLEANUP=1 ;;
			esac
			if [[ "$outer" == source ]]; then
				unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
				export SOURCE_MODE=1 E2E_KEEP_COMPOSE=0
				bash scripts/e2e-test.sh all
			else
				bash scripts/release-qa.sh
			fi
		) > "$WORK/retention-$outer-$failure.log" 2>&1 || status=$?
		case "$failure" in
			restoration|teardown) expected=47 ;;
			attachment|missing-proof) expected=45 ;;
			ordinary|combined|ordinary-teardown) expected=44 ;;
			success) expected=0 ;;
		esac
		[[ "$status" == "$expected" ]] || {
			printf 'FAIL legacy retention outer=%s case=%s expected_status=%s actual_status=%s\n' "$outer" "$failure" "$expected" "$status" >&2
			cat "$WORK/retention-$outer-$failure.log" >&2
			exit 1
		}
		if [[ "$failure" == ordinary || "$failure" == success || "$failure" == teardown || "$failure" == ordinary-teardown ]]; then
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
			test ! -e build/wstm116-retention-release-runtime-fixture
			test ! -e "$WORK/private-backup"
		else
			test -f build/wstm116-retention-release-runtime-fixture
			grep -Eq '^owner=[a-f0-9]{32}$' build/wstm116-retention-release-runtime-fixture
			grep -Fx 'project=release-runtime-fixture' build/wstm116-retention-release-runtime-fixture
			grep -Eq '^source=[a-f0-9]{40}$' build/wstm116-retention-release-runtime-fixture
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 1 ]]
			awk '/stage-acquired/{armed=1} armed && / down -v --remove-orphans$/{exit 1}' "$TRACE"
			if [[ "$failure" == restoration || "$failure" == combined ]]; then
				grep -Fx 'private-config-fixture' "$WORK/private-backup" >/dev/null
				grep -Fx 'owned-readonly-probe' "$WORK/readonly-probe" >/dev/null
			else
				grep -Fx 'owned-attachment' "$WORK/retained-attachment" >/dev/null
				grep -Fx 'original-file-bytes' "$WORK/retained-file" >/dev/null
				grep -Fx 'owned-upload-proof' "$WORK/retained-marker" >/dev/null
			fi
			lines="$(wc -l < "$TRACE")"
			expect_failure 'RECOVERY REQUIRED' bash scripts/destructive-retention.sh cleanup
			expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
			COMPOSE_PROJECT_NAME=another-project expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
			expect_failure 'RECOVERY REQUIRED' bash scripts/e2e-test.sh all
			[[ "$(wc -l < "$TRACE")" == "$lines" ]]
		fi
	done
done
rm -f build/wstm116-retention-release-runtime-fixture
printf 'foreign-invalid-marker\n' > build/wstm116-retention-foreign
: > "$TRACE"
expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
expect_failure 'RECOVERY REQUIRED' bash scripts/destructive-retention.sh cleanup
test ! -s "$TRACE"
grep -Fx 'foreign-invalid-marker' build/wstm116-retention-foreign
rm build/wstm116-retention-foreign
echo 'PASS full package/managed-source retention, private evidence survival, no teardown, re-entry refusal and original statuses'

# These use the real outer wrappers, independent POSIX process capture and
# public proof parsers. Only Docker topology and site payloads are synthetic.
# shellcheck disable=SC2030,SC2031 # Each fault is isolated to its outer invocation.
for outer in package source; do
	for fault in http500 malformed-json failed-valid-json partial-spool partial-journal pending-parser pre-handler-stdout provider-stderr both-streams extra-line success; do
		php tests/unit/fixtures/untrusted-release-proof.php isolate
		rm -f build/wstm116-retention-release-runtime-fixture
		export WSTM108_MOCK_LIVE="$WORK/untrusted-$outer-$fault"
		mkdir -m 700 "$WSTM108_MOCK_LIVE"
		: > "$TRACE"
		status=0
		(
			export WSTM108_MOCK_FAULT="$fault"
			if [[ "$outer" == source ]]; then
				unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
				export SOURCE_MODE=1 E2E_KEEP_COMPOSE=0
				bash scripts/e2e-test.sh all
			else
				bash scripts/release-qa.sh
			fi
		) > "$WORK/untrusted-$outer-$fault.log" 2>&1 || status=$?
		expected=1
		case "$fault" in http500|malformed-json|failed-valid-json) expected=44 ;; success) expected=0 ;; esac
		[[ "$status" == "$expected" ]] || { echo "Unexpected $outer/$fault status $status (expected $expected)." >&2; exit 1; }
		[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
		if [[ "$fault" == success ]]; then
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
			[[ "$(grep -c 'untrusted-content-stage.php retire ' "$TRACE")" == 1 ]]
			[[ ! -e build/wstm116-retention-release-runtime-fixture ]]
			[[ ! -e "$WSTM108_MOCK_LIVE/private-state" && ! -e "$WSTM108_MOCK_LIVE/private-journal" && ! -e "$WSTM108_MOCK_LIVE/original-wire.private" ]]
		else
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 1 ]]
			test -f build/wstm116-retention-release-runtime-fixture
			test -f "$WSTM108_MOCK_LIVE/private-state"
			test -f "$WSTM108_MOCK_LIVE/private-journal"
			test -f "$WSTM108_MOCK_LIVE/original-wire.private"
			if grep -Eq 'untrusted-content-stage.php (finalize|retire) ' "$TRACE"; then exit 1; fi
			if grep -Eq 'unknown-(original|bootstrap|provider|extra)-secret' "$WORK/untrusted-$outer-$fault.log"; then exit 1; fi
			php tests/unit/fixtures/untrusted-mock-proof.php "$fault" > "$WORK/untrusted-$outer-$fault-assertions.log"
			private_identity="$(sha256sum "$WSTM108_MOCK_LIVE/original-wire.private")"
			lines="$(wc -l < "$TRACE")"
			expect_failure 'RECOVERY REQUIRED' bash scripts/destructive-retention.sh cleanup
			expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
			expect_failure 'RECOVERY REQUIRED' bash scripts/e2e-test.sh all
			[[ "$(wc -l < "$TRACE")" == "$lines" ]]
			[[ "$(sha256sum "$WSTM108_MOCK_LIVE/original-wire.private")" == "$private_identity" ]]
		fi
	done
done
rm -f build/wstm116-retention-release-runtime-fixture
echo 'PASS untrusted failed-wire/private-process retention and validated retirement in both synthetic source/original-ZIP outer paths'

# Unchanged f93 runs to its actual unlink/echo boundary. The companion alone
# must block both outer gates when clear acknowledgment or authorization fails.
# shellcheck disable=SC2030,SC2031 # Each injected boundary fault is isolated.
for outer in package source; do
	for fault in companion-partial clear-echo clear-exit clear-truncated precommit-controller authorization-collision precommit-evidence companion-replacement success postcommit-ack; do
		php tests/unit/fixtures/untrusted-release-proof.php isolate
		export WSTM108_MOCK_LIVE="$WORK/release-$outer-$fault"
		mkdir -m 700 "$WSTM108_MOCK_LIVE"
		: > "$TRACE"
		status=0
		(
			export WSTM108_RELEASE_FAULT="$fault"
			if [[ "$outer" == source ]]; then
				unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
				export SOURCE_MODE=1 E2E_KEEP_COMPOSE=0
				bash scripts/e2e-test.sh all
			else
				bash scripts/release-qa.sh
			fi
		) > "$WORK/release-$outer-$fault.log" 2>&1 || status=$?
		expected=1
		case "$fault" in clear-exit) expected=73 ;; precommit-controller) expected=74 ;; success) expected=0 ;; postcommit-ack) expected=75 ;; esac
		[[ "$status" == "$expected" ]]
		[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
		php tests/unit/fixtures/untrusted-release-proof.php "$fault" > "$WORK/release-$outer-$fault-assertions.log"
		if [[ "$fault" == success || "$fault" == postcommit-ack ]]; then
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
			# Exit 75 is expected contract coverage, never a green actual run.
			bash scripts/destructive-retention.sh check
		else
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 1 ]]
			lines="$(wc -l < "$TRACE")"
			expect_failure 'RECOVERY REQUIRED' bash scripts/destructive-retention.sh cleanup
			expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
			expect_failure 'RECOVERY REQUIRED' bash scripts/e2e-test.sh all
			[[ "$(wc -l < "$TRACE")" == "$lines" ]]
			command php tests/unit/fixtures/untrusted-release-proof.php "$fault" > "$WORK/release-$outer-$fault-reentry-assertions.log"
		fi
	done
done
echo 'PASS synthetic source/original-ZIP precommit retention and terminal release/failed-ack classification; not runtime acceptance'

# Negative controls restore unconditional outer teardown only in this copied fixture.
# The identical injected restoration failure must now lose its private evidence.
# shellcheck disable=SC2030,SC2031 # Fault flags never leak between independent subprocesses.
for outer in package source; do
	script=scripts/release-qa.sh
	[[ "$outer" != source ]] || script=scripts/e2e-test.sh
	cp "$script" "$WORK/fixed-retention-$outer.sh"
	sed 's/if ! wstm116_require_no_retention; then/if false; then/' "$WORK/fixed-retention-$outer.sh" > "$script"
	: > "$TRACE"
	status=0
	(
		export FAIL_STAGE_RESTORE=1
		if [[ "$outer" == source ]]; then
			unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
			export SOURCE_MODE=1 E2E_KEEP_COMPOSE=0
			bash scripts/e2e-test.sh all
		else
			bash scripts/release-qa.sh
		fi
	) > "$WORK/legacy-retention-$outer.log" 2>&1 || status=$?
	[[ "$status" == 47 ]]
	[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
	test ! -e "$WORK/private-backup"
	test ! -e "$WORK/readonly-probe"
	test -f build/wstm116-retention-release-runtime-fixture
	cp "$WORK/fixed-retention-$outer.sh" "$script"
	rm build/wstm116-retention-release-runtime-fixture
	echo "RED control confirmed: legacy $outer teardown destroyed retained backup/probe after restoration failure"
done

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
	command php scripts/release-tools.php extract "$RELEASE_ZIP" build/release-runtime
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

# Completed cases retain context-path.private; never reuse their live roots.
export WSTM108_MOCK_LIVE="$WORK/checker-failure"
mkdir -m 700 "$WSTM108_MOCK_LIVE"
CHECKER_FAILURE=1 expect_failure 'Plugin Check reported 1 ERROR' bash scripts/release-qa.sh
: > "$TRACE"
export WSTM108_MOCK_LIVE="$WORK/cleanup-failure"
mkdir -m 700 "$WSTM108_MOCK_LIVE"
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
export WSTM108_MOCK_LIVE="$WORK/native-checker-php-failure"
mkdir -m 700 "$WSTM108_MOCK_LIVE"
expect_failure 'Fixture native host PHP failure.' bash scripts/release-qa.sh
[[ "$FAILURE_STATUS" == 23 ]] || {
	printf 'FAIL native checker exit: expected=23 actual=%s\n' "$FAILURE_STATUS" >&2
	exit 1
}
unset -f php
for tamper in production file directory placeholder; do
	export WSTM108_MOCK_LIVE="$WORK/tamper-$tamper"
	mkdir -m 700 "$WSTM108_MOCK_LIVE"
	TAMPER_RUNTIME="$tamper" expect_failure 'ERROR ' bash scripts/release-qa.sh
	[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
	php scripts/release-tools.php runtime-package build/release-check/webmastery-site-toolkit-for-mcp "$RELEASE_ZIP"
done
export WSTM108_MOCK_LIVE="$WORK/zip-mutation"
mkdir -m 700 "$WSTM108_MOCK_LIVE"
MUTATE_ZIP=1 expect_failure 'Original release ZIP identity changed during QA.' bash scripts/release-qa.sh

# Source-mode commands stay unchanged, including caller-supplied Compose settings.
docker() { printf '%s\n' "$*"; }
output="$(compose config)"
[[ "$output" == 'compose --project-name release-runtime-fixture config' ]]
echo 'PASS release orchestration, pristine checker, original ZIP identity, all runners, package guards and old-behavior negative control'
