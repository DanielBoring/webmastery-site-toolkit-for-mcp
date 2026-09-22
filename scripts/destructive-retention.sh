#!/usr/bin/env bash

wstm116_retention_directory_safe() {
	if [[ -L build || ( -e build && ( ! -d build || ! -r build || ! -x build ) ) ]]; then
		echo "Cannot safely inspect retention state; refusing destructive teardown." >&2
		return 1
	fi
}

wstm116_retention_path() {
	[[ "${COMPOSE_PROJECT_NAME:-}" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "Retention requires an explicit safe project identity." >&2; return 1; }
	wstm116_retention_directory_safe || return $?
	printf 'build/wstm116-retention-%s\n' "$COMPOSE_PROJECT_NAME"
}

wstm116_require_no_retention() {
	local candidate
	wstm116_retention_directory_safe || return $?
	# Different project names still share this checkout's artifacts and package mounts.
	for candidate in build/wstm116-retention-*; do
		if [[ -e "$candidate" || -L "$candidate" ]]; then
			echo "RECOVERY REQUIRED: retaining project=${COMPOSE_PROJECT_NAME:-unassigned}; guard=$candidate. No destructive teardown or restart is authorized." >&2
			return 1
		fi
	done
}

wstm116_arm_retention() {
	local owner="$1" source="$2" marker
	[[ "$owner" =~ ^[a-f0-9]{32}$ && "$source" =~ ^[a-f0-9]{40}$ ]] || return 1
	wstm116_require_no_retention || return $?
	marker="$(wstm116_retention_path)" || return $?
	mkdir -p build || return $?
	( umask 077; set -o noclobber; printf 'owner=%s\nproject=%s\nsource=%s\n' "$owner" "$COMPOSE_PROJECT_NAME" "$source" > "$marker" ) || return $?
	echo "Retention armed: owner=$owner project=$COMPOSE_PROJECT_NAME source=$source guard=$marker"
}

wstm116_clear_retention() {
	local owner="$1" source="$2" marker
	marker="$(wstm116_retention_path)" || return $?
	[[ -f "$marker" && ! -L "$marker" ]] || { echo "Retention identity unavailable; refusing retirement." >&2; return 1; }
	cmp -s "$marker" <(printf 'owner=%s\nproject=%s\nsource=%s\n' "$owner" "$COMPOSE_PROJECT_NAME" "$source") ||
		{ echo "Retention ownership changed; refusing retirement." >&2; return 1; }
	rm -- "$marker" || return $?
	echo "Retention retired after verified runtime restoration and runner cleanup: project=$COMPOSE_PROJECT_NAME"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
	set -Eeuo pipefail
	wstm116_retention_path >/dev/null
	wstm116_require_no_retention
	case "${1:-}" in
		check) ;;
		cleanup)
			# shellcheck source=scripts/qa-compose.sh
			source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"
			compose down -v --remove-orphans
			;;
		*) echo "Usage: destructive-retention.sh check|cleanup" >&2; exit 1 ;;
	esac
fi
