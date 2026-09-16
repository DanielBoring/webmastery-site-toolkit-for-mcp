#!/usr/bin/env bash
set -Eeuo pipefail

PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"
VERSION="${1:-$(php scripts/release-tools.php version)}"
php scripts/validate-release-package.php "" "$VERSION" >&2

PACKAGE_DIR="build/${PLUGIN_SLUG}"
ZIP_FILE="${PLUGIN_SLUG}-${VERSION}.zip"
rm -rf "$PACKAGE_DIR"
mkdir -p "$PACKAGE_DIR"
cp "${PLUGIN_SLUG}.php" readme.txt LICENSE "$PACKAGE_DIR/"
cp -R includes "$PACKAGE_DIR/"
# One archiver everywhere; do not let platform-dependent fallbacks change the package.
php scripts/create-release-zip.php "$PACKAGE_DIR" "$ZIP_FILE"
php scripts/validate-release-package.php "$ZIP_FILE" "$VERSION" >&2
echo "$ZIP_FILE"
