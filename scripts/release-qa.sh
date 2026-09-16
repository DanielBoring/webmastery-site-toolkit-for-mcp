#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"
if [[ ( "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ) && "${SKIP_PLUGIN_CHECK:-0}" != "0" ]]; then
	echo "Plugin Check cannot be bypassed in CI." >&2
	exit 1
fi
ZIP_FILE="${RELEASE_ZIP:-}"
if [[ -z "$ZIP_FILE" ]]; then
	ZIP_FILE="$(bash scripts/build-release.sh "$VERSION")"
fi
php scripts/validate-release-package.php "$ZIP_FILE" "$VERSION"

# Runtime checks consume an extraction of the original ZIP, not the staging tree.
PACKAGE_ROOT="build/release-check"
rm -rf "$PACKAGE_ROOT"
php scripts/release-tools.php extract "$ZIP_FILE" "$PACKAGE_ROOT"
if [[ "${SKIP_PLUGIN_CHECK:-0}" == "1" ]]; then
	echo "LOCAL ONLY: package validation passed; Plugin Check and runtime QA NOT RUN."
	exit 0
fi

PLUGIN_CHECK_VERSION="${PLUGIN_CHECK_VERSION:-$(php scripts/compatibility-baselines.php plugin_check)}"
if [[ "$PLUGIN_CHECK_VERSION" != "latest" && ! "$PLUGIN_CHECK_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid PLUGIN_CHECK_VERSION." >&2
	exit 1
fi
command -v docker >/dev/null || { echo "Docker is required for release runtime QA." >&2; exit 1; }
export E2E_MANAGE_COMPOSE=1
export E2E_KEEP_COMPOSE=1
trap 'docker compose down -v --remove-orphans >/dev/null 2>&1 || true' EXIT
bash scripts/e2e-test.sh all

source scripts/release-plugin-check.sh
check_package "$PLUGIN_CHECK_VERSION"
if [[ "${REQUIRE_CURRENT_PLUGIN_CHECK:-0}" == "1" && "$PLUGIN_CHECK_VERSION" != "latest" ]]; then
	check_package latest
fi
# Detect any unexpected runtime-side change to the archive before handoff.
php scripts/validate-release-package.php "$ZIP_FILE" "$VERSION"
