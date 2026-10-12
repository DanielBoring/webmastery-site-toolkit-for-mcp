#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
# Synthetic functions only; no Docker command, PATH wrapper, runtime or grant.
source scripts/qa-compose.sh
# shellcheck disable=SC2329
docker() { printf '<%s>' "$@"; }
# shellcheck disable=SC2329
proof() {
	printf '%s\n' "export PHP80_RUNTIME_IMAGE='sha256:synthetic-observation'"
	printf '%s\n' "WSTM_QA_COMPOSE_ARGV=('docker' 'compose' '--project-name' 'original-owned' '-f' 'original-floor.yml')"
}
unset WSTM_QA_RUNTIME_PROFILE WSTM_PHP80_CONFIG WSTM_PHP80_CONFIG_SHA256 E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP COMPOSE_PROJECT_NAME
[[ "$(compose exec -T wordpress php -f original.php)" == '<compose><exec><-T><wordpress><php><-f><original.php>' ]]
export COMPOSE_PROJECT_NAME=original-package E2E_PACKAGE_ROOT=/original E2E_PACKAGE_ZIP=/original.zip
[[ "$(compose ps)" == '<compose><--project-name><original-package><-f><docker-compose.yml><-f><docker-compose.release.yml><ps>' ]]
unset E2E_PACKAGE_ROOT E2E_PACKAGE_ZIP
export WSTM_QA_RUNTIME_PROFILE=php80-floor WSTM108_HOST_PHP=proof
[[ "$(compose exec -T -e CHECK=1 wordpress rm -f original.php)" == '<compose><--project-name><original-owned><-f><original-floor.yml><exec><-T><-e><CHECK=1><wordpress><rm><-f><original.php>' ]]
for command in "-f foreign.yml ps" "exec -p foreign wordpress true" "ps --project-name=foreign" "down --file=foreign.yml" "exec -e exec -p foreign wordpress true" "ps -ap foreign" "exec -Tp foreign wordpress true"; do
	# Intentional tokenization of fixed synthetic cases, never external input.
	read -r -a args <<< "$command"
	if compose "${args[@]}" >/dev/null 2>&1; then echo "Selector override accepted: $command" >&2; exit 1; fi
done
# shellcheck disable=SC2329
proof() { echo "original-config-refused" >&2; return 71; }
set +e
output="$(compose ps 2>/dev/null)"
status=$?
set -e
[[ "$status" == 71 && -z "$output" ]]
unset WSTM_QA_RUNTIME_PROFILE
export WSTM_PHP80_CONFIG=''
if compose ps >/dev/null 2>&1; then echo "Unprofiled empty config silently defaulted." >&2; exit 1; fi
echo "PASS synthetic default/package routing, immutable argv dispatch, child flags, selector refusal and original-config failure."
