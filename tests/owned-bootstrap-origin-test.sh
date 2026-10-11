#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(pwd)"
mkdir -p build
WORK="$(mktemp -d "$ROOT/build/owned-bootstrap-origin-XXXXXXXX")"
trap 'status=$?; if [[ "$status" == 0 ]]; then rm -r -- "$WORK"; else echo "Bootstrap origin controls retained at $WORK" >&2; fi; exit "$status"' EXIT
source scripts/e2e-test.sh all

# All WordPress and container calls are modeled; no runtime is started.
# shellcheck disable=SC2329
wp() {
	case "$*" in
		"core is-installed") [[ "${INSTALLED:-0}" == 1 ]] ;;
		"rewrite structure /%postname%/") printf 'rewrite\n' >> "$WORK/calls" ;;
		*) echo "Unexpected modeled WP command: $*" >&2; return 1 ;;
	esac
}
# shellcheck disable=SC2329
qa_quiet_install() {
	if [[ "${2:-}" == --verify && "$1" == '' ]]; then
		printf 'verify-fresh-receipt\n' >> "$WORK/calls"
		[[ "${FRESH_RECEIPT:-0}" == 1 ]] || return 37
		return 0
	fi
	local password="$1"
	[[ "$password" =~ ^[a-f0-9]{64}$ ]]
	printf 'initialize\n' >> "$WORK/calls"
}

for WORDPRESS_URL in http://127.0.0.1:49152 http://127.0.0.1:49153; do
	: > "$WORK/calls"
	install_wordpress > "$WORK/latest.log" 2>&1
	[[ "$(cat "$WORK/calls")" == $'initialize\nrewrite' ]]
done
: > "$WORK/calls"
INSTALLED=1 FRESH_RECEIPT=1 install_wordpress > "$WORK/already-installed.log" 2>&1
[[ "$(cat "$WORK/calls")" == verify-fresh-receipt ]]
: > "$WORK/calls"
status=0
INSTALLED=1 FRESH_RECEIPT=0 install_wordpress > "$WORK/missing-fresh-receipt.log" 2>&1 || status=$?
[[ "$status" == 37 && "$(cat "$WORK/calls")" == verify-fresh-receipt ]]
echo 'Fixed internal bootstrap origin, varying owned host ports, authenticated existing-install verification and missing-receipt refusal controls passed.'
