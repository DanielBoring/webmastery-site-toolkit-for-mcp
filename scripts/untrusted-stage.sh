#!/usr/bin/env bash

run_untrusted_content_qa() (
	: "${COMPOSE_PROJECT_NAME:?Untrusted QA requires an explicitly owned disposable project}"
	[[ "$E2E_ARTIFACTS_DIR" =~ ^[a-zA-Z0-9_-]+$ ]] || { echo "Unsafe untrusted artifact directory." >&2; exit 1; }
	wstm116_require_no_retention || exit $?
	local owner source directory container_directory host_root host_directory acquired=0 cleanup_verified=0
	local first_failure=0 status
	host_root="$(cd "$E2E_SCRIPT_ROOT/.." && pwd -P)"
	[[ "$(pwd -P)" == "$host_root" ]] || { echo "Untrusted QA requires its explicit source invocation root." >&2; exit 1; }
	owner="$(host_php -r 'echo bin2hex(random_bytes(16));')"
	source="$(git rev-parse HEAD)"
	[[ "$owner" =~ ^[a-f0-9]{32}$ && "$source" =~ ^[a-f0-9]{40}$ ]] || exit 1
	directory="${E2E_ARTIFACTS_DIR}/untrusted-${owner}"
	host_directory="$host_root/$directory"
	container_directory="${CONTAINER_PLUGIN_ROOT}/${directory}"
	mkdir "$directory" || exit $?
	( set -o noclobber; : > "$directory/stage.log" ) || exit $?
	( set -o noclobber; host_php "$E2E_SCRIPT_ROOT/untrusted-provenance.php" "$owner" "$COMPOSE_PROJECT_NAME" "$QA_MODE" "$directory" > "$directory/context.json" ) || exit $?
	untrusted_command() {
		compose exec -T -e WSTM108_STAGE_DISPOSABLE=1 wordpress php \
			"${CONTAINER_PLUGIN_ROOT}/tests/e2e/untrusted-content-stage.php" "$1" "$container_directory/context.json"
	}
	untrusted_proof() {
		host_php "$E2E_SCRIPT_ROOT/untrusted-cleanup-proof.php" runner.json context.json "$source" "$COMPOSE_PROJECT_NAME" "$owner" "$host_directory" "$@"
	}
	# shellcheck disable=SC2329 # Invoked by this subshell's EXIT trap.
	untrusted_finish() {
		local original=$? restoration=1 finalization=1 retention=1
		trap - EXIT
		if [[ "$acquired" == 1 ]]; then
			restoration=0
			untrusted_command restored 2>&1 | tee -a "$directory/stage.log" || restoration=$?
			if [[ "$restoration" == 0 && "$cleanup_verified" == 1 ]]; then
				finalization=0
				untrusted_command finalize 2>&1 | tee -a "$directory/stage.log" || finalization=$?
				if [[ "$finalization" == 0 ]]; then
					untrusted_proof --final 2>&1 | tee -a "$directory/stage.log" || finalization=$?
				fi
				if [[ "$finalization" == 0 ]]; then
					wstm116_clear_retention "$owner" "$source" 2>&1 | tee -a "$directory/stage.log" && retention=0
				fi
			fi
		fi
		printf 'untrusted_source=%s original_status=%s restoration_status=%s finalization_status=%s retention_status=%s owner=%s project=%s\n' \
			"$source" "$original" "$restoration" "$finalization" "$retention" "$owner" "$COMPOSE_PROJECT_NAME" | tee -a "$directory/stage.log"
		if [[ "$original" != 0 ]]; then exit "$original"; fi
		if [[ "$restoration" != 0 ]]; then exit "$restoration"; fi
		if [[ "$finalization" != 0 ]]; then exit "$finalization"; fi
		exit "$retention"
	}
	wstm116_arm_retention "$owner" "$source" 2>&1 | tee -a "$directory/stage.log" || exit $?
	trap untrusted_finish EXIT
	untrusted_command acquire 2>&1 | tee -a "$directory/stage.log" || exit $?
	acquired=1
	for operation in original enable enabled; do
		untrusted_command "$operation" 2>&1 | tee -a "$directory/stage.log" || exit $?
	done
	status=0
	compose exec -T -e WSTM108_ALLOW_DISPOSABLE=1 \
		-e WSTM108_STAGE_CONTEXT="$container_directory/context.json" \
		-e WSTM108_ARTIFACT="$container_directory/runner.json" \
		wordpress php -d memory_limit=1G "${CONTAINER_PLUGIN_ROOT}/tests/e2e/untrusted-content-runner.php" \
		2>&1 | tee -a "$directory/stage.log" || status=$?
	if [[ "$status" != 0 ]]; then first_failure="$status"; fi
	if untrusted_proof 2>&1 | tee -a "$directory/stage.log"; then
		cleanup_verified=1
	else
		if [[ "$first_failure" != 0 ]]; then exit "$first_failure"; fi
		exit 1
	fi
	exit "$first_failure"
)
