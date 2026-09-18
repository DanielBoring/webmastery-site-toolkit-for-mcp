#!/usr/bin/env bash

# shellcheck source=scripts/qa-compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"

check_package() {
	local checker="$1"
	local version_args=()
	local helper_root
	local destination="/var/www/html/wp-content/plugins/${PLUGIN_SLUG}-package"
	helper_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
	# WP-CLI interprets --version=latest literally; omit it to install the current release.
	if [[ "$checker" != "latest" ]]; then
		version_args=("--version=$checker")
	fi
	echo "Checking original release ZIP using Plugin Check ${checker}"
	mkdir -p e2e-artifacts
	rm -f "e2e-artifacts/plugin-check-${checker}.json" \
		"e2e-artifacts/plugin-check-${checker}-results.txt" \
		"e2e-artifacts/plugin-check-${checker}-verdict.json"
	compose exec -T wordpress wp --allow-root plugin install plugin-check "${version_args[@]}" --activate --force
	compose exec -T wordpress wp --allow-root plugin get plugin-check --fields=name,version,status --format=json |
		tee "e2e-artifacts/plugin-check-${checker}.json"
	# Keep Git Bash from converting container paths into Windows host paths.
	MSYS_NO_PATHCONV=1 compose exec -T wordpress rm -rf "$destination"
	MSYS_NO_PATHCONV=1 compose exec -T wordpress mkdir -p "$destination"
	compose cp "${PACKAGE_ROOT}/${PLUGIN_SLUG}/." "wordpress:${destination}"
	compose exec -T wordpress wp --allow-root plugin check "${PLUGIN_SLUG}-package" --slug="$PLUGIN_SLUG" \
		--format=strict-json --fields=file,line,column,type,code,message |
		tee "e2e-artifacts/plugin-check-${checker}-results.txt"
	# Plugin Check can return zero even with ERROR findings; the report is authoritative.
	host_php "$helper_root/release-tools.php" checker-verdict \
		"e2e-artifacts/plugin-check-${checker}-results.txt" \
		"e2e-artifacts/plugin-check-${checker}-verdict.json"
}
