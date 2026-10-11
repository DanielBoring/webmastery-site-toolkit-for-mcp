#!/usr/bin/env bash

host_php() (
	# Native Windows PHP needs host-path conversion; container paths do not.
	unset MSYS_NO_PATHCONV
	php "$@"
)

qa_wp() {
	compose exec -T -e WP_CLI_CACHE_DIR=/qa-artifacts/parent-cache/wp-cli \
		wordpress php /var/www/html/.qa-bin/wp --url=http://localhost "$@"
}

qa_prepare_project() {
	local root state_base state_root source_key owner_file database_file existing new_project=0 database_credentials=()
	: "${COMPOSE_PROJECT_NAME:?QA requires an explicitly owned disposable Compose project}"
	[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]] || {
		echo "Invalid disposable Compose project name." >&2; return 1;
	}
	root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)" || return $?
	[[ "$(pwd -P)" == "$root" ]] || {
		echo "Run QA from the candidate repository root." >&2; return 1;
	}
	state_base="${WSTM_QA_STATE_ROOT:-${XDG_CACHE_HOME:-$HOME/.cache}/webmastery-mcp-qa}"
	case "$state_base" in
		/*|[a-zA-Z]:/*|[a-zA-Z]:\\*) ;;
		*) echo "QA state requires an absolute private host directory." >&2; return 1 ;;
	esac
	[[ ! -L "$state_base" ]] || {
		echo "QA state directory must not be a symlink." >&2; return 1;
	}
	(umask 077; mkdir -p "$state_base") || return $?
	state_base="$(cd "$state_base" && pwd -P)" || return $?
	[[ "$state_base" != "$root" && "$state_base" != "$root/"* ]] || {
		echo "Private QA state must remain outside the mounted candidate." >&2; return 1;
	}
	source_key="$(printf '%s' "$root" | host_php -r 'echo substr(hash("sha256", stream_get_contents(STDIN)), 0, 16);')" || return $?
	[[ "$source_key" =~ ^[a-f0-9]{16}$ ]] || return 1
	state_root="$state_base/$source_key"
	[[ ! -L "$state_root" ]] || {
		echo "QA source-state directory must not be a symlink." >&2; return 1;
	}
	(umask 077; mkdir -p "$state_root") || return $?
	owner_file="$state_root/${COMPOSE_PROJECT_NAME}.owner"
	database_file="$state_root/${COMPOSE_PROJECT_NAME}.database"
	if [[ -e "$owner_file" || -L "$owner_file" ]]; then
		[[ -f "$owner_file" && ! -L "$owner_file" && "$(wc -c < "$owner_file")" == 65 ]] || {
			echo "Invalid QA ownership record; refusing resource access." >&2; return 1;
		}
		existing="$(cat "$owner_file")" || return $?
		[[ "$existing" =~ ^[a-f0-9]{64}$ && "${WSTM_QA_OWNER:-$existing}" == "$existing" ]] || {
			echo "QA owner does not match the project record." >&2; return 1;
		}
		WSTM_QA_OWNER="$existing"
	else
		[[ -z "${WSTM_QA_OWNER:-}" ]] || {
			echo "Caller-supplied ownership without a project record is not accepted." >&2; return 1;
		}
		WSTM_QA_OWNER="$(host_php -r 'echo bin2hex(random_bytes(32));')" || return $?
		[[ "$WSTM_QA_OWNER" =~ ^[a-f0-9]{64}$ ]] || return 1
		(umask 077; set -o noclobber; printf '%s\n' "$WSTM_QA_OWNER" > "$owner_file") || return $?
		new_project=1
	fi
	if [[ "$new_project" == 1 ]]; then
		(umask 077; set -o noclobber; host_php -r 'echo bin2hex(random_bytes(32)), "\n", bin2hex(random_bytes(32)), "\n";' > "$database_file") || return $?
	fi
	[[ -f "$database_file" && ! -L "$database_file" && "$(wc -c < "$database_file")" == 130 ]] || {
		echo "Missing or invalid owned database credentials; refusing QA startup." >&2; return 1;
	}
	mapfile -t database_credentials < "$database_file"
	[[ "${#database_credentials[@]}" == 2 && "${database_credentials[0]}" =~ ^[a-f0-9]{64}$ && "${database_credentials[1]}" =~ ^[a-f0-9]{64}$ ]] || {
		echo "Malformed owned database credentials; refusing QA startup." >&2; return 1;
	}
	WSTM_QA_DB_PASSWORD="${database_credentials[0]}"
	WSTM_QA_DB_ROOT_PASSWORD="${database_credentials[1]}"
	WSTM_PRODUCT_RUN="${WSTM_QA_OWNER:0:32}"
	WSTM_PRODUCT_TOKEN="$WSTM_QA_OWNER"
	WSTM_QA_PRIVATE_OUTPUT="$state_root/${COMPOSE_PROJECT_NAME}.private"
	[[ "$WSTM_QA_PRIVATE_OUTPUT" != "$root" && "$WSTM_QA_PRIVATE_OUTPUT" != "$root/"* &&
		! -L "$WSTM_QA_PRIVATE_OUTPUT" && ( ! -e "$WSTM_QA_PRIVATE_OUTPUT" || -d "$WSTM_QA_PRIVATE_OUTPUT" ) ]] || {
		echo "Private QA output must be an unlinked directory outside the candidate." >&2; return 1;
	}
	(umask 077; mkdir -p "$WSTM_QA_PRIVATE_OUTPUT") || return $?
	export WSTM_QA_OWNER
	export WSTM_QA_DB_PASSWORD WSTM_QA_DB_ROOT_PASSWORD
	export WSTM_PRODUCT_RUN WSTM_PRODUCT_TOKEN WSTM_QA_PRIVATE_OUTPUT
}

qa_verify_resources() {
	local kind identifiers identifier observed count=0 selected
	docker info --format '{{.ServerVersion}}' >/dev/null || return $?
	for kind in container network volume; do
		case "$kind" in
			container)
				identifiers="$(docker container ls -aq --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME}")" || return $?
				selected="$(docker container ls -aq --filter "name=^/${COMPOSE_PROJECT_NAME}[-_](wordpress|mysql)[-_]1$")" || return $?
				;;
			network)
				identifiers="$(docker network ls -q --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME}")" || return $?
				selected="$(docker network ls -q --filter "name=^${COMPOSE_PROJECT_NAME}_default$")" || return $?
				;;
			volume)
				identifiers="$(docker volume ls -q --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME}")" || return $?
				selected="$(docker volume ls -q --filter "name=^${COMPOSE_PROJECT_NAME}_(mysql_data|wp_data|qa_artifacts)$")" || return $?
				;;
		esac
		identifiers="$(printf '%s\n%s\n' "$identifiers" "$selected" | sort -u)" || return $?
		while IFS= read -r identifier; do
			[[ -n "$identifier" ]] || continue
			count=$((count + 1))
			if [[ "$count" -gt 64 || ! "$identifier" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]]; then
				echo "Unexpected QA resource inventory; refusing lifecycle changes." >&2
				return 1
			fi
			if [[ "$kind" == container ]]; then
				observed="$(docker container inspect --format '{{ index .Config.Labels "io.webmastery.qa.owner" }}' "$identifier")" || return $?
			else
				observed="$(docker "$kind" inspect --format '{{ index .Labels "io.webmastery.qa.owner" }}' "$identifier")" || return $?
			fi
			[[ "$observed" == "$WSTM_QA_OWNER" ]] || {
				echo "Foreign or unowned Compose resource; refusing lifecycle changes." >&2
				return 1
			}
		done <<< "$identifiers"
	done
	WSTM_QA_CHECKED_PROJECT="$COMPOSE_PROJECT_NAME"
	WSTM_QA_CHECKED_OWNER="$WSTM_QA_OWNER"
}

qa_compose_command() {
	local files=( -f docker-compose.yml )
	if [[ -n "${E2E_PACKAGE_ROOT:-}${E2E_PACKAGE_ZIP:-}" ]]; then
		: "${E2E_PACKAGE_ROOT:?Package runtime requires E2E_PACKAGE_ROOT}"
		: "${E2E_PACKAGE_ZIP:?Package runtime requires E2E_PACKAGE_ZIP}"
		files+=( -f docker-compose.release.yml )
	fi
	docker compose --project-name "$COMPOSE_PROJECT_NAME" "${files[@]}" "$@"
}

qa_initialize_artifacts() {
	qa_prepare_project || return $?
	qa_verify_resources || return $?
	# Trusted image initialization only; no candidate PHP or plugin runs as root.
	qa_compose_command exec -T --user 0:0 wordpress \
		install -d -m 0700 -o 33 -g 33 /qa-artifacts
}

qa_export_artifact() {
	local source="$1" destination="$2" status=0 cli="${3:-}"
	[[ "$source" =~ ^/qa-artifacts/[a-zA-Z0-9_./-]+$ && "$source" != *..* &&
		( "$destination" == "${E2E_ARTIFACTS_DIR:?Owned output directory required}/"* ||
			( -n "${WSTM_QA_PRIVATE_OUTPUT:-}" && "$destination" == "$WSTM_QA_PRIVATE_OUTPUT/"* ) ) &&
		"$destination" != *..* && ! -e "$destination" && ! -L "$destination" ]] || {
		echo "Invalid or already reserved artifact export path." >&2; return 1;
	}
	(
		umask 077
		set -o noclobber
		# shellcheck disable=SC2016
		qa_artifact_read "$cli" php -r '
		$path = $argv[1];
		if (realpath($path) !== $path || is_link($path)) {
			throw new RuntimeException("Artifact path is not an owned regular path.");
		}

		$before = lstat($path);
		if ($before === false || ($before["mode"] & 0170000) !== 0100000 || $before["uid"] !== 33 ||
			$before["size"] > 16777216 || $before["size"] < 1) {
			throw new RuntimeException("Artifact identity or byte bound failed.");
		}
		$handle = fopen($path, "rb");
		if ($handle === false) { throw new RuntimeException("Artifact open failed."); }
		$opened = fstat($handle);
		if ($opened === false || $opened["dev"] !== $before["dev"] || $opened["ino"] !== $before["ino"] ||
			$opened["size"] !== $before["size"]) {
			throw new RuntimeException("Artifact changed while opening.");
		}
		$bytes = stream_get_contents($handle, 16777217);
		$after = fstat($handle);
		if ($bytes === false || strlen($bytes) !== $before["size"] || $after === false ||
			$after["size"] !== $before["size"] || $after["mtime"] !== $before["mtime"] || !fclose($handle)) {
			throw new RuntimeException("Artifact capture was incomplete or changed.");
		}
		$offset = 0;
		while ($offset < strlen($bytes)) {
			$written = fwrite(STDOUT, substr($bytes, $offset));
			if ($written === false || $written === 0) { throw new RuntimeException("Artifact export write failed."); }
			$offset += $written;
		}
		if (!fflush(STDOUT)) { throw new RuntimeException("Artifact export flush failed."); }
		' "$source" > "$destination"
	) || status=$?
	if [[ "$status" != 0 ]]; then
		echo "Artifact export failed; partial private output retained." >&2
	fi
	return "$status"
}

qa_artifact_read() {
	local cli="$1"
	shift
	if [[ -z "$cli" ]]; then
		compose exec -T wordpress "$@"
	else
		qa_quiet_assert_cli "$cli" || return $?
		docker exec --user 33:33 "$cli" "$@"
	fi
}

compose() {
	local command="${1:-}" argument consume=0 service_seen=0
	qa_prepare_project || return $?
	[[ -n "$command" && "$command" != -* ]] || {
		echo "Compose requires a subcommand, not caller-selected global options." >&2; return 1;
	}
	case "$command" in
		up|down|start|stop|restart|rm|kill|create|exec|port|ps|cp|logs|config|version|images|pull) ;;
		*) echo "Unsupported QA Compose subcommand." >&2; return 1 ;;
	esac
	shift
	case "$command" in
		config|version|pull) ;;
		up|down|start|stop|restart|rm|kill|create)
			qa_verify_resources || return $? ;;
		*)
			if [[ "${WSTM_QA_CHECKED_PROJECT:-}" != "$COMPOSE_PROJECT_NAME" || "${WSTM_QA_CHECKED_OWNER:-}" != "$WSTM_QA_OWNER" ]]; then
				qa_verify_resources || return $?
			fi ;;
	esac
	if [[ "$command" == exec ]]; then
		for argument in "$@"; do
			if [[ "$consume" == 1 ]]; then consume=0; continue; fi
			case "$argument" in
				-e|--env|-w|--workdir|--index) consume=1 ;;
				-T|-i|-t|--no-TTY|--interactive|--env=*|--workdir=*|--index=*) ;;
				-u|--user|--user=*|-u*|--privileged|-d|--detach)
					echo "Candidate execution must remain attached and use UID/GID 33." >&2; return 1 ;;
				-*) echo "Unknown candidate execution option." >&2; return 1 ;;
				wordpress|mysql) service_seen=1; break ;;
				*) echo "Unknown QA service." >&2; return 1 ;;
			esac
		done
		[[ "$consume" == 0 && "$service_seen" == 1 ]] || {
			echo "Candidate execution requires complete options and an owned service." >&2; return 1;
		}
		qa_compose_command exec --user 33:33 "$@"
	else
		for argument in "$@"; do
			case "$argument" in
				-f*|-p*|--file*|--project-name*|--project-directory*|--env-file*|--profile*)
					echo "Compose project and files cannot be overridden." >&2; return 1 ;;
			esac
		done
		local result=0
		qa_compose_command "$command" "$@" || result=$?
		case "$command" in
			up|down|start|stop|restart|rm|kill|create)
				unset WSTM_QA_CHECKED_PROJECT WSTM_QA_CHECKED_OWNER ;;
		esac
		return "$result"
	fi
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
	set +x
	set -Eeuo pipefail
	export MSYS_NO_PATHCONV="${MSYS_NO_PATHCONV:-1}"
	compose "$@"
fi
