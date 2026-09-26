#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-runtime-tests-$$"
export WORK
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
			rm -f "$WORK/schema-journal" "$WORK/schema-probe" "$WORK/schema-post" "$WORK/schema-actor" "$WORK/schema-credential"
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
		*"input-schema-stage.php acquire "*)
			printf 'schema-owner-journal\n' > "$WORK/schema-journal"
			printf 'schema-owned-probe\n' > "$WORK/schema-probe"
			;;
		*"input-schema-stage.php restore "*)
			[[ "${FAIL_SCHEMA_RESTORE:-0}" != 1 ]] || return 57
			;;
		*"input-schema-stage.php finalize "*)
			[[ "${FAIL_SCHEMA_FINALIZE:-0}" != 1 ]] || return 58
			rm -f "$WORK/schema-journal" "$WORK/schema-probe"
			;;
		*"WSTM126_DISPOSABLE= wordpress php "*|*"WSTM126_DISPOSABLE=true wordpress php "*)
			echo 'Set WSTM126_DISPOSABLE=1 only in an owned disposable runtime.'
			return 1
			;;
		*"curl --max-time 2"*"/tests/e2e/input-schema-runner.php")
			printf 'CLI only.\n403'
			;;
		*"input-schema-runner.php")
			local arg artifact owner source boundary mutation=none package=source
			for arg in "$@"; do
				case "$arg" in
					WSTM126_ARTIFACT=*) artifact="${arg#*=}"; artifact="${artifact#*/webmastery-site-toolkit-for-mcp/}" ;;
					WSTM126_STAGE_TOKEN=*) owner="${arg#*=}" ;;
					WSTM126_SOURCE_SHA=*) source="${arg#*=}" ;;
					WSTM126_BOUNDARY=*) boundary="${arg#*=}" ;;
				esac
			done
			[[ "${SOURCE_MODE:-0}" == 1 ]] || package=package
			[[ "${FAIL_SCHEMA_RUNNER:-0}" != 1 ]] || mutation=case
			if [[ "${FAIL_SCHEMA_CLEANUP:-0}" == 1 ]]; then
				mutation=cleanup
				printf 'retained-owned-post\n' > "$WORK/schema-post"
				printf 'retained-owned-actor\n' > "$WORK/schema-actor"
				printf 'retained-owned-credential\n' > "$WORK/schema-credential"
			fi
			( unset MSYS_NO_PATHCONV; php tests/input-schema-report.php "$artifact" "$owner" "$source" "$boundary" "$mutation" "$package" ) || return $?
			[[ "${FAIL_SCHEMA_RUNNER:-0}" != 1 ]] || return 53
			[[ "${FAIL_SCHEMA_CLEANUP:-0}" != 1 ]] || return 54
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
		echo "Expected failure: $*" >&2
		exit 1
	fi
	grep -F "$expected" "$WORK/failure.log"
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
bash scripts/release-qa.sh > "$WORK/success.log" 2>&1
[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
for runner in ability-runner media-download-runner scheduling-runner trash-safety-runner mcp-crud-runner site-kit-mcp-runner parent-assignment-runner post-meta-authorization-runner error-contract-runner metadata-batch-runner seo-metadata-runner destructive-safety-runner database-table-privacy-runner; do
	grep -F "/tests/e2e/${runner}.php" "$TRACE" > /dev/null
done
[[ "$(grep -c 'destructive-safety-runner.php$' "$TRACE")" == 8 ]]
[[ "$(grep -c 'WSTM126_DISPOSABLE=1 .*input-schema-runner.php$' "$TRACE")" == 5 ]]
for boundary in direct permission ability http individual; do
	grep -E "WSTM126_DISPOSABLE=1 -e WSTM126_BOUNDARY=${boundary} .*WSTM126_PROJECT=release-runtime-fixture .*WSTM126_ARTIFACT=.*${boundary}.json .*input-schema-runner.php$" "$TRACE" >/dev/null
done
grep -F 'WSTM126_DISPOSABLE= wordpress php ' "$TRACE" >/dev/null
grep -F 'WSTM126_DISPOSABLE=true wordpress php ' "$TRACE" >/dev/null
grep -F 'input-schema-stage.php finalize ' "$TRACE" >/dev/null
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
php scripts/release-tools.php runtime-package build/release-check/webmastery-site-toolkit-for-mcp "$RELEASE_ZIP"

# Exercise both complete outer paths; simulated down really destroys the private fixtures.
# shellcheck disable=SC2030,SC2031 # Each fault injection is intentionally isolated to its subprocess.
for outer in package source; do
	for failure in restoration attachment missing-proof ordinary combined success teardown ordinary-teardown; do
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
		[[ "$status" == "$expected" ]] || { cat "$WORK/retention-$outer-$failure.log" >&2; exit 1; }
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

# Additive schema-stage failures use the same real outer source/package wrappers.
# shellcheck disable=SC2030,SC2031 # Each fault runs in an independent subprocess.
for outer in package source; do
	for failure in restoration finalize cleanup combined ordinary; do
		rm -f build/wstm116-retention-release-runtime-fixture
		: > "$TRACE"
		status=0
		(
			export FAIL_SCHEMA_RESTORE=0 FAIL_SCHEMA_FINALIZE=0 FAIL_SCHEMA_CLEANUP=0 FAIL_SCHEMA_RUNNER=0
			case "$failure" in
				restoration) export FAIL_SCHEMA_RESTORE=1 ;;
				finalize) export FAIL_SCHEMA_FINALIZE=1 ;;
				cleanup) export FAIL_SCHEMA_CLEANUP=1 ;;
				combined) export FAIL_SCHEMA_RUNNER=1 FAIL_SCHEMA_RESTORE=1 ;;
				ordinary) export FAIL_SCHEMA_RUNNER=1 ;;
			esac
			if [[ "$outer" == source ]]; then
				unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
				export SOURCE_MODE=1 E2E_KEEP_COMPOSE=0
				bash scripts/e2e-test.sh all
			else
				bash scripts/release-qa.sh
			fi
		) > "$WORK/schema-retention-$outer-$failure.log" 2>&1 || status=$?
		case "$failure" in restoration) expected=57 ;; finalize) expected=58 ;; cleanup) expected=54 ;; combined|ordinary) expected=53 ;; esac
		[[ "$status" == "$expected" ]] || { cat "$WORK/schema-retention-$outer-$failure.log" >&2; exit 1; }
		if [[ "$failure" == ordinary ]]; then
			test ! -e build/wstm116-retention-release-runtime-fixture
			test ! -e "$WORK/schema-journal"
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
		else
			test -f build/wstm116-retention-release-runtime-fixture
			grep -Fx 'schema-owner-journal' "$WORK/schema-journal" >/dev/null
			grep -Fx 'schema-owned-probe' "$WORK/schema-probe" >/dev/null
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 1 ]]
			if [[ "$failure" == cleanup ]]; then
				grep -Fx 'retained-owned-post' "$WORK/schema-post" >/dev/null
				grep -Fx 'retained-owned-actor' "$WORK/schema-actor" >/dev/null
				grep -Fx 'retained-owned-credential' "$WORK/schema-credential" >/dev/null
			fi
		fi
	done
