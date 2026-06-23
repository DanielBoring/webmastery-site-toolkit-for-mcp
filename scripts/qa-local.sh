#!/usr/bin/env bash
set -Eeuo pipefail

RUN_E2E=0
KEEP_COMPOSE=0

usage() {
	cat <<'USAGE'
Usage: scripts/qa-local.sh [--e2e] [--keep-compose]

Runs local preflight QA:
  - composer install --no-interaction
  - composer qa
  - optional Docker E2E via scripts/e2e-test.sh
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--e2e)
			RUN_E2E=1
			;;
		--keep-compose)
			KEEP_COMPOSE=1
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
		echo "Missing required command: $1" >&2
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

run_step composer install --no-interaction
run_step composer qa

if [ "$RUN_E2E" = "1" ]; then
	require_command docker
	require_command bash

	E2E_MANAGE_COMPOSE=1 E2E_KEEP_COMPOSE="$KEEP_COMPOSE" run_step bash scripts/e2e-test.sh
fi

echo "Local QA completed successfully."
