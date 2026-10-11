#!/usr/bin/env bash

qa_quiet_volume_consumers() {
	local capture="$1" suffix identifier observed=() expected_consumers=()
	for suffix in wp_data qa_artifacts mysql_data; do
		(
			umask 077
			set -o noclobber
			docker container ls -aq --no-trunc --filter "volume=${COMPOSE_PROJECT_NAME}_$suffix" > "$capture.$suffix"
		) || return $?
		[[ ! -L "$capture.$suffix" && "$(wc -c < "$capture.$suffix")" -le 130 ]] || {
			echo 'HTTP-quiet volume consumer inventory exceeded its exact domain.' >&2; return 1;
		}
		mapfile -t observed < "$capture.$suffix"
		expected_consumers=("$WSTM_QUIET_WP")
		if [[ "$suffix" == mysql_data ]]; then
			expected_consumers=("$WSTM_QUIET_DB")
		elif [[ -n "${WSTM_QUIET_CLI:-}" ]]; then
			expected_consumers+=("$WSTM_QUIET_CLI")
		fi
		for identifier in "${observed[@]}"; do
			[[ "$identifier" =~ ^[a-f0-9]{64}$ ]] || {
				echo 'HTTP-quiet volume consumer identity is invalid.' >&2; return 1;
			}
		done
		[[ "${#observed[@]}" == "${#expected_consumers[@]}" &&
			"$(printf '%s\n' "${observed[@]}" | sort)" == "$(printf '%s\n' "${expected_consumers[@]}" | sort)" ]] || {
			echo 'HTTP-quiet volume has missing, duplicate or foreign consumers.' >&2; return 1;
		}
	done
}

qa_quiet_snapshot() {
	local mode="$1" capture="$WSTM_QUIET_DIRECTORY/snapshot-${RANDOM}-${RANDOM}.private.json" snapshots=()
	shopt -s nullglob
	snapshots=("$WSTM_QUIET_DIRECTORY"/snapshot-*.private.json)
	shopt -u nullglob
	[[ "${#snapshots[@]}" -lt 32 && $((SECONDS - WSTM_QUIET_STARTED)) -lt 600 ]] || {
		echo 'HTTP-quiet observation limits exhausted.' >&2; return 1;
	}
	qa_verify_resources || return $?
	local identifiers observed=() expected_containers=("$WSTM_QUIET_WP" "$WSTM_QUIET_DB")
	identifiers="$(docker container ls -aq --no-trunc --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME}")" || return $?
	mapfile -t observed < <(printf '%s\n' "$identifiers" | sort)
	if [[ -n "${WSTM_QUIET_CLI:-}" ]]; then expected_containers+=("$WSTM_QUIET_CLI"); fi
	[[ "${#observed[@]}" == "${#expected_containers[@]}" && \
		"$(printf '%s\n' "${observed[@]}")" == "$(printf '%s\n' "${expected_containers[@]}" | sort)" ]] || {
		echo 'HTTP-quiet project container inventory changed.' >&2; return 1;
	}
	(
		umask 077
		set -o noclobber
		qa_quiet_volume_consumers "$capture.before" || return $?
		docker container inspect "$WSTM_QUIET_WP" "$WSTM_QUIET_DB" > "$capture.containers" || return $?
		docker network inspect "${COMPOSE_PROJECT_NAME}_default" > "$capture.network" || return $?
		if [[ "$mode" == cli || "$mode" == stopped-cli ]]; then
			docker container inspect "$WSTM_QUIET_CLI" > "$capture.cli" || return $?
		fi
		local parts=("$capture.containers" "$capture.network")
		if [[ "$mode" == cli || "$mode" == stopped-cli ]]; then parts+=("$capture.cli"); fi
		# shellcheck disable=SC2016 # PHP variables must remain literal in the host parser.
		host_php -r '
		$records = [];
		foreach (array_slice($argv, 1) as $path) {
			if (is_link($path) || filesize($path) > 1048576) { throw new RuntimeException("Quiet inspection bound."); }
			$part = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
			if (!is_array($part)) { throw new RuntimeException("Quiet inspection shape."); }
			$records = array_merge($records, $part);
		}
		echo json_encode($records, JSON_THROW_ON_ERROR);' "${parts[@]}" > "$capture" || return $?
		[[ "$(wc -c < "$capture")" -le 1048576 ]] || return 1
		host_php scripts/qa-http-quiet.php "$mode" < "$capture" || return $?
		qa_quiet_volume_consumers "$capture.after"
	)
}

