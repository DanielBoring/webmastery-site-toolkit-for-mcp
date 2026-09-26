#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(pwd)"
WORK="$ROOT/build/untrusted-stage-test-$$"
if [[ "$(uname -s)" != Linux ]]; then
	echo 'BLOCKED: positive host authority and source outer mocks require native POSIX; Windows refusal is separate evidence.' >&2
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
"$WSTM108_MOCK_PHP" -r 'require $argv[1]; Wstm108_Export::require_host();' "$ROOT/scripts/untrusted-export.php"
mkdir -p "$WORK/checkout/.github" "$WORK/authority" "$WORK/docker-data" "$WORK/bin" "$WORK/cases"
cp -R scripts includes tests "$WORK/checkout/"
cp docker-compose*.yml webmastery-site-toolkit-for-mcp.php readme.txt LICENSE CHANGELOG.md "$WORK/checkout/"
cp .github/compatibility-versions.json "$WORK/checkout/.github/"
cp -R .github/workflows "$WORK/checkout/.github/"
cp tests/unit/fixtures/untrusted-docker "$WORK/bin/docker"
cp tests/unit/fixtures/untrusted-host-process "$WORK/bin/php"
cp tests/unit/fixtures/untrusted-host-process "$WORK/bin/bash"
chmod 700 "$WORK/bin/docker" "$WORK/bin/php" "$WORK/bin/bash" "$WORK/authority"
trap 'status=$?; if [[ "$status" != 0 ]]; then echo "Failed untrusted mock evidence retained at $WORK" >&2; else rm -rf "$WORK"; fi; exit "$status"' EXIT
export ROOT WORK
export WSTM108_MOCK_ONLY=1 WSTM108_MOCK_ROOT="$WORK" WSTM108_MOCK_CHECKOUT="$WORK/checkout"
export WSTM108_MOCK_ORIGIN="$ROOT"
WSTM108_MOCK_BASH="$(command -v bash)"
export WSTM108_MOCK_PHP WSTM108_MOCK_MATRIX_PHP WSTM108_MOCK_BASH
export WSTM108_HOST_AUTHORITY_ROOT="$WORK/authority" PATH="$WORK/bin:$PATH"
php tests/unit/fixtures/untrusted-host-boundaries.php install-mock
native_controls_status=0
php "$WORK/checkout/tests/unit/fixtures/untrusted-authority-controls.php" "$WORK" > "$WORK/native-authority-controls.log" 2>&1 || native_controls_status=$?
if [[ "$native_controls_status" != 0 ]]; then
	printf 'FAIL native-authority-controls child_exit=%d\n' "$native_controls_status" >&2 || exit "$native_controls_status"
	location_reader_status=0
	{
		(
			umask 077
			php "$WORK/checkout/tests/unit/fixtures/untrusted-runtime-diagnostic.php" read-authority \
				"$WORK/first-package-diagnostic" "$native_controls_status" \
				> "$WORK/native-location.stdout.private" 2> "$WORK/native-location.stderr.private"
		) 2>> "$WORK/native-authority-controls.log"
	} 2>/dev/null || location_reader_status=$?
	location=''
	if [[ "$location_reader_status" == 0 && ! -s "$WORK/native-location.stderr.private" ]] \
		&& location_size="$(wc -c 2>/dev/null < "$WORK/native-location.stdout.private")" \
		&& [[ "$location_size" =~ ^[0-9]{1,2}$ && "$location_size" -le 64 ]] \
		&& IFS= read -r location 2>/dev/null < "$WORK/native-location.stdout.private" \
		&& [[ "$location" =~ ^([0-8])\ ([1-9]|1[0-8])\ (0|[1-9]|10)\ (0|[1-9][0-9]{0,3})\ ([0-5])$ ]] \
		&& [[ "$location_size" -eq $((${#location} + 1)) ]] \
		&& [[ ( "${BASH_REMATCH[1]}" == 0 && "${BASH_REMATCH[4]}" == 0 ) || ( "${BASH_REMATCH[1]}" != 0 && "${BASH_REMATCH[4]}" != 0 ) ]] \
		&& [[ ( "${BASH_REMATCH[2]}" -le 9 && "${BASH_REMATCH[3]}" == 0 ) || ( "${BASH_REMATCH[2]}" -ge 10 && "${BASH_REMATCH[3]}" -ge 1 ) ]]; then
		location_source="${BASH_REMATCH[1]}"
		if [[ "$location_source" == 0 ]]; then
			printf 'Native authority location unavailable; checkpoint fields=%s; original_exit=%d\n' "$location" "$native_controls_status" >&2 || exit "$native_controls_status"
		else
			printf 'Native authority location fields=%s; original_exit=%d\n' "$location" "$native_controls_status" >&2 || exit "$native_controls_status"
		fi
	elif [[ "$location_reader_status" == 2 && ! -s "$WORK/native-location.stdout.private" && ! -s "$WORK/native-location.stderr.private" ]]; then
		printf 'Native authority location unavailable; original_exit=%d\n' "$native_controls_status" >&2 || exit "$native_controls_status"
	else
		printf 'Native authority location reader refused; reader_exit=%d; original_exit=%d\n' "$location_reader_status" "$native_controls_status" >&2 || exit "$native_controls_status"
	fi
	exit "$native_controls_status"
fi
printf 'PASS native-authority-controls child_exit=0\n'
export COMPOSE_PROJECT_NAME=owned-untrusted-stage-fixture
export E2E_ARTIFACTS_DIR=e2e-artifacts
export TRACE="$WORK/trace"
(
	cd "$WORK/checkout"
	git init --quiet
	git config --local core.autocrlf false
	git -c core.autocrlf=false add scripts includes tests .github docker-compose*.yml webmastery-site-toolkit-for-mcp.php readme.txt LICENSE CHANGELOG.md
	git -c user.name='QA fixture' -c user.email='qa@example.test' -c core.autocrlf=false commit --quiet -m 'Create isolated source authority fixture' -m 'Co-authored-by: Copilot App <223556219+Copilot@users.noreply.github.com>'
)
MOCK_SOURCE="$(git -C "$WORK/checkout" rev-parse HEAD)"
export MOCK_SOURCE

# Real provenance, native process capture, framing and typed proof parsers run.
# Endpoint/peer/executable identity and named interruption sites in this copy are
# synthetic; its real capture/export/framing/retention logic is unchanged.
# shellcheck disable=SC2329 # A final fake boundary; real Docker is never callable.
docker() { echo "unexpected Docker call" >> "$TRACE"; return 95; }
export -f docker
case_number=0
run_stage() {
	local mode="$1" expected="$2" status=0
	if [[ -n "${WSTM108_MOCK_LIVE:-}" ]]; then
		php "$WSTM108_MOCK_CHECKOUT/tests/unit/fixtures/untrusted-release-proof.php" isolate
	fi
	case_number=$((case_number + 1))
	export WSTM108_MOCK_LIVE="$WORK/cases/$case_number"
	mkdir -m 700 "$WSTM108_MOCK_LIVE"
	export GITHUB_OUTPUT="$WSTM108_MOCK_LIVE/publication.private"
	export GITHUB_RUN_ID=123 GITHUB_RUN_ATTEMPT=1 GITHUB_JOB=synthetic-source
	( umask 077; set -o noclobber; : > "$GITHUB_OUTPUT" )
	mkdir -p "$WORK/checkout/e2e-artifacts"
	: > "$TRACE"
	rm -f "$WORK/checkout/build/wstm116-retention-owned-untrusted-stage-fixture"
	(
		cd "$WORK/checkout"
		bash -c 'source scripts/e2e-test.sh "$1"; run_untrusted_content_qa' -- "$mode"
	) > "$WORK/latest.log" 2>&1 || status=$?
	cp "$WORK/latest.log" "$WSTM108_MOCK_LIVE/public.log"
	cp "$TRACE" "$WSTM108_MOCK_LIVE/commands.log"
	[[ "$status" == "$expected" ]] || { cat "$WORK/latest.log" >&2; echo "Expected $expected, got $status" >&2; exit 1; }
}
for mode in contract e2e all; do
	run_stage "$mode" 0
	[[ "$(grep -c 'untrusted-content-runner.php$' "$TRACE")" == 1 ]]
	[[ "$(grep -c 'untrusted-content-stage.php restored ' "$TRACE")" == 1 ]]
	[[ "$(grep -c 'untrusted-content-stage.php finalize ' "$TRACE")" == 1 ]]
	[[ "$(grep -c 'untrusted-content-stage.php retire ' "$TRACE")" == 1 ]]
	[[ ! -e "$WORK/checkout/build/wstm116-retention-owned-untrusted-stage-fixture" ]]
	for name in private-state private-journal probe loader actor attachment file original-wire.private original-wire-metadata.private.json; do [[ ! -e "$WSTM108_MOCK_LIVE/$name" ]]; done
	original="$(grep -n 'untrusted-content-stage.php original ' "$TRACE" | cut -d: -f1)"
	enabled="$(grep -n 'untrusted-content-stage.php enabled ' "$TRACE" | cut -d: -f1)"
	runner="$(grep -n 'untrusted-content-runner.php$' "$TRACE" | cut -d: -f1)"
	[[ "$original" -lt "$enabled" && "$enabled" -lt "$runner" ]]
done
for phase in acquire original enable enabled runner; do
	case "$phase" in acquire) expected=41 ;; original) expected=42 ;; enable) expected=43 ;; enabled) expected=49 ;; runner) expected=44 ;; esac
	FAIL_STAGE="$phase" run_stage all "$expected"
	# Failed runner evidence must now survive even when resource cleanup passed.
	[[ -f "$WORK/checkout/build/wstm116-retention-owned-untrusted-stage-fixture" ]]
	if [[ "$phase" != runner ]]; then
		if grep -q 'untrusted-content-runner.php$' "$TRACE"; then exit 1; fi
	fi
