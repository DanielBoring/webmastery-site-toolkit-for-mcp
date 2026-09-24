#!/usr/bin/env bash

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
