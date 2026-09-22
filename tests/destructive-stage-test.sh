#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(pwd)"
WORK="$ROOT/build/destructive-stage-test-$$"
mkdir -p "$WORK/e2e-artifacts"
trap 'status=$?; if [[ "$status" != 0 ]]; then echo "Failed stage fixtures retained at $WORK" >&2; else rm -rf "$WORK"; fi; exit "$status"' EXIT
export ROOT WORK
export COMPOSE_PROJECT_NAME=owned-destructive-stage-fixture
export E2E_ARTIFACTS_DIR=e2e-artifacts
export TRACE="$WORK/trace"

# No Docker, WordPress, HTTP server or real configuration is used by this mock.
# shellcheck disable=SC2329
compose() {
	printf '%s\n' "$*" >> "$TRACE"
	case "$*" in
		*"destructive-safety-stage.php acquire "*)
			[[ "${FAIL_STAGE:-}" != acquire ]] || return 41
			;;
		*"destructive-safety-preflight.php "*)
			[[ "${FAIL_STAGE:-}" != preflight ]] || return 42
			;;
		*"destructive-safety-stage.php prepare "*)
			[[ "${FAIL_STAGE:-}" != prepare ]] || return 49
			;;
		*"destructive-safety-stage.php enabled "*)
			[[ "${FAIL_STAGE:-}" != configure ]] || return 43
			;;
		*"destructive-safety-stage.php disabled "*)
			[[ "${FAIL_DISABLE:-0}" != 1 ]] || return 48
			;;
		*"destructive-safety-stage.php restore "*)
			[[ "${FAIL_RESTORE:-0}" != 1 ]] || return 47
			;;
		*"destructive-safety-runner.php")
			[[ "$*" == *"WSTM116_DISPOSABLE=1"* && "$*" == *"WSTM116_SOURCE_SHA="* && "$*" == *"WSTM116_ARTIFACT="* ]] || return 91
			[[ "${FAIL_STAGE:-}" != runner ]] || return 44
			;;
	esac
}
export -f compose
run_stage() {
	local mode="$1" expected="$2" status=0
	: > "$TRACE"
	(
		cd "$WORK"
		# qa-compose defines compose; restore the exported fixture after sourcing.
		bash -c 'mock=$(declare -f compose); source "$ROOT/scripts/e2e-test.sh" "$1"; eval "$mock"; run_destructive_safety_qa' -- "$mode"
	) > "$WORK/latest.log" 2>&1 || status=$?
	[[ "$status" == "$expected" ]] || { cat "$WORK/latest.log" >&2; echo "Expected $expected, got $status" >&2; exit 1; }
}
for mode in contract e2e all; do
	run_stage "$mode" 0
	expected=4
	[[ "$mode" != all ]] || expected=8
	[[ "$(grep -c 'destructive-safety-runner.php$' "$TRACE")" == "$expected" ]]
	[[ "$(grep -c 'destructive-safety-stage.php restore ' "$TRACE")" == 1 ]]
	audit_line="$(grep -n 'destructive-safety-preflight.php ' "$TRACE" | cut -d: -f1)"
	config_line="$(grep -n 'destructive-safety-stage.php enabled ' "$TRACE" | cut -d: -f1)"
	prepare_line="$(grep -n 'destructive-safety-stage.php prepare ' "$TRACE" | cut -d: -f1)"
	[[ "$audit_line" -lt "$prepare_line" && "$prepare_line" -lt "$config_line" ]]
	for trash in 30 0; do
		for boundary in direct ability http individual; do
			if [[ "$mode" == contract && "$boundary" =~ ^(http|individual)$ ]] || [[ "$mode" == e2e && "$boundary" =~ ^(direct|ability)$ ]]; then continue; fi
			grep -E "WSTM116_BOUNDARY=${boundary} .*WSTM116_EXPECT_TRASH_DAYS=${trash} .*WSTM116_STAGE_TOKEN=[a-f0-9]{32} .*WSTM116_SOURCE_SHA=[a-f0-9]{40} .*WSTM116_ARTIFACT=.*(enabled|disabled)-${boundary}.json " "$TRACE" >/dev/null
		done
	done
done
for failure in acquire preflight prepare configure runner; do
	case "$failure" in acquire) expected=41 ;; preflight) expected=42 ;; prepare) expected=49 ;; configure) expected=43 ;; runner) expected=44 ;; esac
	FAIL_STAGE="$failure" run_stage all "$expected"
	if [[ "$failure" == acquire ]]; then
		if grep -q 'destructive-safety-stage.php restore ' "$TRACE"; then exit 1; fi
	else
		grep -q 'destructive-safety-stage.php restore ' "$TRACE"
	fi
	if [[ "$failure" == runner ]]; then [[ "$(grep -c 'destructive-safety-runner.php$' "$TRACE")" == 8 ]]; fi
	if [[ "$failure" != runner ]]; then
		if grep -q 'destructive-safety-runner.php$' "$TRACE"; then exit 1; fi
	fi
done
FAIL_STAGE=runner FAIL_RESTORE=1 run_stage all 44
grep -F 'original_status=44 restoration_status=47' "$WORK/latest.log"
FAIL_RESTORE=1 run_stage all 47
grep -F 'original_status=0 restoration_status=47' "$WORK/latest.log"
FAIL_STAGE=runner FAIL_DISABLE=1 run_stage all 44
grep -F 'original_status=44 restoration_status=0' "$WORK/latest.log"
FAIL_DISABLE=1 run_stage all 48
grep -F 'original_status=48 restoration_status=0' "$WORK/latest.log"
COMPOSE_PROJECT_NAME='' run_stage all 1
test ! -s "$TRACE"

# Ensure the private EXIT trap did not replace a caller's cleanup.
(
	cd "$WORK"
	bash -c 'mock=$(declare -f compose); source "$ROOT/scripts/e2e-test.sh" all; eval "$mock"; trap '\''echo parent-cleanup >> "$TRACE"'\'' EXIT; run_destructive_safety_qa'
) > "$WORK/parent.log" 2>&1
grep -Fx 'parent-cleanup' "$TRACE"
echo 'PASS eight serial safety invocations, source/opt-in evidence paths, audit ordering, restoration failures and parent cleanup'