done
FAIL_STAGE=runner FAIL_RESTORE=1 run_stage all 44
php "$WSTM108_MOCK_CHECKOUT/tests/unit/fixtures/untrusted-host-boundaries.php" first-exits > "$WSTM108_MOCK_LIVE/first-exits.log"
grep -F 'original_status=44 restoration_status=47' "$WSTM108_MOCK_LIVE/first-exits.log"
FAIL_RESTORE=1 run_stage all 47
FAIL_FINALIZE=1 run_stage all 48
[[ -f "$WSTM108_MOCK_LIVE/private-state" && -f "$WSTM108_MOCK_LIVE/private-journal" && -f "$WSTM108_MOCK_LIVE/probe" ]]
for invalid in missing partial foreign-source foreign-project foreign-owner; do
	FAIL_PROOF="$invalid" run_stage all 1
	[[ -f "$WSTM108_MOCK_LIVE/private-state" && -f "$WSTM108_MOCK_LIVE/private-journal" && -f "$WSTM108_MOCK_LIVE/probe" ]]
	if grep -q 'untrusted-content-stage.php finalize ' "$TRACE"; then exit 1; fi
done
FAIL_STAGE=runner FAIL_PROOF=missing run_stage all 44
for fault in http500 malformed-json failed-valid-json partial-spool partial-journal pending-parser pre-handler-stdout provider-stderr both-streams extra-line; do
	expected=1
	case "$fault" in http500|malformed-json|failed-valid-json) expected=44 ;; esac
	WSTM108_MOCK_FAULT="$fault" run_stage all "$expected"
	[[ -f "$WORK/checkout/build/wstm116-retention-owned-untrusted-stage-fixture" ]]
	[[ -f "$WSTM108_MOCK_LIVE/original-wire.private" && -f "$WSTM108_MOCK_LIVE/private-journal" ]]
	if grep -Eq 'untrusted-content-stage.php (finalize|retire) ' "$TRACE"; then exit 1; fi
	if grep -Eq 'unknown-(original|bootstrap|provider|extra)-secret' "$WORK/latest.log"; then exit 1; fi
	php "$WSTM108_MOCK_CHECKOUT/tests/unit/fixtures/untrusted-mock-proof.php" "$fault" > "$WSTM108_MOCK_LIVE/retention-assertions.log"
