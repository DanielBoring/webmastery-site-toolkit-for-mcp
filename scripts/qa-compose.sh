#!/usr/bin/env bash

compose() {
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