qa_quiet_assert_cli() {
	[[ -n "${WSTM_QUIET_CLI:-}" && "$1" == "$WSTM_QUIET_CLI" ]] || {
		echo 'Unknown HTTP-quiet installer container.' >&2; return 1;
	}
	qa_quiet_snapshot cli
}

qa_quiet_export_failure() {
	local name directory="${1:-${WSTM_QA_PRIVATE_OUTPUT}/legacy}" required="${2:-0}" status=0
	[[ ! -L "$directory" && ( ! -e "$directory" || -d "$directory" ) ]] || return 1
	if [[ ! -d "$directory" ]]; then (umask 077; mkdir "$directory") || return $?; fi
	for name in fresh-install.private.json fresh-install.before.private.json fresh-install.inserts.private.json fresh-install.after.private.json; do
		qa_quiet_assert_cli "$WSTM_QUIET_CLI" || return $?
		status=0
		docker exec --user 33:33 "$WSTM_QUIET_CLI" test -e "/qa-artifacts/$name" || status=$?
		if [[ "$status" == 1 ]]; then
			[[ "$required" == 0 ]] || {
				echo 'Complete preparation originals missing; refusing HTTP restoration.' >&2; return 1;
			}
			continue
		fi
		[[ "$status" == 0 ]] || return "$status"
		qa_export_artifact "/qa-artifacts/$name" "$directory/$name" "$WSTM_QUIET_CLI" || return $?
	done
}

qa_quiet_remove_cli() {
	qa_quiet_assert_cli "$WSTM_QUIET_CLI" || return $?
	docker stop --time 5 "$WSTM_QUIET_CLI" >/dev/null || return $?
	qa_quiet_snapshot stopped-cli || return $?
	docker rm "$WSTM_QUIET_CLI" >/dev/null || return $?
	unset WSTM_QUIET_CLI
}

