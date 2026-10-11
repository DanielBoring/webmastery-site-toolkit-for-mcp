#!/usr/bin/env bash

# shellcheck source=scripts/qa-compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"

check_package() {
	local checker="$1"
	local version_args=()
	local helper_root
	local artifacts="${E2E_ARTIFACTS_DIR:-e2e-artifacts}"
	local package_name="${PLUGIN_SLUG}-package-${checker//./-}"
	local destination="/var/www/html/wp-content/plugins/${package_name}"
	helper_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
	# WP-CLI interprets --version=latest literally; omit it to install the current release.
	if [[ "$checker" != "latest" ]]; then
		version_args=("--version=$checker")
	fi
	echo "Checking original release ZIP using Plugin Check ${checker}"
	[[ "$checker" == latest || "$checker" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
		echo "Invalid Plugin Check version." >&2; return 1;
	}
	[[ -d "$artifacts" && ! -L "$artifacts" ]] || {
		echo "Plugin Check requires the owned runtime output directory." >&2; return 1;
	}
	local artifact
	for artifact in "$artifacts/plugin-check-${checker}.json" "$artifacts/plugin-check-${checker}-results.txt" "$artifacts/plugin-check-${checker}-verdict.json"; do
		(umask 077; set -o noclobber; : > "$artifact") || {
			echo "Plugin Check output already exists or cannot be reserved; preserve prior evidence." >&2; return 1;
		}
	done
	qa_wp plugin install plugin-check "${version_args[@]}" --activate --force || return $?
	qa_wp plugin get plugin-check --fields=name,version,status --format=json |
		tee "$artifacts/plugin-check-${checker}.json" || return $?
	# Keep Git Bash from converting container paths into Windows host paths.
	MSYS_NO_PATHCONV=1 compose exec -T wordpress mkdir "$destination" || return $?
	# The pristine ZIP extraction excludes the runtime's separately mounted QA helpers.
	compose exec -T wordpress cp -R /qa-package-pristine/. "$destination" || return $?
	qa_wp plugin check "$package_name" --slug="$PLUGIN_SLUG" \
		--format=strict-json --fields=file,line,column,type,code,message |
		tee "$artifacts/plugin-check-${checker}-results.txt" || return $?
	# Plugin Check can return zero even with ERROR findings; the report is authoritative.
	host_php "$helper_root/release-tools.php" checker-verdict \
		"$artifacts/plugin-check-${checker}-results.txt" \
		"$artifacts/plugin-check-${checker}-verdict.json"
}
