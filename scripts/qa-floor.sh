#!/usr/bin/env bash
set +x
set -Eeuo pipefail
export MSYS_NO_PATHCONV="${MSYS_NO_PATHCONV:-1}"
# shellcheck source=scripts/qa-compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"
if [[ -z "${WSTM_QA_LOCATION:-}" ]]; then
	export WSTM_QA_LOCATION=local
	if [[ "${GITHUB_ACTIONS:-}" == true ]]; then export WSTM_QA_LOCATION=cloud; fi
fi
qa_engine_preflight
token="$(host_php -r 'echo bin2hex(random_bytes(8));')"
image="$(bash scripts/qa-build-legacy-image.sh 8.0)"
export WORDPRESS_IMAGE="$image" WSTM_QA_EXPECTED_PHP=8.0 WSTM_QA_EXPECTED_WORDPRESS=6.9
export DEPENDENCY_POLICY=wp69-seo
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-wstm-php80-floor-${token}}"
export E2E_ARTIFACTS_DIR="${E2E_ARTIFACTS_DIR:-e2e-artifacts-${COMPOSE_PROJECT_NAME}}"
bash scripts/qa-run.sh all
