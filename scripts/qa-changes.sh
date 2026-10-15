#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "${EVENT_NAME:?Workflow event required}" == workflow_dispatch || "$EVENT_NAME" == schedule ]]; then
	echo "should-run=true"
	exit 0
fi
if [[ "$EVENT_NAME" == pull_request ]]; then
	merge_base="$(git merge-base "${BASE_SHA:?PR base required}" "${HEAD_SHA:?PR head required}")"
	changed="$(git diff --name-only "$merge_base" "$HEAD_SHA")"
elif [[ "$EVENT_NAME" == push ]]; then
	if [[ "${BEFORE_SHA:?Push base required}" == 0000000000000000000000000000000000000000 ]]; then
		changed="$(git ls-tree -r --name-only "${CURRENT_SHA:?Push head required}")"
	else
		changed="$(git diff --name-only "$BEFORE_SHA" "${CURRENT_SHA:?Push head required}")"
	fi
else
	echo "Unsupported QA change-detection event." >&2
	exit 78
fi
if grep -Eq '^("|\.github/compatibility-versions\.json|\.github/workflows/|docker-compose[^/]*\.yml|scripts/|tests/|includes/|webmastery-site-toolkit-for-mcp\.php|readme\.txt|composer\.(json|lock)|phpcs\.xml\.dist|phpstan[^/]*|phpunit\.xml\.dist|assets/|CHANGELOG\.md|LICENSE)' <<< "$changed"; then
	echo "should-run=true"
else
	status=$?
	[[ "$status" == 1 ]] || { echo "QA change detection failed." >&2; exit "$status"; }
	echo "should-run=false"
fi
