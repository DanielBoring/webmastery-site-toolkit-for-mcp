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
printf '%s\n' "$changed" >&2
# QA_SCOPE selects which changes require the calling workflow's checks.
#   default  any QA-relevant file (unit tooling, safeguards)
#   runtime  anything except paths known not to affect the Docker runtime suites
#   package  inputs to the built plugin ZIP and its package QA
# Git-quoted paths (leading ") always count, so unusual names never skip checks.
case "${QA_SCOPE:-default}" in
	default)
		relevant='^("|\.github/compatibility-versions\.json|\.github/workflows/|docker-compose[^/]*\.yml|scripts/|tests/|includes/|webmastery-site-toolkit-for-mcp\.php|readme\.txt|composer\.(json|lock)|phpcs\.xml\.dist|phpstan[^/]*|phpunit\.xml\.dist|assets/|CHANGELOG\.md|LICENSE)'
		;;
	runtime)
		# Inverted: every path is relevant unless it is a known non-runtime path.
		ignored='^(docs/|ISSUES/|\.wordpress-org/|tests/unit/|tests/qa-suites\.json$|[^/"]+\.md$|LICENSE$|\.github/(ISSUE_TEMPLATE/|[^/]+\.(md|yml|json)$|workflows/[^/]+\.yml$)|scripts/(test-release-|release-(recovery|fresh-validation|tag-check|control-check|publish-check|plugin-check)\.|build-release\.sh$|release-qa\.sh$|qa-profile\.py$|adapter-|update-compatibility-baselines\.php$|workflow-lint\.php$|import-issues\.sh$))'
		always='^\.github/(compatibility-versions\.json|workflows/e2e-qa\.yml)$'
		;;
	package)
		relevant='^("|\.gitattributes$|readme\.txt$|LICENSE$|webmastery-site-toolkit-for-mcp\.php$|assets/|\.wordpress-org/|docker-compose\.release\.yml$|\.github/workflows/release-package-qa\.yml$|scripts/(build-release\.sh|create-release-zip\.php|release-tools\.php|release-lib\.php|validate-release-package\.php|release-qa\.sh|release-plugin-check\.sh|product-artifacts\.php|qa-run\.sh|qa-changes\.sh)$)'
		;;
	*)
		echo "Unknown QA change-detection scope." >&2
		exit 78
		;;
esac
if [[ "${QA_SCOPE:-default}" == runtime ]]; then
	if grep -Eq "$always" <<< "$changed" || grep -Evq "$ignored" <<< "$changed"; then
		echo "should-run=true"
	else
		status=$?
		[[ "$status" == 1 ]] || { echo "QA change detection failed." >&2; exit "$status"; }
		echo "should-run=false"
	fi
elif grep -Eq "$relevant" <<< "$changed"; then
	echo "should-run=true"
else
	status=$?
	[[ "$status" == 1 ]] || { echo "QA change detection failed." >&2; exit "$status"; }
	echo "should-run=false"
fi
