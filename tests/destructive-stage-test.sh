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

fixture_artifact_path() {
	[[ "$1" =~ ^/qa-artifacts/[a-zA-Z0-9_./-]+$ && "$1" != *..* ]] || {
		echo "Invalid mocked private artifact path: $1" >&2; return 91;
	}
	printf '%s\n' "$WORK/e2e-artifacts/private-volume/${1#/qa-artifacts/}"
}

# Model transport only; reports are still checked by the real host validators.
qa_export_artifact() {
	local source destination="$2"
	source="$(fixture_artifact_path "$1")" || return $?
	printf 'export-artifact %s %s\n' "$1" "$destination" >> "$TRACE"
	[[ "$destination" == e2e-artifacts/* && "$destination" != *..* &&
		! -e "$destination" && ! -L "$destination" && -f "$source" && ! -L "$source" ]] || {
		echo "Missing or unsafe mocked artifact export." >&2; return 1;
	}
	[[ "${FAIL_EXPORT:-0}" != 1 || "$1" == */preflight.json ]] || return 1
	(umask 077; set -o noclobber; cat "$source" > "$destination")
}

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
			local arg artifact
			for arg in "$@"; do
				case "$arg" in
					/qa-artifacts/*) artifact="$(fixture_artifact_path "$arg")" || return $? ;;
				esac
			done
			[[ -n "${artifact:-}" ]] || { echo "Missing mock preflight report path." >&2; return 91; }
			mkdir -p -- "$(dirname "$artifact")"
			printf '{"mock_preflight":true}\n' > "$artifact"
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
			local arg artifact source boundary days
			for arg in "$@"; do
				case "$arg" in
					WSTM116_ARTIFACT=*) artifact="$(fixture_artifact_path "${arg#*=}")" || return $? ;;
					WSTM116_SOURCE_SHA=*) source="${arg#*=}" ;;
					WSTM116_BOUNDARY=*) boundary="${arg#*=}" ;;
					WSTM116_EXPECT_TRASH_DAYS=*) days="${arg#*=}" ;;
				esac
			done
			mkdir -p -- "$(dirname "$artifact")"
			printf '{"completed":true,"cleanup_complete":true,"source_sha":"%s","boundary":"%s","trash_days":%s,"cleanup":{"fixture":true}}\n' "$source" "$boundary" "$days" > "$artifact"
			[[ "${FAIL_STAGE:-}" != runner ]] || return 44
			;;
	esac
}
export -f compose qa_export_artifact fixture_artifact_path
run_stage() {
	local mode="$1" expected="$2" status=0
	: > "$TRACE"
	# Reset only this mock's retained marker between independently asserted cases.
	rm -f "$WORK/build/wstm116-retention-owned-destructive-stage-fixture"
	(
		cd "$WORK"
		# qa-compose defines both interfaces; restore the isolated fixture mocks.
		bash -c 'mock=$(declare -f compose qa_export_artifact); source "$ROOT/scripts/e2e-test.sh" "$1"; eval "$mock"; run_destructive_safety_qa' -- "$mode"
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
	[[ "$(grep -c '^export-artifact /qa-artifacts/' "$TRACE")" == "$((expected + 1))" ]]
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
FAIL_EXPORT=1 run_stage all 1
test -f "$WORK/build/wstm116-retention-owned-destructive-stage-fixture"
FAIL_STAGE=runner FAIL_EXPORT=1 run_stage all 44
test -f "$WORK/build/wstm116-retention-owned-destructive-stage-fixture"
COMPOSE_PROJECT_NAME='' run_stage all 1
test ! -s "$TRACE"

# Ensure the private EXIT trap did not replace a caller's cleanup.
(
	cd "$WORK"
	bash -c 'mock=$(declare -f compose qa_export_artifact); source "$ROOT/scripts/e2e-test.sh" all; eval "$mock"; trap '\''echo parent-cleanup >> "$TRACE"'\'' EXIT; run_destructive_safety_qa'
) > "$WORK/parent.log" 2>&1
grep -Fx 'parent-cleanup' "$TRACE"
echo 'PASS eight serial safety invocations, source/opt-in evidence paths, audit ordering, restoration failures and parent cleanup'
