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
		# shellcheck disable=SC2030 # The next capture fixture supplies its own project and run.
		COMPOSE_PROJECT_NAME=owned-legacy-fixture
		# shellcheck disable=SC2030
		WSTM_PRODUCT_RUN=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
		E2E_SOURCE_IDENTITY=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
		E2E_MANAGE_COMPOSE=1
		E2E_KEEP_COMPOSE=0
		# shellcheck disable=SC2030 # Private output is deliberately local to this case.
		WSTM_QA_PRIVATE_OUTPUT="$PWD/private-output"
		mkdir "$WSTM_QA_PRIVATE_OUTPUT"
		# shellcheck disable=SC2030 # Each capture case selects a new scenario.
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

run_privacy_case() {
	local scenario="$1" expected="$2" status=0
	mkdir "$WORK/$scenario"
	(
		cd "$WORK/$scenario"
		mkdir e2e-artifacts volume
		source "$ROOT/scripts/e2e-test.sh" all
		COMPOSE_PROJECT_NAME=owned-legacy-fixture
		WSTM_PRODUCT_RUN=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
		E2E_SOURCE_IDENTITY=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
		E2E_MANAGE_COMPOSE=1
		E2E_KEEP_COMPOSE=0
		# shellcheck disable=SC2031 # This case replaces, rather than inherits, the previous scenario.
		export SCENARIO="$scenario"
		# shellcheck disable=SC2016 # PHP variables must not expand in Bash.
		php -r '
			define("WEBMASTERY_MCP_E2E_CLIENT_ONLY", true);
			require getenv("ROOT") . "/tests/e2e/mcp-crud-runner.php";
			$source = file_get_contents(getenv("ROOT") . "/tests/e2e/database-table-privacy-runner.php");
			if (preg_match("/^function wstm111_privacy_check\b.*?^}/ms", $source, $match) !== 1) {
				throw new RuntimeException("Actual privacy producer was not found.");
			}
			eval($match[0]);
			foreach (array("native" => 2, "http" => 3) as $boundary => $count) {
				$GLOBALS["wstm111_privacy_report"] = array("cases" => array());
				for ($i = 0; $i < $count; $i++) {
					wstm111_privacy_check("controlled-" . $boundary . "-" . $i,
						array("names_redacted" => true), array("success" => true));
				}
				webmastery_mcp_e2e_write_summary("volume/database-table-privacy-" . $boundary . ".json",
					$GLOBALS["wstm111_privacy_report"]);
			}
			webmastery_mcp_e2e_write_summary("volume/e2e-summary.json", array("passed" => true));
		'
		printf 'private diagnostic sentinel\n' > volume/not-in-inventory.private.json
		# Only external container observations/reads are modeled. Capture and host export are real.
		# shellcheck disable=SC2329
		compose() {
			case "$*" in
				"ps -q wordpress")
					[[ "$SCENARIO" == privacy-no-container || "$SCENARIO" == privacy-wrapper-no-container ]] || printf 'owned-container\n' ;;
				"exec -T wordpress test -e /qa-artifacts/"*)
					local name="${*: -1}"
					name="${name##*/}"
					if [[ "$name" == database-table-privacy-native.json && "$SCENARIO" == privacy-inventory-failure ]]; then return 79; fi
					test -f "volume/$name" ;;
				"exec -T wordpress test -s /qa-artifacts/"*) return 1 ;;
				"exec -T wordpress curl "*) printf '403' ;;
				"exec -T wordpress grep -Fxq CLI only. /tmp/wstm111-cli-response") return 0 ;;
				"exec -T wordpress grep -Fxq CLI only. /tmp/wstm118-cli-response") return 0 ;;
				"exec -T wordpress cp "*"error-contract-fixture.php "*)
					printf 'installed\n' > error-fixture ;;
				"exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/wstm-issue118-errors.php")
					rm error-fixture
					printf 'removed\n' > error-fixture-cleanup ;;
				"exec -T -e WSTM118_DISPOSABLE=1 "*)
					[[ "$SCENARIO" != privacy-wrapper-runner-failure ]] || return 74 ;;
				"exec -T -e WSTM111_DISPOSABLE_SITE=1 "*) return 0 ;;
				"down -v --remove-orphans") printf 'down\n' > down ;;
				*) echo "Unexpected modeled operation: $*" >&2; return 88 ;;
			esac
		}
		# shellcheck disable=SC2329
		qa_artifact_read() {
			local name="${*: -1}"
			name="${name##*/}"
			if [[ "$name" == database-table-privacy-native.json && "$SCENARIO" == privacy-read-failure ]]; then return 78; fi
			cat "volume/$name"
		}
		trap cleanup_compose EXIT
		if [[ "$SCENARIO" == privacy-wrapper-* ]]; then
			QA_MODE=e2e
			rm volume/database-table-privacy-native.json
			if [[ "$SCENARIO" == privacy-wrapper-runner-failure ]]; then rm volume/database-table-privacy-http.json; fi
			run_error_contract_qa || exit $?
			[[ "${DATABASE_PRIVACY_ARTIFACTS[*]}" == database-table-privacy-http.json ]]
			test ! -e error-fixture
			test -f error-fixture-cleanup
			case "$SCENARIO" in
				privacy-wrapper-missing|privacy-wrapper-original-failure) rm volume/database-table-privacy-http.json ;;
			esac
		elif [[ "$SCENARIO" != privacy-optional ]]; then
			if [[ "$SCENARIO" != privacy-http-only ]]; then run_database_privacy_qa native; fi
			if [[ "$SCENARIO" != privacy-native-only ]]; then run_database_privacy_qa http; fi
		fi
		case "$SCENARIO" in
			privacy-missing-native|privacy-http-only|privacy-original-failure) rm volume/database-table-privacy-native.json ;;
			privacy-missing-http|privacy-native-only|privacy-optional) rm volume/database-table-privacy-http.json ;;
			privacy-collision) printf 'original\n' > e2e-artifacts/database-table-privacy-native.json ;;
		esac
		trap cleanup_compose EXIT
		if [[ "$SCENARIO" == privacy-original-failure || "$SCENARIO" == privacy-wrapper-original-failure ]]; then exit 73; fi
	) > "$WORK/$scenario.log" 2>&1 || status=$?
	[[ "$status" == "$expected" ]] || {
		cat "$WORK/$scenario.log" >&2
		echo "Expected $expected for $scenario, received $status" >&2
		exit 1
	}
}

