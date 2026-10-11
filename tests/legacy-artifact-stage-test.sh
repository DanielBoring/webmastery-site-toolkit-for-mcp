#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(pwd)"
mkdir -p build
WORK="$(mktemp -d "$ROOT/build/legacy-artifact-stage-XXXXXXXX")"
export ROOT WORK
trap 'status=$?; if [[ "$status" == 0 ]]; then rm -r -- "$WORK"; else echo "Legacy artifact controls retained at $WORK" >&2; fi; exit "$status"' EXIT

run_case() {
	local scenario="$1" expected="$2" status=0
	mkdir "$WORK/$scenario"
	(
		cd "$WORK/$scenario"
		mkdir e2e-artifacts
		source "$ROOT/scripts/e2e-test.sh" all
		COMPOSE_PROJECT_NAME=owned-legacy-fixture
		WSTM_PRODUCT_RUN=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
		E2E_SOURCE_IDENTITY=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
		E2E_MANAGE_COMPOSE=1
		E2E_KEEP_COMPOSE=0
		WSTM_QA_PRIVATE_OUTPUT="$PWD/private-output"
		mkdir "$WSTM_QA_PRIVATE_OUTPUT"
		export SCENARIO="$scenario"
		# All resource operations are modeled; no Docker command is executed.
		# shellcheck disable=SC2329
		compose() {
			printf '%s\n' "$*" >> trace
			case "$*" in
				"ps -q wordpress")
					[[ "$SCENARIO" != inventory-failure ]] || return 79
					[[ "$SCENARIO" == no-container ]] || printf 'owned-container\n'
					;;
				"exec -T wordpress test -e /qa-artifacts/e2e-summary.json") return 0 ;;
				"exec -T wordpress test -e /qa-artifacts/issue125-permission-summary.json") return 0 ;;
				"exec -T wordpress test -e /qa-artifacts/fresh-install."*)
					[[ "$SCENARIO" != fresh-inventory-failure ]] || return 79
					if [[ "$SCENARIO" == fresh-partial ]]; then
						[[ "$*" == "exec -T wordpress test -e /qa-artifacts/fresh-install.private.json" ]]
					else
						[[ "$SCENARIO" == fresh-receipts || "$SCENARIO" == fresh-export-failure ||
							"$SCENARIO" == fresh-original-failure ]]
					fi
					;;
				"exec -T wordpress test -e /qa-artifacts/"*) return 1 ;;
				"exec -T wordpress test -s /qa-artifacts/media-server-baseline.jsonl")
					[[ "$SCENARIO" == private-diagnostics || "$SCENARIO" == private-directory-collision ]]
					;;
				"exec -T wordpress test -s /qa-artifacts/"*) return 1 ;;
				"down -v --remove-orphans") printf 'down\n' >> events ;;
				*) echo "Unexpected modeled Compose operation: $*" >&2; return 88 ;;
			esac
		}
		# shellcheck disable=SC2329
		qa_export_artifact() {
			printf '%s\n' "$1" >> exports
			[[ "$SCENARIO" != export-failure && "$SCENARIO" != original-failure ]] || return 78
			if [[ "$1" == /qa-artifacts/fresh-install.* &&
				( "$SCENARIO" == fresh-export-failure || "$SCENARIO" == fresh-original-failure ) ]]; then return 78; fi
			[[ ! -e "$2" && ! -L "$2" ]] || return 1
			(umask 077; set -o noclobber; printf '{"passed":true}\n' > "$2")
			printf 'export\n' >> events
		}
		if [[ "$scenario" == retained ]]; then
			wstm116_arm_retention "$WSTM_PRODUCT_RUN" "${E2E_SOURCE_IDENTITY:0:40}"
		fi
		if [[ "$scenario" == keep ]]; then E2E_KEEP_COMPOSE=1; fi
		if [[ "$scenario" == collision ]]; then printf 'original\n' > e2e-artifacts/e2e-summary.json; fi
		if [[ "$scenario" == private-directory-collision ]]; then printf 'original\n' > "$WSTM_QA_PRIVATE_OUTPUT/legacy"; fi
		trap cleanup_compose EXIT
		if [[ "$scenario" == original-failure || "$scenario" == fresh-original-failure ]]; then exit 73; fi
	) > "$WORK/$scenario.log" 2>&1 || status=$?
	[[ "$status" == "$expected" ]] || {
		cat "$WORK/$scenario.log" >&2
		echo "Expected $expected for $scenario, received $status" >&2
		exit 1
	}
}

