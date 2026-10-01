#!/usr/bin/env bash

compose() {
	if [[ -n "${WSTM_QA_RUNTIME_PROFILE:-}${WSTM_PHP80_CONFIG+x}${WSTM_PHP80_CONFIG_SHA256+x}" ]]; then
		local selection argument command="${1:-}" option_value=0
		local WSTM_QA_COMPOSE_ARGV=()
		local arguments=( "$@" )
		if [[ -z "$command" || "$command" == -* ]]; then
			echo "Floor Compose requires a subcommand, not caller-selected global options." >&2
			return 1
		fi
		for argument in "${arguments[@]:1}"; do
			if [[ "$option_value" == 1 ]]; then option_value=0; continue; fi
			case "$argument" in
				-f*|-p*|--file*|--project-name*|--project-directory*|--env-file*|--profile*)
					echo "Floor Compose selector cannot be overridden by call arguments." >&2
					return 1 ;;
			esac
			if [[ "$argument" == -[^-]* && "$argument" == *[fp]* ]]; then
				echo "Floor Compose rejects combined caller-selected file/project flags." >&2
				return 1
			fi
			if [[ "$command" == exec ]]; then
				case "$argument" in
					-e|--env|-u|--user|-w|--workdir|--index) option_value=1 ;;
					-T|-d|-i|-t|--no-TTY|--detach|--interactive|--privileged|--env=*|--user=*|--workdir=*|--index=*) ;;
					-*) echo "Unrecognized floor exec option; refusing ambiguous selection." >&2; return 1 ;;
					*) break ;; # Service begins exec's untouched child argv.
				esac
			fi
		done
		selection="$("${WSTM108_HOST_PHP-php}" "$(dirname "${BASH_SOURCE[0]}")/qa-runtime.php" shell)" || return $?
		# Only the closed, pinned source helper emits shell-quoted exports/argv.
		eval "$selection"
		"${WSTM_QA_COMPOSE_ARGV[@]}" "$@"
		return $?
	fi
	local project_args=()
	local file_args=()
	if [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then
		project_args=( --project-name "$COMPOSE_PROJECT_NAME" )
	fi
	if [ -n "${E2E_PACKAGE_ROOT:-}${E2E_PACKAGE_ZIP:-}" ]; then
		: "${E2E_PACKAGE_ROOT:?Package runtime requires E2E_PACKAGE_ROOT}"
		: "${E2E_PACKAGE_ZIP:?Package runtime requires E2E_PACKAGE_ZIP}"
		file_args=( -f docker-compose.yml -f docker-compose.release.yml )
	fi

	docker compose "${project_args[@]}" "${file_args[@]}" "$@"
}

host_php() (
	# Native Windows PHP needs Git Bash host-path conversion; container commands do not.
	unset MSYS_NO_PATHCONV
	php "$@"
)
