#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/e2e-test.sh
source "$root/scripts/e2e-test.sh"

compose() { :; }
wp() { printf '%s\n' "$*"; }
MCP_ADAPTER_ZIP=https://example.invalid/adapter.zip
MCP_ADAPTER_SHA256=unused-by-mocked-transport

DEPENDENCY_POLICY=pinned
YOAST_VERSION=28.5
SEOPRESS_VERSION=10.2
output="$(install_plugins)"
grep -Fx 'plugin install wordpress-seo --version=28.5 --activate --force' <<< "$output"
grep -Fx 'plugin install wp-seopress --version=10.2 --activate --force' <<< "$output"

DEPENDENCY_POLICY=latest-seo
YOAST_VERSION=latest
SEOPRESS_VERSION=latest
output="$(install_plugins)"
grep -Fx 'plugin install wordpress-seo --activate --force' <<< "$output"
grep -Fx 'plugin install wp-seopress --activate --force' <<< "$output"
if grep -q -- '--version=' <<< "$output"; then
	echo 'Floating SEO must omit --version rather than request a release literally named latest.' >&2
	exit 1
fi
echo 'Compatibility dependency policy tests passed without Docker or network.'