run_case success 0
[[ "$(cat "$WORK/success/events")" == $'export\nexport\ndown' ]]
[[ "$(cat "$WORK/success/exports")" == $'/qa-artifacts/e2e-summary.json\n/qa-artifacts/issue125-permission-summary.json' ]]
grep -Fxq '{"passed":true}' "$WORK/success/e2e-artifacts/e2e-summary.json"
run_case no-container 0
test ! -e "$WORK/no-container/exports"
run_case keep 0
test ! -e "$WORK/keep/build/wstm116-retention-owned-legacy-fixture"
[[ "$(cat "$WORK/keep/events")" == $'export\nexport' ]]
run_case retained 1
test ! -e "$WORK/retained/trace"
for scenario in export-failure inventory-failure collision; do
	run_case "$scenario" 1
	test -f "$WORK/$scenario/build/wstm116-retention-owned-legacy-fixture"
	test ! -e "$WORK/$scenario/events"
done
grep -Fxq original "$WORK/collision/e2e-artifacts/e2e-summary.json"
run_case original-failure 73
test -f "$WORK/original-failure/build/wstm116-retention-owned-legacy-fixture"
test ! -e "$WORK/original-failure/events"
run_case private-diagnostics 0
test -f "$WORK/private-diagnostics/private-output/legacy/media-server-baseline.jsonl"
test ! -e "$WORK/private-diagnostics/e2e-artifacts/media-server-baseline.jsonl"
[[ "$(cat "$WORK/private-diagnostics/events")" == $'export\nexport\nexport\ndown' ]]
run_case private-directory-collision 1
grep -Fxq original "$WORK/private-directory-collision/private-output/legacy"
test -f "$WORK/private-directory-collision/build/wstm116-retention-owned-legacy-fixture"
[[ "$(cat "$WORK/private-directory-collision/events")" == $'export\nexport' ]]
run_case fresh-receipts 0
[[ "$(cat "$WORK/fresh-receipts/events")" == $'export\nexport\nexport\nexport\nexport\nexport\ndown' ]]
for name in fresh-install.private.json fresh-install.before.private.json fresh-install.inserts.private.json fresh-install.after.private.json; do
	test -f "$WORK/fresh-receipts/private-output/legacy/$name"
	test ! -e "$WORK/fresh-receipts/e2e-artifacts/$name"
	[[ "$(grep -Fxc "/qa-artifacts/$name" "$WORK/fresh-receipts/exports")" == 1 ]]
done
run_case fresh-partial 0
test -f "$WORK/fresh-partial/private-output/legacy/fresh-install.private.json"
test ! -e "$WORK/fresh-partial/private-output/legacy/fresh-install.after.private.json"
[[ "$(cat "$WORK/fresh-partial/events")" == $'export\nexport\nexport\ndown' ]]
for scenario in fresh-export-failure fresh-inventory-failure fresh-original-failure; do
	expected=1
	if [[ "$scenario" == fresh-original-failure ]]; then expected=73; fi
	run_case "$scenario" "$expected"
	test -f "$WORK/$scenario/build/wstm116-retention-owned-legacy-fixture"
	[[ "$(cat "$WORK/$scenario/events")" == $'export\nexport' ]]
done
echo 'Legacy artifact capture, closed exports, failure retention and original-exit controls passed.'
