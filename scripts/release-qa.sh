#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"
if [[ ( "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ) && "${SKIP_PLUGIN_CHECK:-0}" != "0" ]]; then
	echo "Plugin Check cannot be bypassed in CI." >&2
	exit 1
fi
# shellcheck source=scripts/destructive-retention.sh
source scripts/destructive-retention.sh
if [[ "${SKIP_PLUGIN_CHECK:-0}" != "1" ]]; then
	: "${COMPOSE_PROJECT_NAME:?Package runtime requires an explicitly owned disposable Compose project}"
	[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "Invalid disposable Compose project name." >&2; exit 1; }
	wstm116_require_no_retention
else
	# Offline validation must not overwrite another invocation's retained package.
	wstm116_require_no_retention
fi
ZIP_FILE="${RELEASE_ZIP:-}"
if [[ -z "$ZIP_FILE" ]]; then
	ZIP_FILE="$(bash scripts/build-release.sh "$VERSION")"
fi
php scripts/validate-release-package.php "$ZIP_FILE" "$VERSION"
ZIP_IDENTITY="$(sha256sum -- "$ZIP_FILE")"
echo "Original release archive: $ZIP_IDENTITY"

# Keep the checker extraction pristine: Docker may create nested mount placeholders.
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
RUNTIME_ROOT="build/release-runtime"
rm -rf "$RUNTIME_ROOT"
php scripts/release-tools.php extract "$ZIP_FILE" "$RUNTIME_ROOT"
export E2E_PACKAGE_ROOT="./${RUNTIME_ROOT}/${PLUGIN_SLUG}"
export E2E_PACKAGE_ZIP="$ZIP_FILE"
export E2E_MANAGE_COMPOSE=1
export E2E_KEEP_COMPOSE=1
# shellcheck source=scripts/qa-compose.sh
source scripts/qa-compose.sh
cleanup_release() {
	local status=$? cleanup=0
	if ! wstm116_require_no_retention; then
		if [[ "$status" != 0 ]]; then exit "$status"; fi
		exit 1
	fi
	compose down -v --remove-orphans || cleanup=$?
	printf 'Package cleanup: original_status=%s cleanup_status=%s\n' "$status" "$cleanup"
	if [[ "$status" != 0 ]]; then exit "$status"; fi
	exit "$cleanup"
}
trap cleanup_release EXIT
bash scripts/e2e-test.sh all

source scripts/release-plugin-check.sh
check_package "$PLUGIN_CHECK_VERSION"
if [[ "${REQUIRE_CURRENT_PLUGIN_CHECK:-0}" == "1" && "$PLUGIN_CHECK_VERSION" != "latest" ]]; then
	check_package latest
fi
# Detect any unexpected runtime-side change to the archive before handoff.
php scripts/validate-release-package.php "$ZIP_FILE" "$VERSION"
php scripts/release-tools.php runtime-package "${PACKAGE_ROOT}/${PLUGIN_SLUG}" "$ZIP_FILE"
php scripts/release-tools.php runtime-package-after "$E2E_PACKAGE_ROOT" "$ZIP_FILE"
if [[ "$(sha256sum -- "$ZIP_FILE")" != "$ZIP_IDENTITY" ]]; then
	echo "Original release ZIP identity changed during QA." >&2
	exit 1
fi
echo "Unchanged release archive: $ZIP_IDENTITY"
