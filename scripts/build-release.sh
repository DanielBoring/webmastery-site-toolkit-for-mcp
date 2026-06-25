#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
PLUGIN_SLUG="webmastery-site-toolkit-for-mcp"

if [ -z "$VERSION" ]; then
	VERSION="$(grep -E '^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "${PLUGIN_SLUG}.php" | head -1 | sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')"
fi

if [ -z "$VERSION" ]; then
	echo "Could not determine plugin version." >&2
	exit 1
fi

PACKAGE_DIR="build/${PLUGIN_SLUG}"
ZIP_FILE="${PLUGIN_SLUG}-${VERSION}.zip"

rm -rf "$PACKAGE_DIR" "$ZIP_FILE"
mkdir -p "$PACKAGE_DIR"
cp "${PLUGIN_SLUG}.php" readme.txt LICENSE "$PACKAGE_DIR/"
cp -R includes "$PACKAGE_DIR/"

if command -v zip >/dev/null 2>&1; then
	(
		cd build
		zip -qr "../${ZIP_FILE}" "$PLUGIN_SLUG"
	)
elif command -v tar >/dev/null 2>&1; then
	if command -v php >/dev/null 2>&1; then
		php scripts/create-release-zip.php "$PACKAGE_DIR" "$ZIP_FILE"
	elif command -v docker >/dev/null 2>&1; then
		if command -v cygpath >/dev/null 2>&1; then
			MOUNT_PATH="$(cygpath -w "$(pwd)")"
		else
			MOUNT_PATH="$(pwd)"
		fi
		docker run --rm -v "${MOUNT_PATH}:/app" -w /app composer:2 php scripts/create-release-zip.php "$PACKAGE_DIR" "$ZIP_FILE"
	else
		echo "Missing zip and PHP ZipArchive fallback." >&2
		exit 1
	fi
elif command -v powershell.exe >/dev/null 2>&1; then
	powershell.exe -NoProfile -Command "Compress-Archive -Path 'build/${PLUGIN_SLUG}' -DestinationPath '${ZIP_FILE}' -Force"
else
	echo "Missing zip-compatible archiver. Install zip or tar." >&2
	exit 1
fi

echo "$ZIP_FILE"
