#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(pwd)"
WORK="$ROOT/build/untrusted-stage-test-$$"
mkdir -p "$WORK/e2e-artifacts"
cp -R scripts "$WORK/scripts"
trap 'status=$?; if [[ "$status" != 0 ]]; then echo "Failed untrusted mock evidence retained at $WORK" >&2; else rm -rf "$WORK"; fi; exit "$status"' EXIT
export ROOT WORK
export COMPOSE_PROJECT_NAME=owned-untrusted-stage-fixture
export E2E_ARTIFACTS_DIR=e2e-artifacts
export TRACE="$WORK/trace"
MOCK_SOURCE="$(git rev-parse HEAD)"
export MOCK_SOURCE

# This filesystem orchestration mock never starts Docker/WordPress or HTTP.
# The real typed proof verifier is exercised separately by UntrustedStageProofTest.
# shellcheck disable=SC2329 # Exported into orchestration subprocesses.
host_php() (
	unset MSYS_NO_PATHCONV
	case "$1" in
		*"untrusted-provenance.php")
			printf '{"binding":{"owner":"%s","project":"%s","source_sha":"%s"},"mode":"%s"}\n' "$2" "$3" "$MOCK_SOURCE" "$4"
			;;
		*"untrusted-cleanup-proof.php")
			printf 'proof %s %s %s %s %s\n' "$2" "$4" "$5" "$6" "${8:-runner}" >> "$TRACE"
			[[ "$4" == "$MOCK_SOURCE" && "$5" == "$COMPOSE_PROJECT_NAME" && "$6" =~ ^[a-f0-9]{32}$ ]] || return 92
			[[ "$7" == "$WORK/e2e-artifacts/untrusted-$6" ]] || return 96
			[[ "${FAIL_PROOF:-0}" == 0 && "${FAIL_OWNED_CLEANUP:-0}" == 0 ]] || return 46
			if [[ "${8:-}" == --final ]]; then
				[[ ! -e "$WORK/private-state" && ! -e "$WORK/private-journal" && ! -e "$WORK/probe" && ! -e "$WORK/loader" ]] || return 93
			fi
			;;
		*) command php "$@" ;;
	esac
)
# shellcheck disable=SC2329 # Exported into orchestration subprocesses.
compose() {
	printf '%s\n' "$*" >> "$TRACE"
	case "$*" in
		*"untrusted-content-stage.php acquire "*)
			[[ -f build/wstm116-retention-owned-untrusted-stage-fixture ]] || return 90
			printf 'private-owned-state\n' > "$WORK/private-state"
			[[ "${FAIL_STAGE:-}" != acquire ]] || return 41
			;;
		*"untrusted-content-stage.php original "*)
			printf 'owner-authenticated-readonly-probe\n' > "$WORK/probe"
			[[ "${FAIL_STAGE:-}" != original ]] || return 42
			;;
		*"untrusted-content-stage.php enable "*)
			printf 'owned-loader-not-wp-config\n' > "$WORK/loader"
			[[ "${FAIL_STAGE:-}" != enable ]] || return 43
			;;
		*"untrusted-content-stage.php enabled "*)
			[[ "${FAIL_STAGE:-}" != enabled ]] || return 49
			;;
		*"untrusted-content-stage.php restored "*)
			[[ "${FAIL_RESTORE:-0}" != 1 ]] || return 47
			rm -f "$WORK/loader"
			;;
		*"untrusted-content-stage.php finalize "*)
			[[ "${FAIL_FINALIZE:-0}" != 1 ]] || return 48
			rm -f "$WORK/private-state" "$WORK/private-journal" "$WORK/probe"
			;;
		*"untrusted-content-runner.php")
			[[ "$*" == *"WSTM108_ALLOW_DISPOSABLE=1"* && "$*" == *"WSTM108_STAGE_CONTEXT="* && "$*" == *"WSTM108_ARTIFACT="* ]] || return 91
			printf 'private-owned-IDs-and-session-journal\n' > "$WORK/private-journal"
			printf 'owned-actor\n' > "$WORK/actor"
			printf 'owned-attachment\n' > "$WORK/attachment"
			printf 'original-owned-file\n' > "$WORK/file"
			if [[ "${FAIL_OWNED_CLEANUP:-0}" == 0 ]]; then rm -f "$WORK/actor" "$WORK/attachment" "$WORK/file"; fi
			[[ "${FAIL_STAGE:-}" != runner ]] || return 44
			[[ "${FAIL_OWNED_CLEANUP:-0}" == 0 ]] || return 45
			;;
		*) echo "Unexpected mocked Compose command." >&2; return 94 ;;
	esac
}
# shellcheck disable=SC2329 # A final fake boundary; real Docker is never callable.
docker() { echo "unexpected Docker call" >> "$TRACE"; return 95; }
export -f compose host_php docker
run_stage() {
	local mode="$1" expected="$2" status=0
	: > "$TRACE"
	rm -f "$WORK/build/wstm116-retention-owned-untrusted-stage-fixture" "$WORK/private-state" "$WORK/private-journal" "$WORK/probe" "$WORK/loader" "$WORK/actor" "$WORK/attachment" "$WORK/file"
	(
		cd "$WORK"
		bash -c 'mock_compose=$(declare -f compose); mock_php=$(declare -f host_php); source "$WORK/scripts/e2e-test.sh" "$1"; eval "$mock_compose"; eval "$mock_php"; run_untrusted_content_qa' -- "$mode"
	) > "$WORK/latest.log" 2>&1 || status=$?
	[[ "$status" == "$expected" ]] || { cat "$WORK/latest.log" >&2; echo "Expected $expected, got $status" >&2; exit 1; }
}
for mode in contract e2e all; do
	run_stage "$mode" 0
	[[ "$(grep -c 'untrusted-content-runner.php$' "$TRACE")" == 1 ]]
	[[ "$(grep -c 'untrusted-content-stage.php restored ' "$TRACE")" == 1 ]]
	[[ "$(grep -c 'untrusted-content-stage.php finalize ' "$TRACE")" == 1 ]]
	[[ ! -e "$WORK/build/wstm116-retention-owned-untrusted-stage-fixture" ]]
	original="$(grep -n 'untrusted-content-stage.php original ' "$TRACE" | cut -d: -f1)"
	enabled="$(grep -n 'untrusted-content-stage.php enabled ' "$TRACE" | cut -d: -f1)"
	runner="$(grep -n 'untrusted-content-runner.php$' "$TRACE" | cut -d: -f1)"
	[[ "$original" -lt "$enabled" && "$enabled" -lt "$runner" ]]
