#!/usr/bin/env bash
set +x
set -Eeuo pipefail

RUN_E2E=0
RUN_CONTRACT=0
RUN_RELEASE=0
RUN_PACKAGE=0
RUN_PUBLISHING=0
KEEP_COMPOSE=0
PREFLIGHT_ONLY=0

usage() {
	cat <<'USAGE'
Usage: scripts/qa-local.sh [--contract] [--e2e] [--package] [--publishing] [--release] [--engine docker|podman] [--engine-host SOCKET] [--keep-compose] [--preflight-only]

Runs local preflight QA:
  - composer install --no-interaction --prefer-dist --no-progress
  - composer qa (static + unit)
  - optional Docker Ability Contract QA
  - optional Docker Full MCP E2E QA
  - optional Release Package QA, including Docker contract/transport checks and Plugin Check

Options:
  --contract        run managed Docker Ability Contract QA
  --e2e             run managed Docker Full MCP E2E QA
  --release         run Release Package QA
  --package         run packaged-plugin QA without directory checks
  --publishing      run directory checks without claiming product acceptance
  --engine          docker (default) or podman through its local API socket
  --engine-host     explicit local Unix socket or Windows named pipe
  --preflight-only  check required commands and exit without running QA
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--contract)
			RUN_CONTRACT=1
			;;
		--e2e)
			RUN_E2E=1
			;;
		--release)
			RUN_RELEASE=1
			;;
		--package) RUN_PACKAGE=1 ;;
		--publishing) RUN_PUBLISHING=1 ;;
		--engine|--engine-host)
			[[ "$#" -ge 2 && -n "$2" ]] || { echo "$1 requires a value." >&2; exit 78; }
			if [[ "$1" == --engine ]]; then export WSTM_QA_ENGINE="$2"; else export WSTM_QA_ENGINE_HOST="$2"; fi
			shift
			;;
		--keep-compose)
			KEEP_COMPOSE=1
			;;
		--preflight-only)
			PREFLIGHT_ONLY=1
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown argument: $1" >&2
			usage >&2
			exit 1
			;;
	esac
	shift
done

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		case "$1" in
			php)
				if command -v php.exe >/dev/null 2>&1; then
					hint="Found Windows php.exe, but this Bash wrapper expects a Unix php command. Install PHP inside this Bash/WSL environment, or use scripts/qa-local.ps1 from PowerShell."
				else
					hint="Install PHP 8.0+ and restart the terminal so php is on PATH."
				fi
				;;
			composer)
				if command -v composer.bat >/dev/null 2>&1; then
					hint="Found Windows composer.bat, but this Bash wrapper expects a Unix composer command. Install Composer inside this Bash/WSL environment, or use scripts/qa-local.ps1 from PowerShell."
				else
					hint="Install Composer and restart the terminal so composer is on PATH. See CONTRIBUTING.md > Local QA commands for setup notes."
				fi
				;;
			docker)
				hint="Install Docker Desktop or Docker Engine before running E2E."
				;;
			bash)
				hint="Install Bash before running E2E."
				;;
			*)
				hint="Install it and restart the terminal so it is available on PATH."
				;;
		esac

		echo "Missing required command: $1. $hint" >&2
		exit 1
	fi
}

run_step() {
	echo "==> $*"
	"$@"
}

require_command php
require_command composer
require_command git
export WSTM_QA_LOCATION=local

if [[ "$RUN_CONTRACT$RUN_E2E$RUN_RELEASE$RUN_PACKAGE$RUN_PUBLISHING" != 00000 ]]; then
	require_command docker
	require_command bash
	run_step bash scripts/qa-run.sh preflight
fi

if [ "$PREFLIGHT_ONLY" = "1" ]; then
	echo "Local QA prerequisites are available."
	exit 0
fi

if [[ ! -f vendor/autoload.php ]]; then
	run_step composer install --no-interaction --prefer-dist --no-progress
fi
COMPOSER_PROCESS_TIMEOUT=0 run_step composer qa

if [[ "$RUN_CONTRACT$RUN_E2E$RUN_RELEASE$RUN_PACKAGE$RUN_PUBLISHING" != 00000 ]]; then
	export MSYS_NO_PATHCONV="${MSYS_NO_PATHCONV:-1}"
	project_token="$(php -r 'echo bin2hex(random_bytes(8));')"
	[[ "$project_token" =~ ^[a-f0-9]{16}$ ]] || exit 1
	local_project="wstm-local-${project_token}"
fi
if [[ "$RUN_CONTRACT" == 1 || "$RUN_E2E" == 1 ]]; then
	mode=contract
	if [[ "$RUN_E2E" == 1 ]]; then mode=e2e; fi
	if [[ "$RUN_CONTRACT" == 1 && "$RUN_E2E" == 1 ]]; then mode=all; fi
	unset WSTM_QA_OWNER
	export COMPOSE_PROJECT_NAME="${local_project}-source"
	export E2E_ARTIFACTS_DIR="e2e-artifacts-${COMPOSE_PROJECT_NAME}"
	export E2E_MANAGE_COMPOSE=1 E2E_KEEP_COMPOSE="$KEEP_COMPOSE"
	run_step bash scripts/qa-run.sh "$mode"
fi

if [ "$RUN_RELEASE" = "1" ]; then
	unset WSTM_QA_OWNER
	export COMPOSE_PROJECT_NAME="${local_project}-release"
	export E2E_ARTIFACTS_DIR="e2e-artifacts-${COMPOSE_PROJECT_NAME}"
	run_step bash scripts/qa-run.sh release
fi
for selection in package publishing; do
	if [[ ( "$selection" == package && "$RUN_PACKAGE" == 1 ) ||
		( "$selection" == publishing && "$RUN_PUBLISHING" == 1 ) ]]; then
		unset WSTM_QA_OWNER
		export COMPOSE_PROJECT_NAME="${local_project}-${selection}"
		export E2E_ARTIFACTS_DIR="e2e-artifacts-${COMPOSE_PROJECT_NAME}"
		run_step bash scripts/qa-run.sh "$selection"
	fi
done

echo "Local QA completed successfully."
