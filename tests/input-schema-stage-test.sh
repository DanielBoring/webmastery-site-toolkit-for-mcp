#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(pwd)"
WORK="$ROOT/build/input-schema-stage-test-$$"
mkdir -p "$WORK/e2e-artifacts"
trap 'status=$?; if [[ "$status" != 0 ]]; then echo "Failed schema stage evidence retained at $WORK" >&2; else rm -r -- "$WORK"; fi; exit "$status"' EXIT
export ROOT WORK COMPOSE_PROJECT_NAME=owned-input-schema-fixture E2E_ARTIFACTS_DIR=e2e-artifacts
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
	[[ "${FAIL_EXPORT:-0}" != 1 ]] || return 1
	(umask 077; set -o noclobber; cat "$source" > "$destination")
}

# Every compose/HTTP/WP action below is a fake; no runtime is started.
# shellcheck disable=SC2329
compose() {
	printf '%s\n' "$*" >> "$TRACE"
	case "$*" in
		*"input-schema-stage.php acquire "*) [[ "${FAIL_PHASE:-}" != acquire ]] || return 41 ;;
		*"input-schema-stage.php prepare "*) [[ "${FAIL_PHASE:-}" != prepare ]] || return 42 ;;
		*"input-schema-stage.php restore "*) [[ "${FAIL_RESTORE:-0}" != 1 ]] || return 47 ;;
		*"input-schema-stage.php finalize "*) [[ "${FAIL_FINALIZE:-0}" != 1 ]] || return 48 ;;
		*"curl --max-time 2"*)
			if [[ "${FAIL_HTTP:-0}" == 1 ]]; then printf 'not refused\n200'; else printf 'CLI only.\n403'; fi
			;;
		*"WSTM126_DISPOSABLE= wordpress php "*|*"WSTM126_DISPOSABLE=true wordpress php "*)
			if [[ "${FAIL_OPTIN:-0}" == 1 ]]; then return 0; fi
			echo "Set WSTM126_DISPOSABLE=1 only in an owned disposable runtime."; return 1
			;;
		*"input-schema-runner.php")
			local arg artifact owner source boundary
			for arg in "$@"; do
				case "$arg" in
					WSTM126_ARTIFACT=*) artifact="$(fixture_artifact_path "${arg#*=}")" || return $? ;;
					WSTM126_STAGE_TOKEN=*) owner="${arg#*=}" ;;
					WSTM126_SOURCE_SHA=*) source="${arg#*=}" ;;
					WSTM126_BOUNDARY=*) boundary="${arg#*=}" ;;
				esac
			done
			mkdir -p -- "$(dirname "$artifact")"
			(
				unset MSYS_NO_PATHCONV
				php "$ROOT/tests/input-schema-report.php" "$artifact" "$owner" "$source" "$boundary" "${MUTATION:-none}" "${PACKAGE_TEST:-source}"
			) || return $?
			[[ "${MUTATION:-}" != case && "${FAIL_RUNNER:-0}" != 1 ]] || return 44
			;;
	esac
}
export -f compose qa_export_artifact fixture_artifact_path
run_stage() {
	local mode="$1" expected="$2" status=0
	: > "$TRACE"
	rm -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
	(
		cd "$WORK"
		bash -c 'mock=$(declare -f compose qa_export_artifact); source "$ROOT/scripts/e2e-test.sh" "$1"; eval "$mock"; run_input_schema_qa' -- "$mode"
	) > "$WORK/latest.log" 2>&1 || status=$?
	[[ "$status" == "$expected" ]] || { cat "$WORK/latest.log" >&2; echo "Expected $expected, got $status" >&2; exit 1; }
}
for mode in contract e2e all; do
	run_stage "$mode" 0
	count=5
	[[ "$mode" != contract ]] || count=3
	[[ "$mode" != e2e ]] || count=2
	[[ "$(grep -c 'WSTM126_DISPOSABLE=1 .*input-schema-runner.php$' "$TRACE")" == "$count" ]]
	[[ "$(grep -c 'WSTM126_JOURNAL_DIR=/tmp/wstm126-stage/invocations' "$TRACE")" == "$count" ]]
	[[ "$(grep -c '^export-artifact /qa-artifacts/' "$TRACE")" == "$count" ]]
	[[ "$(grep -c 'input-schema-stage.php restore ' "$TRACE")" == 1 ]]
	[[ "$(grep -c 'input-schema-stage.php finalize ' "$TRACE")" == 1 ]]
	test ! -e "$WORK/build/wstm116-retention-owned-input-schema-fixture"
	prepare="$(grep -n 'input-schema-stage.php prepare ' "$TRACE" | cut -d: -f1)"
	optin="$(grep -n 'WSTM126_DISPOSABLE= wordpress php' "$TRACE" | cut -d: -f1)"
	http="$(grep -n 'curl --max-time' "$TRACE" | cut -d: -f1)"
	[[ "$optin" -lt "$prepare" && "$http" -lt "$prepare" ]]
	actual="$(grep 'WSTM126_DISPOSABLE=1 .*input-schema-runner.php$' "$TRACE" | sed -E 's/.*WSTM126_BOUNDARY=([^ ]+).*/\1/' | paste -sd, -)"
	case "$mode" in contract) expected=direct,permission,ability ;; e2e) expected=http,individual ;; all) expected=direct,permission,ability,http,individual ;; esac
	[[ "$actual" == "$expected" ]]
