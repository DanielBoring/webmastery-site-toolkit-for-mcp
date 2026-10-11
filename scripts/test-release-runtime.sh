#!/usr/bin/env bash
set +x
set -Eeuo pipefail

REPO_ROOT="$(pwd -P)"
WORK_BASE="${RELEASE_RUNTIME_TEST_ROOT:-$REPO_ROOT/build}"
[[ "$WORK_BASE" == /* && "$WORK_BASE" != /tmp* && "$WORK_BASE" != /var/tmp* ]] || {
	echo "Runtime mocks require an explicit project-local workspace." >&2; exit 78;
}
[[ "$(uname -s)" == Linux ]] || {
	echo "BLOCKED: runtime orchestration mocks require a genuine native Linux PHP interpreter." >&2; exit 78;
}
WORK="$WORK_BASE/release-runtime-tests-$$"
[[ ! -e "$WORK" && ! -L "$WORK" ]] || {
	echo "Runtime fixture workspace already exists; refusing overwrite." >&2; exit 1;
}
export WORK
(umask 077; mkdir -p "$WORK/source with spaces/.github" "$WORK/private-state" "$WORK/container")
trap 'status=$?; if [[ "$status" != 0 ]]; then echo "Mock failure evidence retained at $WORK" >&2; tail -n 30 "$WORK/"*.log >&2; else rm -rf -- "$WORK"; fi; exit "$status"' EXIT
cp -R scripts includes tests "$WORK/source with spaces/"
cp docker-compose*.yml webmastery-site-toolkit-for-mcp.php readme.txt LICENSE CHANGELOG.md "$WORK/source with spaces/"
cp .github/compatibility-versions.json "$WORK/source with spaces/.github/"
cd "$WORK/source with spaces"
# The exclusive fixture may originate in a Windows source snapshot. Normalize
# shell syntax only in this copy; never change the candidate or PHP oracles.
find scripts tests -type f -name '*.sh' -exec sed -i 's/\r$//' {} +
export WSTM_QA_STATE_ROOT="$WORK/private-state"
unset WSTM_QA_OWNER WSTM_QA_DB_PASSWORD WSTM_QA_DB_ROOT_PASSWORD E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP E2E_CHECKER_ROOT
# shellcheck source=scripts/qa-compose.sh
source scripts/qa-compose.sh
host_php tests/e2e/post-meta-authorization-runner.php --test-cleanup "$WORK/metadata-cleanup.json"
export RELEASE_ZIP
RELEASE_ZIP="$(bash scripts/build-release.sh)"
export TRACE="$WORK/commands" MSYS_NO_PATHCONV=1
export COMPOSE_PROJECT_NAME=release-runtime-fixture REQUIRE_CURRENT_PLUGIN_CHECK=1 E2E_MANAGE_COMPOSE=1
export COMPOSE_FILE=must-not-be-used.yml
IDENTITY="$(sha256sum "$RELEASE_ZIP")"
# This token is ONLY a synthetic journal correlation identity, not a claim that
# the copied source is a committed/frozen candidate. Never access shared Git.
git() {
	[[ "$*" == "rev-parse HEAD" ]] || { echo "Unexpected mock Git operation." >&2; return 95; }
	printf '%s\n' '1111111111111111111111111111111111111111'
}
export -f git
# shellcheck source=tests/unit/fixtures/release-runtime-docker.sh
source tests/unit/fixtures/release-runtime-docker.sh
# The outer release fixture models the installer boundary; its real lifecycle
# and native custody validators have separate non-runtime regression controls.
cat >> tests/unit/fixtures/release-runtime-product.sh <<'QUIET_FIXTURE'
qa_quiet_install() {
	if [[ "${2:-}" == --verify ]]; then
		compose exec -T -e WSTM_INITIAL_SITE_URL=http://localhost wordpress php \
			"$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" --verify
	else
		printf '%s\n' "$1" | compose exec -T -e WSTM_INITIAL_SITE_URL=http://localhost wordpress php \
			"$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" || return $?
		compose exec -T -e WSTM_INITIAL_SITE_URL=http://localhost wordpress php \
			"$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" --verify
	fi
}
QUIET_FIXTURE
# Replace only the isolated product boundary in the copied outer fixture.
[[ "$(grep -c $'^\trun_product_qa$' scripts/e2e-test.sh)" == 1 ]] || {
	echo "BLOCKED: parent E2E main must call run_product_qa exactly once." >&2; exit 78;
}
awk '/^if \[\[ "\$\{BASH_SOURCE\[0\]\}" == "\$0" \]\]; then/ {
	print "source \"$WORK/source with spaces/tests/unit/fixtures/release-runtime-product.sh\""
} { print }' scripts/e2e-test.sh > "$WORK/e2e-mocked.sh"
cp "$WORK/e2e-mocked.sh" scripts/e2e-test.sh

expect_failure() {
	local expected="$1" status=0
	shift
	"$@" > "$WORK/failure.log" 2>&1 || status=$?
	[[ "$status" != 0 ]] || { echo "Expected failure: $*" >&2; exit 1; }
	grep -F "$expected" "$WORK/failure.log"
	FAILURE_STATUS="$status"
}

fresh_case() {
	# Test-only retirement of resources in the exclusively copied fixture. This
	# is never a production recovery mechanism or permission to delete live data.
	rm -rf e2e-artifacts "$WORK/container"
	mkdir -m 0700 "$WORK/container"
	rm -f build/wstm116-retention-release-runtime-fixture
	rm -f "$WORK/private-backup" "$WORK/readonly-probe" "$WORK/retained-attachment" "$WORK/retained-file" "$WORK/retained-marker"
	rm -f "$WORK/schema-journal" "$WORK/schema-probe" "$WORK/schema-post" "$WORK/schema-actor" "$WORK/schema-credential" "$WORK/product-journal"
	: > "$TRACE"
}

outer_qa() {
	local outer="$1"
	if [[ "$outer" == source ]]; then
		unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP E2E_CHECKER_ROOT
		export SOURCE_MODE=1 E2E_KEEP_COMPOSE=0
		bash scripts/e2e-test.sh all
	else
		bash scripts/release-qa.sh
	fi
}

assert_reentry_refused() {
	local lines
	lines="$(wc -l < "$TRACE")"
	expect_failure 'RECOVERY REQUIRED' bash scripts/destructive-retention.sh cleanup
	expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
	COMPOSE_PROJECT_NAME=another-project expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
	expect_failure 'RECOVERY REQUIRED' bash scripts/e2e-test.sh all
	[[ "$(wc -l < "$TRACE")" == "$lines" ]]
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
fresh_case
bash scripts/release-qa.sh > "$WORK/success.log" 2>&1
[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
for runner in ability-runner media-download-runner scheduling-runner trash-safety-runner mcp-crud-runner site-kit-mcp-runner parent-assignment-runner post-meta-authorization-runner error-contract-runner metadata-batch-runner seo-metadata-runner destructive-safety-runner database-table-privacy-runner; do
	grep -F "/tests/e2e/${runner}.php" "$TRACE" >/dev/null
done
[[ "$(grep -c 'destructive-safety-runner.php$' "$TRACE")" == 8 ]]
[[ "$(grep -c 'WSTM126_DISPOSABLE=1 .*input-schema-runner.php$' "$TRACE")" == 5 ]]
for boundary in direct permission ability http individual; do
	grep -E "WSTM126_DISPOSABLE=1 -e WSTM126_BOUNDARY=${boundary} .*WSTM126_PROJECT=release-runtime-fixture .*WSTM126_ARTIFACT=/qa-artifacts/.*${boundary}.json .*input-schema-runner.php$" "$TRACE" >/dev/null
done
grep -F 'WSTM126_DISPOSABLE= wordpress php ' "$TRACE" >/dev/null
grep -F 'WSTM126_DISPOSABLE=true wordpress php ' "$TRACE" >/dev/null
grep -F 'input-schema-stage.php finalize ' "$TRACE" >/dev/null
for days in 30 0; do
	for boundary in direct ability http individual; do
		grep -E "WSTM116_DISPOSABLE=1 -e WSTM116_BOUNDARY=${boundary} -e WSTM116_EXPECT_TRASH_DAYS=${days} .*WSTM116_ARTIFACT=/qa-artifacts/.* wordpress php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/destructive-safety-runner.php$" "$TRACE" >/dev/null
	done
done
grep -F 'destructive-safety-stage.php restore ' "$TRACE" >/dev/null
for boundary in native http; do
	http=0
	[[ "$boundary" != http ]] || http=1
	grep -F "WSTM111_DISPOSABLE_SITE=1 -e WSTM111_PRIVACY_HTTP=${http} -e WSTM111_PRIVACY_ARTIFACT=/qa-artifacts/database-table-privacy-${boundary}.json wordpress php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/database-table-privacy-runner.php" "$TRACE" >/dev/null
done
for boundary in direct ability http individual; do
	for runner in metadata-batch seo-metadata; do
		grep -F "WSTM110_BATCH_DISPOSABLE=1 -e WSTM110_BATCH_BOUNDARY=${boundary} wordpress php -d memory_limit=1G /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/${runner}-runner.php" "$TRACE" >/dev/null
	done
done
grep -F 'rm -f /var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php /var/www/html/wp-content/mu-plugins/wstm-issue110-http.php /var/www/html/wp-content/mu-plugins/wstm-issue110-seo.php /var/www/html/wp-content/mu-plugins/wstm-issue118-errors.php' "$TRACE" >/dev/null
for boundary in direct ability http; do grep -F "WSTM110_BOUNDARY=${boundary}" "$TRACE" >/dev/null; done
grep -F 'rm -f /var/www/html/wp-content/mu-plugins/wstm-issue110-meta.php' "$TRACE" >/dev/null
grep -F 'plugin install plugin-check --version=2.1.0' "$TRACE"
grep -F 'plugin install plugin-check --activate --force' "$TRACE"
for checker in 2.1.0 latest; do
	destination="/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package-${checker//./-}"
	grep -F " wordpress mkdir $destination" "$TRACE" >/dev/null
	grep -F " wordpress cp -R /qa-package-pristine/. $destination" "$TRACE" >/dev/null
done
if grep -F 'rm -rf /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp-package' "$TRACE"; then
	echo "Plugin Check must not delete an existing package destination." >&2; exit 1
fi
grep -F 'Unchanged release archive:' "$WORK/success.log"
[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
grep -F ' exec --user 33:33 ' "$TRACE" >/dev/null
grep -F 'cp -R /qa-package-pristine/.' "$TRACE" >/dev/null
grep -F 'php /var/www/html/.qa-bin/wp --url=http://localhost' "$TRACE" >/dev/null
grep -F 'WP_CLI_CACHE_DIR=/qa-artifacts/parent-cache/wp-cli' "$TRACE" >/dev/null
grep -F 'install-owned-wordpress.php' "$TRACE" >/dev/null
test -f e2e-artifacts/plugin-check-latest-verdict.json
# shellcheck disable=SC2016 # PHP variables must not be expanded by Bash.
host_php -r '
	$summary = json_decode(file_get_contents("e2e-artifacts/product-qa.summary.json"), true, 16, JSON_THROW_ON_ERROR);
	$expected = [
		"schema" => "wstm-product-qa-summary-v1", "status" => "passed",
		"lane" => PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION, "uid" => 33,
		"untrusted_cases" => 298, "bounded_observations" => 1461,
	];
	if ($summary !== $expected) { throw new RuntimeException("Mock product summary is not the closed six-key contract."); }
'
host_php scripts/release-tools.php runtime-package "$(cat "$WORK/checker-root")" "$RELEASE_ZIP"
awk '
	/mock-product:untrusted/ { if (product || legacy || destructive) exit 1; product=1 }
	/mock-product:bounded/ { if (!product || bounded || legacy || destructive) exit 1; bounded=1 }
	/ability-runner.php|scheduling-runner.php|mcp-crud-runner.php|comments-runner.php|database-table-privacy-runner.php/ {
		if (!bounded) exit 1; legacy=1
	}
	/stage-acquired/ { if (!bounded || !legacy) exit 1; destructive=1 }
	/input-schema-stage.php acquire/ { if (!destructive) exit 1; schema=1 }
	END { if (!product || !bounded || !legacy || !destructive || !schema) exit 1 }
' "$TRACE"
awk '/mock-product:retired/{retired=1} /mock-product:summary-published/{if (!retired) exit 1; published=1} END {if (!published) exit 1}' "$TRACE"
# Existing artifact output must be preserved, not silently reset on re-entry.
printf 'retained-output\n' > e2e-artifacts/retained-fixture
lines="$(wc -l < "$TRACE")"
SOURCE_MODE=1 expect_failure 'Artifact output already exists' bash scripts/e2e-test.sh all
[[ "$(wc -l < "$TRACE")" == "$lines" ]]
grep -Fx 'retained-output' e2e-artifacts/retained-fixture

# The main destructive outer matrix remains unchanged semantically.
for outer in package source; do
	for failure in restoration attachment missing-proof ordinary combined success teardown ordinary-teardown; do
		fresh_case
		status=0
		(
			# shellcheck disable=SC2030 # Each case intentionally isolates its exported mock flags.
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
			outer_qa "$outer"
		) > "$WORK/retention-$outer-$failure.log" 2>&1 || status=$?
		case "$failure" in
			restoration|teardown) expected=47 ;;
			attachment|missing-proof) expected=45 ;;
			ordinary|combined|ordinary-teardown) expected=44 ;;
			success) expected=0 ;;
		esac
		[[ "$status" == "$expected" ]] || { echo "$outer/$failure: expected $expected, got $status" >&2; exit 1; }
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
			assert_reentry_refused
		fi
	done
done
fresh_case
printf 'foreign-invalid-marker\n' > build/wstm116-retention-foreign
expect_failure 'RECOVERY REQUIRED' bash scripts/release-qa.sh
expect_failure 'RECOVERY REQUIRED' bash scripts/destructive-retention.sh cleanup
test ! -s "$TRACE"
grep -Fx 'foreign-invalid-marker' build/wstm116-retention-foreign
rm build/wstm116-retention-foreign
echo 'PASS main destructive source/package failure, cleanup, retention and original-status matrix'

for outer in package source; do
	for failure in restoration finalize cleanup combined ordinary; do
		fresh_case
		status=0
		(
			# shellcheck disable=SC2030 # Each case intentionally isolates its exported mock flags.
			export FAIL_SCHEMA_RESTORE=0 FAIL_SCHEMA_FINALIZE=0 FAIL_SCHEMA_CLEANUP=0 FAIL_SCHEMA_RUNNER=0
			case "$failure" in
				restoration) export FAIL_SCHEMA_RESTORE=1 ;;
				finalize) export FAIL_SCHEMA_FINALIZE=1 ;;
				cleanup) export FAIL_SCHEMA_CLEANUP=1 ;;
				combined) export FAIL_SCHEMA_RUNNER=1 FAIL_SCHEMA_RESTORE=1 ;;
				ordinary) export FAIL_SCHEMA_RUNNER=1 ;;
			esac
			outer_qa "$outer"
		) > "$WORK/schema-retention-$outer-$failure.log" 2>&1 || status=$?
		case "$failure" in restoration) expected=57 ;; finalize) expected=58 ;; cleanup) expected=54 ;; combined|ordinary) expected=53 ;; esac
		[[ "$status" == "$expected" ]] || { echo "$outer/schema-$failure: expected $expected, got $status" >&2; exit 1; }
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
			assert_reentry_refused
		fi
	done
done
echo 'PASS main schema source/package restoration, finalization, cleanup and first-failure matrix'

# These negative controls mutate ONLY the copied orchestration scripts.
for stage in destructive schema; do
	for outer in package source; do
		fresh_case
		script=scripts/release-qa.sh
		[[ "$outer" != source ]] || script=scripts/e2e-test.sh
		cp "$script" "$WORK/fixed-$stage-$outer.sh"
		sed 's/if ! wstm116_require_no_retention; then/if false; then/' "$WORK/fixed-$stage-$outer.sh" > "$script"
		status=0
		(
			# shellcheck disable=SC2031 # This control sets new flags, never reuses a prior case's values.
			if [[ "$stage" == destructive ]]; then export FAIL_STAGE_RESTORE=1; else export FAIL_SCHEMA_RESTORE=1 FAIL_SCHEMA_RUNNER=1; fi
			outer_qa "$outer"
		) > "$WORK/legacy-$stage-$outer.log" 2>&1 || status=$?
		expected=47
		[[ "$stage" != schema ]] || expected=53
		[[ "$status" == "$expected" && "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
		test ! -e "$WORK/private-backup"
		test ! -e "$WORK/readonly-probe"
		test ! -e "$WORK/schema-journal"
		test ! -e "$WORK/schema-probe"
		test -f build/wstm116-retention-release-runtime-fixture
		cp "$WORK/fixed-$stage-$outer.sh" "$script"
		echo "RED control confirmed: legacy $stage/$outer teardown loses private evidence; first failure remains $expected"
	done
done

# Newly isolated product boundary is mocked, but its outer exit/retention
# consequences are tested for both source and original-ZIP paths.
for outer in package source; do
	for failure in ordinary restoration combined summary occupied; do
		fresh_case
		status=0
		(
			export FAIL_PRODUCT_RUNNER=0 FAIL_PRODUCT_RESTORE=0 FAIL_PRODUCT_SUMMARY=0
			case "$failure" in
				ordinary) export FAIL_PRODUCT_RUNNER=1 ;;
				restoration) export FAIL_PRODUCT_RESTORE=1 ;;
				combined) export FAIL_PRODUCT_RUNNER=1 FAIL_PRODUCT_RESTORE=1 ;;
				summary) export FAIL_PRODUCT_SUMMARY=1 ;;
				occupied) export FAIL_PRODUCT_SUMMARY=occupied ;;
			esac
			outer_qa "$outer"
		) > "$WORK/product-$outer-$failure.log" 2>&1 || status=$?
		case "$failure" in ordinary|combined) expected=63 ;; restoration) expected=64 ;; summary) expected=65 ;; occupied) expected=66 ;; esac
		[[ "$status" == "$expected" ]]
		if [[ "$failure" == occupied ]]; then
			grep -Fx 'prior-summary-bytes' e2e-artifacts/product-qa.summary.json
		else
			test ! -e e2e-artifacts/product-qa.summary.json
		fi
		if [[ "$failure" == ordinary || "$failure" == summary || "$failure" == occupied ]]; then
			test ! -e build/wstm116-retention-release-runtime-fixture
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
		else
			test -f "$WORK/product-journal"
			test -f build/wstm116-retention-release-runtime-fixture
			[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 1 ]]
			assert_reentry_refused
		fi
	done
done
echo 'PASS mocked isolated product-boundary outer failures; NOT product acceptance evidence'

fresh_case
cp scripts/release-qa.sh "$WORK/fixed-release-qa.sh"
cp scripts/e2e-test.sh "$WORK/prepared-e2e.sh"
sed '/host_php scripts\/release-tools.php prepare-runtime-mounts /c\		:' "$WORK/prepared-e2e.sh" > scripts/e2e-test.sh
expect_failure 'Missing prepared readonly tests mountpoint.' bash scripts/release-qa.sh
[[ "$FAILURE_STATUS" == 91 ]]
grep -F 'Package cleanup: original_status=91 cleanup_status=0' "$WORK/failure.log"
[[ "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]
[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
cp "$WORK/prepared-e2e.sh" scripts/e2e-test.sh
echo 'PASS missing mount preparation reproduces original startup boundary and preserves cleanup'
fresh_case
sed '/^export E2E_PACKAGE_ROOT=/d; /^export E2E_PACKAGE_ZIP=/d' "$WORK/fixed-release-qa.sh" > scripts/release-qa.sh
expect_failure 'Release runtime attempted checkout Compose configuration.' bash scripts/release-qa.sh
cp "$WORK/fixed-release-qa.sh" scripts/release-qa.sh
export E2E_PACKAGE_ROOT="./build/release-qa-00000000000000000000000000000000/runtime/webmastery-site-toolkit-for-mcp"
export E2E_PACKAGE_ZIP="$RELEASE_ZIP"
export E2E_CHECKER_ROOT="./build/release-qa-00000000000000000000000000000000/checker/webmastery-site-toolkit-for-mcp"
for bad in missing-root missing changed extra checkout absent-zip mismatched-zip; do
	fresh_case
	rm -rf build/release-qa-00000000000000000000000000000000
	host_php scripts/release-tools.php extract "$RELEASE_ZIP" build/release-qa-00000000000000000000000000000000/runtime
	case "$bad" in
		missing-root) rm -rf build/release-qa-00000000000000000000000000000000 ;;
		missing) rm "$E2E_PACKAGE_ROOT/readme.txt" ;;
		changed) printf 'changed' >> "$E2E_PACKAGE_ROOT/includes/class-posts.php" ;;
		extra) mkdir "$E2E_PACKAGE_ROOT/vendor"; printf 'unpackaged' > "$E2E_PACKAGE_ROOT/vendor/autoload.php" ;;
		checkout) E2E_PACKAGE_ROOT=. ;;
		absent-zip) E2E_PACKAGE_ZIP=missing.zip ;;
		mismatched-zip) printf 'changed' >> readme.txt ;;
	esac
	expect_failure 'ERROR ' bash scripts/e2e-test.sh all
	test ! -s "$TRACE"
	E2E_PACKAGE_ROOT="./build/release-qa-00000000000000000000000000000000/runtime/webmastery-site-toolkit-for-mcp"
	E2E_PACKAGE_ZIP="$RELEASE_ZIP"
	cp "$REPO_ROOT/readme.txt" readme.txt
done
E2E_MANAGE_COMPOSE=0 expect_failure 'Package runtime requires managed Compose' bash scripts/e2e-test.sh all
E2E_MANAGE_COMPOSE=0 E2E_ARTIFACTS_DIR=other-output expect_failure 'Package runtime requires managed Compose' bash scripts/e2e-test.sh all
test ! -s "$TRACE"
unset E2E_PACKAGE_ZIP
expect_failure 'requires E2E_PACKAGE_ZIP' bash scripts/e2e-test.sh all
unset E2E_PACKAGE_ROOT
export E2E_PACKAGE_ZIP="$RELEASE_ZIP"
expect_failure 'requires E2E_PACKAGE_ROOT' bash scripts/e2e-test.sh all
unset E2E_PACKAGE_ZIP E2E_CHECKER_ROOT

fresh_case
CHECKER_FAILURE=1 expect_failure 'Plugin Check reported 1 ERROR' bash scripts/release-qa.sh
fresh_case
FAIL_CLEANUP=1 expect_failure 'Fixture cleanup failure.' bash scripts/release-qa.sh
[[ "$FAILURE_STATUS" == 47 ]]
# shellcheck disable=SC2329
php() {
	if [[ "${2:-}" == checker-verdict ]]; then
		[[ -z "${MSYS_NO_PATHCONV:-}" ]] || return 94
		echo "Fixture native host PHP failure." >&2; return 23
	fi
	command php "$@"
}
export -f php
fresh_case
expect_failure 'Fixture native host PHP failure.' bash scripts/release-qa.sh
[[ "$FAILURE_STATUS" == 23 ]]
unset -f php
for tamper in production file directory placeholder; do
	fresh_case
	TAMPER_RUNTIME="$tamper" expect_failure 'ERROR ' bash scripts/release-qa.sh
	[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]
	host_php scripts/release-tools.php runtime-package "$(cat "$WORK/checker-root")" "$RELEASE_ZIP"
done
fresh_case
MUTATE_ZIP=1 expect_failure 'Original release ZIP identity changed during QA.' bash scripts/release-qa.sh
[[ "$(sha256sum "$RELEASE_ZIP")" != "$IDENTITY" ]]

unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP E2E_CHECKER_ROOT
SOURCE_MODE=1 output="$(compose config)"
[[ -z "$output" ]]
tail -n 1 "$TRACE" | grep -Fx 'compose --project-name release-runtime-fixture -f docker-compose.yml config'
echo 'PASS retained main outer orchestration/package/destructive/schema controls; mock evidence only, no service/network/product acceptance'