done
for fault in bad-anchor truncated-anchor acquire-stderr failed-acquire bad-prepare; do
	expected=1
	[[ "$fault" != failed-acquire ]] || expected=41
	WSTM108_MOCK_FAULT="$fault" run_stage all "$expected"
	[[ -f "$WORK/checkout/build/wstm116-retention-owned-untrusted-stage-fixture" && -f "$WSTM108_MOCK_LIVE/private-state" ]]
	if grep -q 'untrusted-content-stage.php retire ' "$TRACE"; then exit 1; fi
	if [[ "$fault" != bad-prepare ]] && grep -q 'untrusted-content-stage.php original ' "$TRACE"; then exit 1; fi
	if grep -q 'unknown-acquire-secret' "$WORK/latest.log"; then exit 1; fi
	php "$WSTM108_MOCK_CHECKOUT/tests/unit/fixtures/untrusted-mock-proof.php" "$fault" > "$WSTM108_MOCK_LIVE/retention-assertions.log"
done
for fault in companion-partial clear-echo clear-exit clear-truncated precommit-controller authorization-collision precommit-evidence companion-replacement success postcommit-ack; do
	expected=1
	case "$fault" in clear-exit) expected=73 ;; precommit-controller) expected=74 ;; success) expected=0 ;; postcommit-ack) expected=75 ;; esac
	WSTM108_RELEASE_FAULT="$fault" run_stage all "$expected"
	php "$WSTM108_MOCK_CHECKOUT/tests/unit/fixtures/untrusted-release-proof.php" "$fault" > "$WSTM108_MOCK_LIVE/release-assertions.log"
	before="$(cat "$TRACE")"
	if [[ "$fault" != success && "$fault" != postcommit-ack ]]; then
		for command in 'bash scripts/destructive-retention.sh cleanup' 'bash scripts/e2e-test.sh all' 'bash scripts/release-qa.sh'; do
			status=0
			( cd "$WORK/checkout"; bash -c "$command" ) > "$WSTM108_MOCK_LIVE/reentry.log" 2>&1 || status=$?
			[[ "$status" != 0 ]]
			grep -F 'RECOVERY REQUIRED' "$WSTM108_MOCK_LIVE/reentry.log" >/dev/null
			[[ "$(cat "$TRACE")" == "$before" ]]
		done
		php "$WSTM108_MOCK_CHECKOUT/tests/unit/fixtures/untrusted-release-proof.php" "$fault" > "$WSTM108_MOCK_LIVE/reentry-assertions.log"
	else
		( cd "$WORK/checkout"; bash scripts/destructive-retention.sh check )
		[[ "$(cat "$TRACE")" == "$before" ]]
	fi
