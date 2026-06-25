#!/usr/bin/env bash
set -Eeuo pipefail

RUN_E2E=0
RUN_CONTRACT=0
RUN_RELEASE=0
KEEP_COMPOSE=0
PREFLIGHT_ONLY=0

usage() {
	cat <<'USAGE'
Usage: scripts/qa-local.sh [--contract] [--e2e] [--release] [--keep-compose] [--preflight-only]

Runs local preflight QA:
  - composer install --no-interaction --prefer-dist --no-progress
  - composer qa (static + unit)
  - optional Docker Ability Contract QA
  - optional Docker Full MCP E2E QA
  - optional Release Package QA

Options:
  --contract        run managed Docker Ability Contract QA
  --e2e             run managed Docker Full MCP E2E QA
  --release         run Release Package QA
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

if [ "$RUN_CONTRACT" = "1" ] || [ "$RUN_E2E" = "1" ] || [ "$RUN_RELEASE" = "1" ]; then
	require_command docker
	require_command bash
fi

if [ "$PREFLIGHT_ONLY" = "1" ]; then
	echo "Local QA prerequisites are available."
	exit 0
fi

run_step composer install --no-interaction --prefer-dist --no-progress
run_step composer qa

if [ "$RUN_CONTRACT" = "1" ]; then
	E2E_MANAGE_COMPOSE=1 E2E_KEEP_COMPOSE="$KEEP_COMPOSE" run_step bash scripts/e2e-test.sh contract
fi

if [ "$RUN_E2E" = "1" ]; then
	E2E_MANAGE_COMPOSE=1 E2E_KEEP_COMPOSE="$KEEP_COMPOSE" run_step bash scripts/e2e-test.sh e2e
fi

if [ "$RUN_RELEASE" = "1" ]; then
	run_step bash scripts/release-qa.sh
fi

echo "Local QA completed successfully."