done
mkdir -p "$WORK/package/includes"
cp "$ROOT/webmastery-site-toolkit-for-mcp.php" "$WORK/package/"
for file in class-ability.php class-input.php class-response.php; do
	cp "$ROOT/includes/$file" "$WORK/package/includes/$file"
done
printf '\n/* Isolated package identity negative control. */\n' >> "$WORK/package/includes/class-ability.php"
export E2E_PACKAGE_ROOT=package
PACKAGE_TEST=package run_stage all 0
# Reusing checkout production hashes for this distinct package must fail.
PACKAGE_TEST=source run_stage all 1
test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
unset E2E_PACKAGE_ROOT
for phase in acquire prepare; do
	expected=41; [[ "$phase" != prepare ]] || expected=42
	FAIL_PHASE="$phase" run_stage all "$expected"
	test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
	if grep -q 'WSTM126_DISPOSABLE=1 .*input-schema-runner.php$' "$TRACE"; then exit 1; fi
done
FAIL_OPTIN=1 run_stage all 1
if grep -q 'input-schema-stage.php prepare' "$TRACE"; then exit 1; fi
FAIL_HTTP=1 run_stage all 1
if grep -q 'input-schema-stage.php prepare' "$TRACE"; then exit 1; fi
for mutation in missing partial foreign cleanup hash malformed; do
	MUTATION="$mutation" run_stage all 1
	test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
	if grep -q 'input-schema-stage.php finalize' "$TRACE"; then exit 1; fi
done
MUTATION=case run_stage all 44
test ! -e "$WORK/build/wstm116-retention-owned-input-schema-fixture"
FAIL_RUNNER=1 FAIL_RESTORE=1 run_stage all 44
grep -F 'original_status=44 restoration_status=47' "$WORK/latest.log"
FAIL_RESTORE=1 run_stage all 47
test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
FAIL_FINALIZE=1 run_stage all 48
test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
FAIL_EXPORT=1 run_stage all 1
test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
FAIL_RUNNER=1 FAIL_EXPORT=1 run_stage all 44
test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"

# The unchanged retention guard must also veto outer cleanup.
(
	cd "$WORK"
	bash -c 'source "$ROOT/scripts/e2e-test.sh" all; compose(){ echo forbidden-teardown >> "$TRACE"; }; export E2E_MANAGE_COMPOSE=1; cleanup_compose'
) > "$WORK/outer.log" 2>&1 && exit 1
if grep -q forbidden-teardown "$TRACE"; then exit 1; fi
: > "$TRACE"
(
	cd "$WORK"
	bash -c 'mock=$(declare -f compose qa_export_artifact); source "$ROOT/scripts/e2e-test.sh" all; eval "$mock"; run_input_schema_qa'
) > "$WORK/nested.log" 2>&1 && exit 1
test ! -s "$TRACE"
test -f "$WORK/build/wstm116-retention-owned-input-schema-fixture"
COMPOSE_PROJECT_NAME='' run_stage all 1
test ! -s "$TRACE"
E2E_ARTIFACTS_DIR=missing-schema-output run_stage all 1
test ! -s "$TRACE"
E2E_ARTIFACTS_DIR=../foreign-output run_stage all 1
test ! -s "$TRACE"
run_stage all 0
(
	cd "$WORK"
	bash -c 'mock=$(declare -f compose qa_export_artifact); source "$ROOT/scripts/e2e-test.sh" all; eval "$mock"; trap '\''echo outer-trap >> "$TRACE"'\'' EXIT; run_input_schema_qa'
) > "$WORK/parent.log" 2>&1
grep -Fx outer-trap "$TRACE"
echo "PASS schema mode/order, precredential refusals, exact 152-case proofs, failures and outer retention without Docker"