done
rm -f build/wstm116-retention-release-runtime-fixture
echo 'PASS schema source/package cleanup and HTTP restoration retention with original failure status'

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
# shellcheck disable=SC2030,SC2031 # Each fault runs in an independent subprocess.
for outer in package source; do
	script=scripts/release-qa.sh
	[[ "$outer" != source ]] || script=scripts/e2e-test.sh
	cp "$script" "$WORK/fixed-schema-retention-$outer.sh"
	sed 's/if ! wstm116_require_no_retention; then/if false; then/' "$WORK/fixed-schema-retention-$outer.sh" > "$script"
	: > "$TRACE"
	status=0
	(
		export FAIL_SCHEMA_RESTORE=1 FAIL_SCHEMA_RUNNER=1
		if [[ "$outer" == source ]]; then
			unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
			export SOURCE_MODE=1 E2E_KEEP_COMPOSE=0
			bash scripts/e2e-test.sh all
		else
			bash scripts/release-qa.sh
		fi
	) > "$WORK/legacy-schema-retention-$outer.log" 2>&1 || status=$?
	[[ "$status" == 53 ]]
	[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
	test ! -e "$WORK/schema-journal"
	test ! -e "$WORK/schema-probe"
	test -f build/wstm116-retention-release-runtime-fixture
	cp "$WORK/fixed-schema-retention-$outer.sh" "$script"
	rm build/wstm116-retention-release-runtime-fixture
	echo "RED control confirmed: legacy $outer teardown destroyed schema evidence while retaining first failure 53"
done

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
