#!/usr/bin/env bash

run_untrusted_runtime_selection() (
	set +x
	export -n SHELLOPTS BASHOPTS
	unset BASH_ENV ENV
	if [[ -z "${WSTM108_HOST_AUTHORITY_ROOT:-}" ]]; then
		printf 'WSTM108_QUERY_REFUSED_V1\n' >&2
		exit 78
	fi
	local runtime_key
	for runtime_key in WSTM_QA_RUNTIME_PROFILE WSTM_PHP80_CONFIG WSTM_PHP80_CONFIG_SHA256 WSTM108_HOST_AUTHORITY_ROOT WSTM108_HOST_PHP \
		COMPOSE_PROJECT_NAME E2E_ARTIFACTS_DIR DEPENDENCY_POLICY E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP COMPOSE_FILE COMPOSE_PROFILES COMPOSE_ENV_FILES \
		WORDPRESS_IMAGE MYSQL_IMAGE WORDPRESS_PORT MYSQL_PORT WORDPRESS_URL E2E_MANAGE_COMPOSE \
		PHP80_RUNTIME_IMAGE PHP80_MYSQL_IMAGE PHP80_CANDIDATE_ROOT PHP80_HTTP_PORT PHP80_WP_CONFIG PHP80_WP_CONFIG_SHA256 PHP80_MYSQL_ENV_FILE; do
		if [[ -v "$runtime_key" ]]; then export "${runtime_key?}"; fi
	done
	/usr/bin/python3 -B "$E2E_SCRIPT_ROOT/untrusted-query.py" --runtime-selection "$1"
)

run_untrusted_admission() {
	# No receipt or environment flag can substitute for a fresh native pass.
	local QA_MODE=admission
	run_untrusted_content_qa
}

run_untrusted_content_qa() (
	set +x
	export -n SHELLOPTS BASHOPTS
	unset BASH_ENV ENV
	: "${COMPOSE_PROJECT_NAME:?Untrusted QA requires an explicitly owned disposable project}"
	: "${WSTM108_HOST_AUTHORITY_ROOT:?Untrusted QA requires an explicit independent native host authority root}"
	[[ "$E2E_ARTIFACTS_DIR" =~ ^[a-zA-Z0-9_-]+$ ]] || { echo "Unsafe untrusted artifact directory." >&2; exit 1; }
	wstm116_require_no_retention || exit $?
	local host_root
	host_root="$(cd "$E2E_SCRIPT_ROOT/.." && pwd -P)"
	[[ "$(pwd -P)" == "$host_root" ]] || { echo "Untrusted QA requires its explicit source invocation root." >&2; exit 1; }
	# shellcheck source=scripts/untrusted-host-bootstrap.sh
	source "$E2E_SCRIPT_ROOT/untrusted-host-bootstrap.sh"
	# Bootstrap reserves original helper/controller streams before PHP. It execs
	# the controller; no shell-variable frame, merged stream, or postcommit parser.
	wstm108_host_bootstrap
)