qa_quiet_install() (
	local password="$1" verify="${2:-}" mounts=() status=0 cleanup=0 args_file
	[[ "$verify" == '' || "$verify" == --verify ]] || return 1
	local exit_status=0
	trap 'exit_status=$?; if [[ -n "${WSTM_QUIET_CLI:-}" ]]; then
		wstm116_arm_retention "$WSTM_PRODUCT_RUN" "${E2E_SOURCE_IDENTITY:0:40}" || {
			echo "HTTP-quiet retention journal failed." >&2
			if [[ "$exit_status" == 0 ]]; then exit_status=1; fi
		}
		echo "HTTP-quiet CLI still present; retention armed, no recovery authorized." >&2
		if [[ "$exit_status" == 0 ]]; then exit_status=1; fi
	fi; exit "$exit_status"' EXIT
	qa_prepare_project || return $?
	WSTM_QUIET_SOURCE_ROOT="$(pwd -P)" || return $?
	export WSTM_QUIET_SOURCE_ROOT
	WSTM_QUIET_DIRECTORY="${WSTM_QA_PRIVATE_OUTPUT}/http-quiet-install"
	[[ ! -e "$WSTM_QUIET_DIRECTORY" && ! -L "$WSTM_QUIET_DIRECTORY" ]] || {
		echo 'Original HTTP-quiet evidence already exists.' >&2; return 1;
	}
	(umask 077; mkdir "$WSTM_QUIET_DIRECTORY") || return $?
	WSTM_QUIET_STARTED=$SECONDS
	WSTM_QUIET_WP="$(compose ps -a -q wordpress)" || return $?
	WSTM_QUIET_DB="$(compose ps -a -q mysql)" || return $?
	[[ "$WSTM_QUIET_WP" =~ ^[a-f0-9]{64}$ && "$WSTM_QUIET_DB" =~ ^[a-f0-9]{64}$ ]] || return 1
	export WSTM_QUIET_WP WSTM_QUIET_DB
	args_file="$WSTM_QUIET_DIRECTORY/original-mounts.private.argv"
	(umask 077; set -o noclobber; qa_quiet_snapshot original > "$args_file") || return $?
	mapfile -d '' -t mounts < "$args_file"
	[[ "${#mounts[@]}" -ge 10 && "${#mounts[@]}" -le 32 ]] || return 1
	compose stop wordpress || return $?
	qa_quiet_snapshot quiet >/dev/null || return $?
	export WORDPRESS_DB_HOST=mysql WORDPRESS_DB_USER=wp WORDPRESS_DB_NAME=wordpress
	export WORDPRESS_DB_PASSWORD="$WSTM_QA_DB_PASSWORD"
	export E2E_OPT_IN=1 WSTM_INITIAL_SITE_URL=http://localhost
	WSTM_QUIET_CLI="$(docker create --pull=never --name "${COMPOSE_PROJECT_NAME}-install-cli" \
		--label "io.webmastery.qa.owner=$WSTM_QA_OWNER" --label "com.docker.compose.project=$COMPOSE_PROJECT_NAME" \
		--user 33:33 --read-only --cap-drop ALL --security-opt no-new-privileges \
		--workdir /var/www/html --no-healthcheck \
		--network "${COMPOSE_PROJECT_NAME}_default" --memory 256m --pids-limit 64 \
		--tmpfs /tmp:rw,noexec,nosuid,nodev,uid=33,gid=33,mode=0700,size=16m \
		-e WORDPRESS_DB_HOST -e WORDPRESS_DB_USER -e WORDPRESS_DB_NAME -e WORDPRESS_DB_PASSWORD \
		-e E2E_OPT_IN -e WSTM_INITIAL_SITE_URL -e WSTM_PRODUCT_RUN -e WSTM_PRODUCT_TOKEN \
		-e WORDPRESS_DEBUG=true -e WORDPRESS_DEBUG_LOG=/var/www/html/wp-content/debug.log \
		-e WSTM_E2E_ARTIFACTS_DIR=/qa-artifacts -e WSTM_PRODUCT_CONTEXT=/qa-artifacts/context.json \
		"${mounts[@]}" --entrypoint sleep sha256:5cbb0456741c335249cb26406ca8bef1e8919dbc8af1481c81fd7041a4c3b405 600)" || return $?
	[[ "$WSTM_QUIET_CLI" =~ ^[a-f0-9]{64}$ ]] || return 1
	export WSTM_QUIET_CLI
	qa_quiet_volume_consumers "$WSTM_QUIET_DIRECTORY/created-cli-consumers" || return $?
	docker start "$WSTM_QUIET_CLI" >/dev/null || return $?
	qa_quiet_assert_cli "$WSTM_QUIET_CLI" || return $?
	if [[ "$verify" == --verify ]]; then
		docker exec --user 33:33 "$WSTM_QUIET_CLI" php "$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" --verify || status=$?
	else
		printf '%s\n' "$password" | docker exec -i --user 33:33 "$WSTM_QUIET_CLI" php \
			"$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" || status=$?
		if [[ "$status" == 0 ]]; then
			qa_quiet_assert_cli "$WSTM_QUIET_CLI" || status=$?
			if [[ "$status" == 0 ]]; then
				docker exec --user 33:33 "$WSTM_QUIET_CLI" php "$CONTAINER_PLUGIN_ROOT/tests/e2e/install-owned-wordpress.php" --verify || status=$?
			fi
		fi
	fi
	if [[ "$status" != 0 ]]; then
		qa_quiet_export_failure || cleanup=$?
	else
		qa_quiet_export_failure "$WSTM_QUIET_DIRECTORY/prepared-originals" 1 || cleanup=$?
	fi
	if [[ "$cleanup" == 0 ]]; then qa_quiet_remove_cli || cleanup=$?; fi
	if [[ "$cleanup" != 0 ]]; then
		wstm116_arm_retention "$WSTM_PRODUCT_RUN" "${E2E_SOURCE_IDENTITY:0:40}" || {
			echo 'HTTP-quiet retention journal failed.' >&2
		}
		echo 'HTTP-quiet installer cleanup unresolved; owned resources retained.' >&2
		if [[ "$status" != 0 ]]; then return "$status"; fi
		return "$cleanup"
	fi
	[[ "$status" == 0 ]] || return "$status"
	qa_quiet_snapshot quiet >/dev/null || return $?
	compose start wordpress || return $?
	qa_quiet_snapshot restored >/dev/null || return $?
)