run_privacy_case privacy-success 0
for boundary in native http; do
	cmp "$WORK/privacy-success/volume/database-table-privacy-$boundary.json" \
		"$WORK/privacy-success/e2e-artifacts/database-table-privacy-$boundary.json"
done
# shellcheck disable=SC2016 # PHP variables must not expand in Bash.
php -r '
	foreach (array("native" => 2, "http" => 3) as $boundary => $count) {
		$report = json_decode(file_get_contents($argv[1] . "/database-table-privacy-" . $boundary . ".json"), true, 512, JSON_THROW_ON_ERROR);
		if (array_keys($report) !== array("cases") || count($report["cases"]) !== $count) {
			throw new RuntimeException("Exported controlled report count/shape differs.");
		}
		foreach ($report["cases"] as $case) {
			if (array_keys($case) !== array("id", "passed", "predicates", "response") || $case["passed"] !== true) {
				throw new RuntimeException("Exported controlled case shape differs.");
			}
		}
	}
' "$WORK/privacy-success/e2e-artifacts"
[[ "$(find "$WORK/privacy-success/e2e-artifacts" -type f | wc -l)" == 3 ]]
test ! -e "$WORK/privacy-success/e2e-artifacts/not-in-inventory.private.json"
for scenario in privacy-missing-native privacy-missing-http privacy-inventory-failure privacy-read-failure privacy-collision privacy-no-container; do
	run_privacy_case "$scenario" 1
	test ! -e "$WORK/$scenario/down"
	test -f "$WORK/$scenario/build/wstm116-retention-owned-legacy-fixture"
done
grep -Fxq original "$WORK/privacy-collision/e2e-artifacts/database-table-privacy-native.json"
test ! -e "$WORK/privacy-missing-native/e2e-artifacts/database-table-privacy-native.json"
test ! -e "$WORK/privacy-missing-http/e2e-artifacts/database-table-privacy-http.json"
test ! -s "$WORK/privacy-read-failure/e2e-artifacts/database-table-privacy-native.json"
run_privacy_case privacy-native-only 0
run_privacy_case privacy-http-only 0
run_privacy_case privacy-optional 0
run_privacy_case privacy-original-failure 73
test ! -e "$WORK/privacy-original-failure/down"
test -f "$WORK/privacy-original-failure/build/wstm116-retention-owned-legacy-fixture"
test ! -e "$WORK/privacy-native-only/e2e-artifacts/database-table-privacy-http.json"
test ! -e "$WORK/privacy-http-only/e2e-artifacts/database-table-privacy-native.json"
test ! -e "$WORK/privacy-optional/e2e-artifacts/database-table-privacy-http.json"
run_privacy_case privacy-wrapper-success 0
test -f "$WORK/privacy-wrapper-success/e2e-artifacts/database-table-privacy-http.json"
test ! -e "$WORK/privacy-wrapper-success/e2e-artifacts/database-table-privacy-native.json"
test -f "$WORK/privacy-wrapper-success/down"
for scenario in privacy-wrapper-missing privacy-wrapper-no-container privacy-wrapper-original-failure privacy-wrapper-runner-failure; do
	expected=1
	[[ "$scenario" != privacy-wrapper-original-failure ]] || expected=73
	[[ "$scenario" != privacy-wrapper-runner-failure ]] || expected=74
	run_privacy_case "$scenario" "$expected"
	test ! -e "$WORK/$scenario/down"
	test -f "$WORK/$scenario/error-fixture-cleanup"
	test ! -e "$WORK/$scenario/error-fixture"
	test -f "$WORK/$scenario/build/wstm116-retention-owned-legacy-fixture"
done
test ! -e "$WORK/privacy-wrapper-missing/e2e-artifacts/database-table-privacy-http.json"
echo 'Actual error-contract HTTP wrapper: parent requirement retained; missing/no-container fail closed; fixture cleanup and original statuses 73/74 preserved.'
echo 'Actual privacy producer/export: controlled native=2 HTTP=3 JSON cases; required-source/read/collision failures closed.'
echo 'Legacy artifact capture, closed exports, failure retention and original-exit controls passed.'