done
for phase in acquire original enable enabled runner; do
	case "$phase" in acquire) expected=41 ;; original) expected=42 ;; enable) expected=43 ;; enabled) expected=49 ;; runner) expected=44 ;; esac
	FAIL_STAGE="$phase" run_stage all "$expected"
	if [[ "$phase" == runner ]]; then
		[[ ! -e "$WORK/build/wstm116-retention-owned-untrusted-stage-fixture" ]]
	else
		[[ -f "$WORK/build/wstm116-retention-owned-untrusted-stage-fixture" ]]
		if grep -q 'untrusted-content-runner.php$' "$TRACE"; then exit 1; fi
	fi
done
FAIL_STAGE=runner FAIL_RESTORE=1 run_stage all 44
grep -F 'original_status=44 restoration_status=47' "$WORK/latest.log"
FAIL_RESTORE=1 run_stage all 47
FAIL_FINALIZE=1 run_stage all 48
[[ -f "$WORK/private-state" && -f "$WORK/private-journal" && -f "$WORK/probe" ]]
for invalid in missing partial foreign-source foreign-project foreign-owner; do
	FAIL_PROOF="$invalid" run_stage all 1
	[[ -f "$WORK/private-state" && -f "$WORK/private-journal" && -f "$WORK/probe" ]]
	if grep -q 'untrusted-content-stage.php finalize ' "$TRACE"; then exit 1; fi
done
FAIL_STAGE=runner FAIL_PROOF=missing run_stage all 44
FAIL_OWNED_CLEANUP=1 run_stage all 45
[[ "$(cat "$WORK/file")" == original-owned-file && -f "$WORK/actor" && -f "$WORK/attachment" ]]

# The same unchanged retention helper protects all outer teardown/re-entry paths.
before="$(cat "$TRACE")"
for command in \
	'bash scripts/destructive-retention.sh cleanup' \
	'bash scripts/e2e-test.sh all' \
	'bash scripts/release-qa.sh' \
	'bash -c '\''source scripts/e2e-test.sh all; cleanup_compose'\'''; do
	status=0
	( cd "$WORK"; bash -c "$command" ) > "$WORK/outer.log" 2>&1 || status=$?
	[[ "$status" != 0 ]]
	grep -F 'RECOVERY REQUIRED' "$WORK/outer.log" >/dev/null
	[[ "$(cat "$TRACE")" == "$before" ]]
	[[ "$(cat "$WORK/file")" == original-owned-file && -f "$WORK/actor" && -f "$WORK/private-journal" ]]
done
(
	cd "$WORK"
	status=0
	bash -c 'mock_compose=$(declare -f compose); mock_php=$(declare -f host_php); source "$WORK/scripts/e2e-test.sh" all; eval "$mock_compose"; eval "$mock_php"; run_untrusted_content_qa' > "$WORK/reentry.log" 2>&1 || status=$?
	[[ "$status" != 0 ]]
	grep -F 'RECOVERY REQUIRED' "$WORK/reentry.log" >/dev/null
)
[[ "$(cat "$TRACE")" == "$before" ]]
echo "Untrusted serial-stage ordering, first exits, ownership retention and all outer gates passed (mocks only)."
