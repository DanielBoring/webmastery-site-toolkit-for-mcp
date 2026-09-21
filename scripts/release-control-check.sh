#!/usr/bin/env bash
set -Eeuo pipefail

[[ "${GITHUB_EVENT_NAME:?}" == "workflow_dispatch" &&
	"${GITHUB_REF:?}" =~ ^refs/tags/v-release-recovery-[a-z0-9][a-z0-9-]*$ ]] || {
	echo "Recovery requires dispatch on a dedicated v-release-recovery-* control tag, not a branch or plugin release tag." >&2
	exit 1
}
[[ "${GITHUB_SHA:?}" =~ ^[a-f0-9]{40}$ && "$(git rev-parse HEAD)" == "$GITHUB_SHA" ]] || {
	echo "Control checkout does not match the dispatched workflow source." >&2
	exit 1
}
[[ "$(git rev-parse --is-shallow-repository)" == "false" && "$(git cat-file -t "$GITHUB_REF")" == "tag" ]] || {
	echo "Recovery requires full history and an annotated control tag." >&2
	exit 1
}
[[ "$(git rev-parse "${GITHUB_REF}^{commit}")" == "$GITHUB_SHA" ]] || {
	echo "Control tag does not identify the dispatched workflow source." >&2
	exit 1
}
REMOTE_TAGS="$(git ls-remote --exit-code --tags origin "$GITHUB_REF" "${GITHUB_REF}^{}")"
[[ "$(printf '%s\n' "$REMOTE_TAGS" | awk 'NR == 1 { print $1 }')" == "$(git rev-parse "$GITHUB_REF")" &&
	"$(printf '%s\n' "$REMOTE_TAGS" | awk '/\^\{\}$/ { print $1 }')" == "$GITHUB_SHA" ]] || {
	echo "Remote control tag differs from the approved workflow ref." >&2
	exit 1
}
git fetch --no-tags origin +refs/heads/main:refs/remotes/origin/main
git merge-base --is-ancestor "$GITHUB_SHA" refs/remotes/origin/main || {
	echo "Control workflow must already be contained in reviewed current main." >&2
	exit 1
}
echo "Verified annotated recovery control tag in main; this is not a plugin release tag."
