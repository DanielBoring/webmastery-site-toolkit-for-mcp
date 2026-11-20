#!/usr/bin/env bash
set -Eeuo pipefail
export MSYS_NO_PATHCONV="${MSYS_NO_PATHCONV:-1}"
# shellcheck source=scripts/qa-compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/qa-compose.sh"
case "${1:-}" in
	8.0|8.1) ;;
	*) echo "Expected PHP series 8.0 or 8.1." >&2; exit 1 ;;
esac
qa_engine_preflight >&2
image="$(qa_engine build --quiet --build-arg "PHP_SERIES=$1" tests/fixtures/php80-floor)"
[[ "$image" =~ ^sha256:[a-f0-9]{64}$ ]] || { echo "Legacy image identity is malformed." >&2; exit 1; }
# PHP_VERSION keeps the upstream number even when the distributor backports fixes.
qa_engine run --rm --network none --entrypoint dpkg-query "$image" -W "php${1}-cli" "libapache2-mod-php${1}" >&2
printf '%s\n' "$image"
