#!/usr/bin/env bash

# Finite trust base: reservation failures before descriptors exist have no
# transcript guarantee. They never launch PHP, Docker, or runtime mutations.
wstm108_host_bootstrap() (
	exec 8>&2
	exec 2>/dev/null 1>/dev/null
	trap 'status=$?; if (( status != 0 )); then printf "%s\n" "WSTM108 bootstrap refused before controller admission." >&8; fi; exit "$status"' EXIT
	set -Eeuo pipefail
	set +x
	unset BASH_ENV ENV
	umask 077
	[[ "$(uname -s)" == Linux ]] || exit 1
	local root="${WSTM108_HOST_AUTHORITY_ROOT:-}" project="${COMPOSE_PROJECT_NAME:-}"
	local root_before root_after child_identity owner directory intent php_binary controller
	[[ -n "$root" && "$root" == "$(readlink -e -- "$root")" && "$root" != *$'\n'* && "$root" != *$'\r'* ]] || exit 1
	[[ "$project" =~ ^[a-z0-9][a-z0-9_-]{0,127}$ ]] || exit 1
	[[ -d "$root" && ! -L "$root" && "$(stat -c %u -- "$root")" == "$(id -u)" ]] || exit 1
	(( ( 8#$(stat -c %a -- "$root") & 0022 ) == 0 )) || exit 1
	root_before="$(stat -c '%d:%i:%u:%g:%f:%h' -- "$root")" || exit $?
	owner="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')" || exit $?
	[[ "$owner" =~ ^[a-f0-9]{32}$ ]] || exit 1
	directory="$root/wstm108-bootstrap-$owner"
	intent="$root/wstm108-bootstrap-intent-$owner.private"
	set -o noclobber
	exec 5>"$intent" || exit $?
	set +o noclobber
	printf 'version=1\nroot=%s\nchild=%s\n' "$root_before" "$directory" >&5 || exit $?
	sync "/proc/self/fd/5" || exit $?
	[[ "$(stat -c '%d:%i:%u:%g:%f:%h' -- "$root")" == "$root_before" ]] || exit 1
	mkdir -m 700 -- "$directory" || exit $?
	child_identity="$(stat -c '%d:%i:%u:%g:%f:%h' -- "$directory")" || exit $?
	root_after="$(stat -c '%d:%i:%u:%g:%f:%h' -- "$root")" || exit $?
	set -o noclobber
	exec 3>"$directory/controller.stdout.private" || exit $?
	exec 4>"$directory/controller.stderr.private" || exit $?
	set +o noclobber
	# From this point all bootstrap/controller diagnostics stay in original,
	# independently opened private streams, including PHP startup failures.
	exec 1>&3 2>&4 || exit $?
	trap - EXIT
	exec 8>&-
	[[ -n "${GITHUB_OUTPUT:-}" && -f "$GITHUB_OUTPUT" && ! -L "$GITHUB_OUTPUT" ]] || exit 1
	exec 9>>"$GITHUB_OUTPUT" || exit $?
	if [[ "${WSTM108_HOST_PHP+x}" == x ]]; then
		[[ -n "$WSTM108_HOST_PHP" && "$WSTM108_HOST_PHP" == /* ]] || exit 1
		php_binary="$(readlink -e -- "$WSTM108_HOST_PHP")" || exit $?
		[[ "$php_binary" == "$WSTM108_HOST_PHP" ]] || exit 1
	else
		php_binary="$(readlink -e -- "$(command -v php)")" || exit $?
	fi
	[[ -f "$php_binary" && -x "$php_binary" && "${php_binary##*/}" =~ ^php([0-9]+(\.[0-9]+)?)?$ && "$(stat -c %u -- "$php_binary")" == 0 ]] || exit 1
	(( ( 8#$(stat -c %a -- "$php_binary") & 0022 ) == 0 )) || exit 1
	controller="$E2E_SCRIPT_ROOT/untrusted-host-controller.php"
	[[ "$controller" == "$(readlink -e -- "$controller")" ]] || exit 1
	exec env -i \
		PATH=/usr/bin:/bin HOME="${HOME:?}" \
		DOCKER_CONTEXT="${DOCKER_CONTEXT:-}" DOCKER_HOST="${DOCKER_HOST:-}" DOCKER_CONFIG="${DOCKER_CONFIG:-}" \
		COMPOSE_PROJECT_NAME="$project" \
		WSTM108_HOST_AUTHORITY_ROOT="$root" \
		WSTM108_HOST_PHP="$php_binary" \
		WSTM108_RUN_ID="${GITHUB_RUN_ID:?}" WSTM108_RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:?}" WSTM108_JOB="${GITHUB_JOB:?}" \
		E2E_PACKAGE_ZIP="${E2E_PACKAGE_ZIP:-}" E2E_PACKAGE_ROOT="${E2E_PACKAGE_ROOT:-}" \
		DEPENDENCY_POLICY="${DEPENDENCY_POLICY:-pinned}" \
		WORDPRESS_IMAGE="${WORDPRESS_IMAGE:-}" MYSQL_IMAGE="${MYSQL_IMAGE:-}" \
		WORDPRESS_PORT="${WORDPRESS_PORT:-}" MYSQL_PORT="${MYSQL_PORT:-}" \
		"$php_binary" "$controller" "$directory" "$root_before" "$root_after" "$child_identity" \
		"$owner" "${QA_MODE:?}" "${E2E_ARTIFACTS_DIR:?}" "$GITHUB_OUTPUT" < /dev/null
)
