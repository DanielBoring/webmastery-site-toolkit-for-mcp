#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
export MSYS_NO_PATHCONV=1
source scripts/qa-compose.sh

candidate="${1:-}"
candidate_archive="${2:-}"
if [[ -n "$candidate" ]]; then
	[[ "$#" == 2 && -d "$candidate/.git" && -f "$candidate_archive" &&
		"$(git -C "$candidate" rev-parse HEAD)" == 115b577bf59e4d3c33b0ccc78d9317e09efab3e1 &&
		-z "$(git -C "$candidate" status --porcelain --untracked-files=no)" ]] || {
		echo "Candidate requires the clean exact 115b577 snapshot checkout." >&2; exit 1;
	}
fi
: "${COMPOSE_PROJECT_NAME:?Choose a new isolated assessment project}"
qa_prepare_project
qa_verify_resources
for resource in container network volume; do
	[[ -z "$(docker "$resource" ls -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]] || {
		echo "Assessment requires a fresh project with no existing resources." >&2; exit 1;
	}
done
private="$WSTM_QA_PRIVATE_OUTPUT"
if command -v cygpath >/dev/null 2>&1; then private="$(cygpath -m "$private")"; fi
mkdir "$private/release"
download() {
	bash scripts/compatibility-download.sh "$1" "$2" "$3" "$4" || return $?
	chmod 0644 "$4"
}
if [[ -n "$candidate" ]]; then
	cp "$candidate_archive" "$private/release/plugin.zip"
	python -B scripts/adapter-candidate-assessment.py --verify-package "$candidate" "$private/release/plugin.zip" \
		> "$private/packages.json"
else
	download https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/releases/download/v2.6.0/webmastery-site-toolkit-for-mcp-2.6.0.zip \
		577f4f2a5f1a6ca2c9ac32d3cb24d3600a8aecfedb2e11dcd812d768c73de188 sha256 "$private/release/plugin.zip"
	download https://downloads.wordpress.org/plugin/webmastery-site-toolkit-for-mcp.2.6.0.zip \
		42911f5967eee06db32cd01cd74cd23dc99220fc82163e80494c3bd86ed3001d sha256 "$private/release/wordpress-org.zip"
	python -B scripts/adapter-release-assessment.py --verify-packages \
		"$private/release/plugin.zip" "$private/release/wordpress-org.zip" > "$private/packages.json"
fi
# PHP expressions must not expand in the shell.
# shellcheck disable=SC2016
host_php -r '
umask(0022);
$zip = new ZipArchive();
if ($zip->open($argv[1]) !== true) { throw new RuntimeException("Cannot open release ZIP."); }
for ($i = 0; $i < $zip->numFiles; $i++) {
	$name = $zip->getNameIndex($i);
	if (!preg_match("~\Awebmastery-site-toolkit-for-mcp/[a-zA-Z0-9_./-]*\z~", $name) || strpos($name, "..") !== false) {
		throw new RuntimeException("Invalid release ZIP member.");
	}
}
if (!$zip->extractTo($argv[2])) { throw new RuntimeException("Cannot extract release ZIP."); }
' "$private/release/plugin.zip" "$private/release"
WSTM_ASSESSMENT_PACKAGE_SHA256="$(sha256sum "$private/release/plugin.zip" | cut -d ' ' -f 1)"
export WSTM_ASSESSMENT_PACKAGE_SHA256

# Mount only original production files; test support is a separate read-only mount.
export E2E_PACKAGE_ROOT="$private/release/webmastery-site-toolkit-for-mcp"
export E2E_CHECKER_ROOT="$E2E_PACKAGE_ROOT"
export E2E_PACKAGE_ZIP="$private/release/plugin.zip"
# Called by the sourced ownership wrapper.
# shellcheck disable=SC2329
qa_compose_command() {
	docker compose --project-name "$COMPOSE_PROJECT_NAME" -f docker-compose.yml \
		-f tests/e2e/adapter-release-compose.yml "$@"
}
# shellcheck disable=SC2329
cleanup() {
	local status=$?
	trap - EXIT
	compose logs --no-color > "$private/compose.log" || status=1
	if compose exec -T wordpress test -f /var/www/html/wp-content/debug.log; then
		compose exec -T wordpress cat /var/www/html/wp-content/debug.log > "$private/wordpress-debug.log" || status=1
	fi
	compose down -v --remove-orphans || status=1
	echo "Private assessment evidence: $private"
	exit "$status"
}
trap cleanup EXIT
compose up -d --wait --wait-timeout 180 mysql
compose up -d wordpress
ready=0
for ((attempt=0; attempt<60; attempt++)); do
	if compose exec -T wordpress test -f /var/www/html/wp-load.php; then ready=1; break; fi
	sleep 2
done
[[ "$ready" == 1 ]] || { echo "WordPress initialization timed out." >&2; exit 1; }
qa_initialize_artifacts
compose exec -T wordpress mkdir -p /var/www/html/.qa-bin /var/www/html/wp-content/mu-plugins
download https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar \
	be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832 \
	sha512 "$private/wp.phar"
compose cp "$private/wp.phar" wordpress:/var/www/html/.qa-bin/wp
host_php -r 'echo bin2hex(random_bytes(32)), "\n";' |
	compose exec -T wordpress php /qa-parent-support/tests/e2e/adapter-release-install.php
compose exec -T wordpress cp /qa-parent-support/tests/e2e/adapter-release-server.php \
	/var/www/html/wp-content/mu-plugins/adapter-assessment.php
if [[ -n "$candidate" ]]; then
	compose cp "$candidate/tests/e2e/custom-post-types-fixture.php" \
		wordpress:/var/www/html/wp-content/mu-plugins/adapter-assessment-cpts.php
fi
assessment_status=0
for adapter in 0.6.1 0.7.0; do
	case "$adapter" in
		0.6.1) digest=1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676 ;;
		0.7.0) digest=9168c18dbd018428ff14ee28e7018aa0399d4b8819b7c98b406f6610731c3a79 ;;
	esac
	download "https://github.com/WordPress/mcp-adapter/releases/download/v$adapter/mcp-adapter.zip" \
		"$digest" sha256 "$private/adapter-$adapter.zip"
	compose cp "$private/adapter-$adapter.zip" wordpress:/var/www/html/.qa-bin/adapter.zip
	qa_wp plugin install /var/www/html/.qa-bin/adapter.zip --force --activate
	qa_wp plugin activate webmastery-site-toolkit-for-mcp
	# Replacement files must not share the previous Adapter's Apache opcode cache.
	compose restart wordpress
	compose up -d --wait --wait-timeout 180 wordpress
	binding="$(compose port wordpress 80)"
	[[ "$binding" =~ ^127\.0\.0\.1:([0-9]+)$ ]] || { echo "Non-loopback assessment binding refused." >&2; exit 1; }
	export WSTM_ASSESSMENT_URL="http://$binding"
	qa_wp eval 'require "/qa-parent-support/tests/e2e/adapter-release-fixture.php";' > "$private/fixture-$adapter.json"
	if [[ -n "$candidate" ]]; then
		python -B scripts/adapter-candidate-assessment.py "$private/fixture-$adapter.json" \
			"$private/evidence-$adapter.json" "$candidate/tests/e2e/abilities-manifest.json" || assessment_status=1
	else
		python -B scripts/adapter-release-assessment.py "$private/fixture-$adapter.json" "$private/evidence-$adapter.json" || assessment_status=1
	fi
	# Only actors created above are removed; plugin installation and draft persist for the comparison.
	qa_wp user delete adapter_assessment_administrator adapter_assessment_subscriber --reassign=1 --yes
done
exit "$assessment_status"
