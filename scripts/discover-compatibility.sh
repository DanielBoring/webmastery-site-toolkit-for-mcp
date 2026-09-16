#!/usr/bin/env bash
set -euo pipefail

baseline=.github/compatibility-versions.json
mkdir -p compatibility-artifacts
candidate=compatibility-artifacts/proposed-versions.json

latest_wordpress="$(curl --retry 3 --connect-timeout 20 --max-time 120 -fsSL https://api.wordpress.org/core/version-check/1.7/ |
	jq -er '[.offers[] | select(.response == "upgrade")][0].current')"
adapter_release="$(gh api repos/WordPress/mcp-adapter/releases/latest)"
latest_adapter="$(jq -er '.tag_name | ltrimstr("v")' <<< "$adapter_release")"
adapter_digest="$(jq -er '[.assets[] | select(.name == "mcp-adapter.zip") | .digest] | if length == 1 then .[0] else error("Expected one adapter ZIP") end | select(startswith("sha256:")) | ltrimstr("sha256:")' <<< "$adapter_release")"
cli_release="$(gh api repos/wp-cli/wp-cli/releases/latest)"
latest_cli="$(jq -er '.tag_name | ltrimstr("v")' <<< "$cli_release")"
if [[ ! "$latest_cli" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid WP-CLI release version." >&2
	exit 1
fi
cli_digest="$(curl --proto '=https' --proto-redir '=https' --retry 3 --connect-timeout 20 --max-time 120 -fsSL \
	"https://github.com/wp-cli/wp-cli/releases/download/v${latest_cli}/wp-cli-${latest_cli}.phar.sha512" | awk '{print $1}')"

plugin_version() {
	curl --globoff --retry 3 --connect-timeout 20 --max-time 120 -fsSL \
		"https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=$1" | jq -er '.version'
}
yoast="$(plugin_version wordpress-seo)"
seopress="$(plugin_version wp-seopress)"
plugin_check="$(plugin_version plugin-check)"
jq --arg wp "$latest_wordpress" --arg adapter "$latest_adapter" --arg adapter_digest "$adapter_digest" \
	--arg cli "$latest_cli" --arg cli_digest "$cli_digest" --arg yoast "$yoast" --arg seopress "$seopress" --arg checker "$plugin_check" \
	'.wordpress=$wp | .mcp_adapter=$adapter | .mcp_adapter_sha256=$adapter_digest | .wp_cli=$cli | .wp_cli_sha512=$cli_digest |
	.yoast=$yoast | .seopress=$seopress | .plugin_check=$checker' "$baseline" > "$candidate"

# This validates both schemas and rejects upstream version regressions before creating a matrix.
# shellcheck disable=SC2016
php -r '
require "scripts/compatibility-baselines.php";
$old = webmastery_mcp_read_baselines($argv[1]);
$new = webmastery_mcp_read_baselines($argv[2]);
foreach (["wordpress", "mcp_adapter", "wp_cli", "yoast", "seopress", "plugin_check"] as $key) {
	if (version_compare($new[$key], $old[$key], "<")) {
		throw new RuntimeException("Discovered {$key} is older than the baseline.");
	}
}
' "$baseline" "$candidate"
matrix="$(php scripts/compatibility-matrix.php "$baseline" "$candidate")"
update_needed=false
if ! diff -q <(jq -S . "$baseline") <(jq -S . "$candidate") >/dev/null; then
	update_needed=true
fi
{
	echo "source-sha=$(git rev-parse HEAD)"
	echo "update-needed=$update_needed"
	echo "matrix=$matrix"
} >> "$GITHUB_OUTPUT"
{
	echo "## Compatibility discovery"
	echo
	echo "Source: \`$(git rev-parse HEAD)\`. Proposed values are **not** compatibility evidence."
	echo
	echo '| Dependency | Baseline | Discovered |'
	echo '| --- | --- | --- |'
	for key in wordpress mcp_adapter wp_cli yoast seopress plugin_check; do
		# Backticks are Markdown formatting, not command substitutions.
		# shellcheck disable=SC2016
		printf '| %s | `%s` | `%s` |\n' "$key" "$(jq -r --arg key "$key" '.[$key]' "$baseline")" "$(jq -r --arg key "$key" '.[$key]' "$candidate")"
	done
} >> "$GITHUB_STEP_SUMMARY"