done
FAIL_OWNED_CLEANUP=1 run_stage all 45
[[ "$(cat "$WSTM108_MOCK_LIVE/file")" == original-owned-file && -f "$WSTM108_MOCK_LIVE/actor" && -f "$WSTM108_MOCK_LIVE/attachment" ]]

# The same unchanged retention helper protects all outer teardown/re-entry paths.
before="$(cat "$TRACE")"
for command in \
	'bash scripts/destructive-retention.sh cleanup' \
	'bash scripts/e2e-test.sh all' \
	'bash scripts/release-qa.sh' \
	'bash -c '\''source scripts/e2e-test.sh all; cleanup_compose'\'''; do
	status=0
	( cd "$WORK/checkout"; bash -c "$command" ) > "$WORK/outer.log" 2>&1 || status=$?
	[[ "$status" != 0 ]]
	grep -F 'RECOVERY REQUIRED' "$WORK/outer.log" >/dev/null
	[[ "$(cat "$TRACE")" == "$before" ]]
	[[ "$(cat "$WSTM108_MOCK_LIVE/file")" == original-owned-file && -f "$WSTM108_MOCK_LIVE/actor" && -f "$WSTM108_MOCK_LIVE/private-journal" ]]
done
(
	cd "$WORK/checkout"
	status=0
	bash -c 'source scripts/e2e-test.sh all; run_untrusted_content_qa' > "$WORK/reentry.log" 2>&1 || status=$?
	[[ "$status" != 0 ]]
	grep -F 'RECOVERY REQUIRED' "$WORK/reentry.log" >/dev/null
)
[[ "$(cat "$TRACE")" == "$before" ]]
echo "Untrusted real host capture/proof, ordering, first exits and outer gates passed with synthetic Docker/site data (not runtime acceptance)."
