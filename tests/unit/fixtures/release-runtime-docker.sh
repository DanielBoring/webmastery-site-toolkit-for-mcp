#!/usr/bin/env bash

# External engine/WordPress observations only. The real outer wrappers,
# retention guards, package comparisons and report parsers remain under test.
docker() {
	local command="$*" argument artifact="" owner="" source="" boundary="" days="" position
	case "$1 ${2:-}" in
		"info --format") printf 'mock-engine\n'; return 0 ;;
		"container ls"|"network ls"|"volume ls") return 0 ;;
		*) ;;
	esac
	printf '%s\n' "$command" >> "$TRACE"
	[[ "$command" == "compose --project-name release-runtime-fixture -f docker-compose.yml "* ]] || {
		echo "Unexpected Compose project or internal file selection." >&2; return 90;
	}
	if [[ "${SOURCE_MODE:-0}" == 1 ]]; then
		[[ "$command" != *"docker-compose.release.yml"* ]] || return 91
	else
		[[ "$command" == *" -f docker-compose.release.yml "* ]] || {
			echo "Release runtime attempted checkout Compose configuration." >&2; return 91;
		}
		[[ "${E2E_PACKAGE_ROOT:-}" =~ ^\./build/release-qa-[a-f0-9]{32}/runtime/webmastery-site-toolkit-for-mcp$ &&
			"${E2E_CHECKER_ROOT:-}" == "${E2E_PACKAGE_ROOT%/runtime/*}/checker/webmastery-site-toolkit-for-mcp" ]] || {
			echo "Release runtime did not select fresh, separate package roots." >&2; return 92;
		}
	fi
	if [[ "$command" == *" exec "* ]]; then
		[[ "$command" == *" exec --user 33:33 "* ||
			"$command" == *" exec -T --user 0:0 wordpress install -d -m 0700 -o 33 -g 33 /qa-artifacts" ]] || {
			echo "Candidate execution did not use fixed UID/GID 33." >&2; return 93;
		}
	fi
	if [[ "$command" == *" wordpress wp "* || "$command" == *" wordpress php /var/www/html/.qa-bin/wp "* ]]; then
		[[ "$command" == *" exec --user 33:33 -T -e WP_CLI_CACHE_DIR=/qa-artifacts/parent-cache/wp-cli wordpress php /var/www/html/.qa-bin/wp --url=http://localhost "* ]] || {
			echo "WP-CLI did not use the fixed private cache, phar and local URL." >&2; return 96;
		}
	fi
	for argument in "$@"; do
		case "$argument" in
			WSTM116_ARTIFACT=*|WSTM126_ARTIFACT=*|WSTM111_PRIVACY_ARTIFACT=*) artifact="${argument#*=}" ;;
			WSTM126_STAGE_TOKEN=*) owner="${argument#*=}" ;;
			WSTM116_SOURCE_SHA=*|WSTM126_SOURCE_SHA=*) source="${argument#*=}" ;;
			WSTM116_BOUNDARY=*|WSTM126_BOUNDARY=*) boundary="${argument#*=}" ;;
			WSTM116_EXPECT_TRASH_DAYS=*) days="${argument#*=}" ;;
		esac
	done
	case "$command" in
		*" ps -q wordpress") printf 'owned-runtime-fixture\n' ;;
		*" wordpress test -e /qa-artifacts/"*) test -e "$WORK/container${!#}" ;;
		*" wordpress test -s /qa-artifacts/"*) test -s "$WORK/container${!#}" ;;
		*"WSTM111_DISPOSABLE_SITE=1 "*"/tests/e2e/database-table-privacy-runner.php")
			# Controlled external producer output, not live privacy-case evidence.
			# shellcheck disable=SC2016
			host_php -r '
				define("WEBMASTERY_MCP_E2E_CLIENT_ONLY", true);
				require "tests/e2e/mcp-crud-runner.php";
				webmastery_mcp_e2e_write_summary($argv[1], array("cases" => array(
					array("id" => "controlled-export", "passed" => true,
						"predicates" => array("redacted" => true), "response" => array("success" => true))
				)));
			' "$WORK/container$artifact" ;;
		*" down -v --remove-orphans")
			rm -f "$WORK/private-backup" "$WORK/readonly-probe" "$WORK/retained-attachment" "$WORK/retained-file" "$WORK/retained-marker"
			rm -f "$WORK/schema-journal" "$WORK/schema-probe" "$WORK/schema-post" "$WORK/schema-actor" "$WORK/schema-credential" "$WORK/product-journal"
			if [[ "${FAIL_CLEANUP:-0}" == 1 && "$(grep -c ' down -v --remove-orphans$' "$TRACE")" == 2 ]]; then
				echo "Fixture cleanup failure." >&2; return 47
			fi ;;
		*" up -d")
			[[ "${SOURCE_MODE:-0}" != 1 ]] || return 0
			host_php scripts/release-tools.php runtime-package-after "$E2E_PACKAGE_ROOT" "$RELEASE_ZIP" || return
			[[ -d "$E2E_PACKAGE_ROOT/tests" && ! -L "$E2E_PACKAGE_ROOT/tests" &&
				-z "$(ls -A "$E2E_PACKAGE_ROOT/tests")" ]] || { echo 'Missing prepared readonly tests mountpoint.' >&2; return 91; }
			for mountpoint in scripts/compatibility-baselines.php scripts/compatibility-download.sh .github/compatibility-versions.json; do
				[[ -f "$E2E_PACKAGE_ROOT/$mountpoint" && ! -L "$E2E_PACKAGE_ROOT/$mountpoint" &&
					! -s "$E2E_PACKAGE_ROOT/$mountpoint" ]] || { echo 'Missing prepared readonly file mountpoint.' >&2; return 91; }
			done
			printf '%s\n' "$E2E_CHECKER_ROOT" > "$WORK/checker-root"
			if [[ "${MUTATE_ZIP:-0}" == 1 ]]; then
				# shellcheck disable=SC2016
				host_php -r '$z = new ZipArchive(); $z->open($argv[1]); $z->setArchiveComment("mock ZIP mutation"); $z->close();' "$RELEASE_ZIP"
			fi ;;
		*" port wordpress 80") printf '127.0.0.1:18080\n' ;;
		*"destructive-safety-stage.php acquire "*)
			printf 'private-config-fixture\n' > "$WORK/private-backup"
			printf 'owned-readonly-probe\n' > "$WORK/readonly-probe"
			printf 'stage-acquired\n' >> "$TRACE" ;;
		*"destructive-safety-stage.php restore "*)
			[[ "${FAIL_STAGE_RESTORE:-0}" != 1 ]] || return 47
			rm -f "$WORK/private-backup" "$WORK/readonly-probe" ;;
		*"destructive-safety-preflight.php "*)
			position=$(( $# - 1 ))
			artifact="${!position}"
			mkdir -p "$WORK/container${artifact%/*}"
			printf '{"mock":true}\n' > "$WORK/container$artifact" ;;
		*"destructive-safety-runner.php")
			local complete=true cleanup=true
			mkdir -p "$WORK/container${artifact%/*}"
			if [[ "${FAIL_RUNNER_CLEANUP:-0}" != 0 ]]; then
				printf 'owned-attachment\n' > "$WORK/retained-attachment"
				printf 'original-file-bytes\n' > "$WORK/retained-file"
				printf 'owned-upload-proof\n' > "$WORK/retained-marker"
				complete=false
				cleanup='"refused attachment cleanup"'
			fi
			if [[ "${FAIL_RUNNER_CLEANUP:-0}" == missing ]]; then
				printf '{"completed":true,"source_sha":"%s","boundary":"%s","trash_days":%s,"cleanup":{"fixture":true}}\n' "$source" "$boundary" "$days" > "$WORK/container$artifact"
			else
				printf '{"completed":true,"cleanup_complete":%s,"source_sha":"%s","boundary":"%s","trash_days":%s,"cleanup":{"fixture":%s}}\n' "$complete" "$source" "$boundary" "$days" "$cleanup" > "$WORK/container$artifact"
			fi
			[[ "${FAIL_RUNNER:-0}" != 1 ]] || return 44
			[[ "${FAIL_RUNNER_CLEANUP:-0}" == 0 ]] || return 45 ;;
		*"input-schema-stage.php acquire "*)
			printf 'schema-owner-journal\n' > "$WORK/schema-journal"
			printf 'schema-owned-probe\n' > "$WORK/schema-probe" ;;
		*"input-schema-stage.php restore "*) [[ "${FAIL_SCHEMA_RESTORE:-0}" != 1 ]] || return 57 ;;
		*"input-schema-stage.php finalize "*)
			[[ "${FAIL_SCHEMA_FINALIZE:-0}" != 1 ]] || return 58
			rm -f "$WORK/schema-journal" "$WORK/schema-probe" ;;
		*"WSTM126_DISPOSABLE= wordpress php "*|*"WSTM126_DISPOSABLE=true wordpress php "*)
			echo 'Set WSTM126_DISPOSABLE=1 only in an owned disposable runtime.'; return 1 ;;
		*"curl --max-time 2"*"/tests/e2e/input-schema-runner.php") printf 'CLI only.\n403' ;;
		*"input-schema-runner.php")
			local mutation=none package=source
			mkdir -p "$WORK/container${artifact%/*}"
			[[ "${SOURCE_MODE:-0}" == 1 ]] || package=package
			[[ "${FAIL_SCHEMA_RUNNER:-0}" != 1 ]] || mutation=case
			if [[ "${FAIL_SCHEMA_CLEANUP:-0}" == 1 ]]; then
				mutation=cleanup
				printf 'retained-owned-post\n' > "$WORK/schema-post"
				printf 'retained-owned-actor\n' > "$WORK/schema-actor"
				printf 'retained-owned-credential\n' > "$WORK/schema-credential"
			fi
			host_php tests/input-schema-report.php "$WORK/container$artifact" "$owner" "$source" "$boundary" "$mutation" "$package" || return
			[[ "${FAIL_SCHEMA_RUNNER:-0}" != 1 ]] || return 53
			[[ "${FAIL_SCHEMA_CLEANUP:-0}" != 1 ]] || return 54 ;;
		*"compatibility-baselines.php "*) host_php scripts/compatibility-baselines.php "${!#}" ;;
		*"/tests/e2e/ability-runner.php")
			case "${TAMPER_RUNTIME:-}" in
				production) printf 'changed' >> "$E2E_PACKAGE_ROOT/webmastery-site-toolkit-for-mcp.php" ;;
				file) printf 'unexpected' > "$E2E_PACKAGE_ROOT/tests/injected.php" ;;
				directory) mkdir "$E2E_PACKAGE_ROOT/vendor" ;;
				placeholder) printf 'changed' > "$E2E_PACKAGE_ROOT/scripts/compatibility-baselines.php" ;;
			esac ;;
		*"plugin get plugin-check"*) printf '{"name":"plugin-check","version":"2.1.0","status":"active"}\n' ;;
		*"plugin check "*)
			if [[ "${CHECKER_FAILURE:-0}" == 1 ]]; then
				printf '[{"file":"readme.txt","line":0,"column":0,"type":"ERROR","code":"fixture_error","message":"Rejected"}]\n'
			else
				printf 'Success: Checks complete. No errors found.\n'
			fi ;;
		*"cp -R /qa-package-pristine/. "*)
			host_php scripts/release-tools.php runtime-package "$E2E_CHECKER_ROOT" "$RELEASE_ZIP" ;;
		*"test -f /var/www/html/wp-content/debug.log") return 1 ;;
		*" core is-installed") return 1 ;;
		*"install-owned-wordpress.php")
			local password
			IFS= read -r password || return 1
			[[ "$password" =~ ^[a-f0-9]{64}$ && "$command" == *"WSTM_INITIAL_SITE_URL=http://localhost "* ]] || return 94 ;;
		*"application-password create"*) printf 'fixture-password\n' ;;
		*"--write-out"*"/tests/e2e/parent-assignment-runner.php"|*"--write-out"*"/tests/e2e/post-meta-authorization-runner.php"|*"--write-out"*"/tests/e2e/error-contract-runner.php"|*"--write-out"*"/tests/e2e/metadata-batch-runner.php"|*"--write-out"*"/tests/e2e/seo-metadata-runner.php"|*"--write-out"*"/tests/e2e/database-table-privacy-runner.php") printf '403' ;;
		*" wordpress php -r "*)
			# The private-volume read is an external observation in this mock.
			# Production qa_export_artifact still reserves/validates destination paths.
			artifact="${!#}"
			if [[ "$artifact" == /qa-artifacts/* ]]; then
				[[ "${FAIL_EXPORT:-0}" != 1 ]] || return 65
				cat "$WORK/container$artifact"
			fi ;;
		*) ;;
	esac
}
export -f docker
