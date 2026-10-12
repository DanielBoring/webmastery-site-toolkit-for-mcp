#!/usr/bin/env bash
set +x
set -Eeuo pipefail

mode="${1:-}"
case "$mode" in
	preflight|contract|e2e|all|package|publishing|release) ;;
	*) echo "Usage: bash scripts/qa-run.sh preflight|contract|e2e|all|package|publishing|release" >&2; exit 78 ;;
esac
export MSYS_NO_PATHCONV="${MSYS_NO_PATHCONV:-1}"
# shellcheck source=scripts/qa-compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"
if [[ -z "${WSTM_QA_LOCATION:-}" ]]; then
	WSTM_QA_LOCATION=local
	if [[ "${GITHUB_ACTIONS:-}" == true ]]; then WSTM_QA_LOCATION=cloud; fi
fi
export WSTM_QA_LOCATION
qa_engine_preflight
if [[ "$mode" == preflight ]]; then exit 0; fi
if [[ -z "${COMPOSE_PROJECT_NAME:-}" ]]; then
	token="$(host_php -r 'echo bin2hex(random_bytes(8));')"
	[[ "$token" =~ ^[a-f0-9]{16}$ ]] || exit 1
	export COMPOSE_PROJECT_NAME="wstm-${WSTM_QA_LOCATION}-${mode}-${token}"
fi
if [[ -z "${E2E_ARTIFACTS_DIR:-}" ]]; then
	export E2E_ARTIFACTS_DIR="e2e-artifacts-${COMPOSE_PROJECT_NAME}"
fi
export E2E_MANAGE_COMPOSE=1
printf 'QA execution: location=%s engine=%s mode=%s project=%s\n' \
	"$WSTM_QA_LOCATION" "$WSTM_QA_ENGINE" "$mode" "$COMPOSE_PROJECT_NAME"
case "$mode" in
	package) bash scripts/release-qa.sh --product ;;
	publishing) bash scripts/release-qa.sh --publishing ;;
	release) bash scripts/release-qa.sh ;;
	*) bash scripts/e2e-test.sh "$mode" ;;
esac
