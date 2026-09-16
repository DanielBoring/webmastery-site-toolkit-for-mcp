#!/usr/bin/env bash
set -Eeuo pipefail

TAG="${1:?exact release tag required}"
[[ "$TAG" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || {
	echo "Release tag must be exactly vX.Y.Z, without prefixes, suffixes, or leading zeros." >&2
	exit 1
}
[[ "$(git rev-parse --is-shallow-repository)" == "false" ]] || {
	echo "Release ancestry requires a full checkout." >&2
	exit 1
}
git fetch --no-tags origin +refs/heads/main:refs/remotes/origin/main
SOURCE_SHA="$(git rev-parse HEAD)"
[[ "$(git rev-parse "${TAG}^{commit}")" == "$SOURCE_SHA" ]] || { echo "Tag does not identify this checkout." >&2; exit 1; }
REMOTE_TAGS="$(git ls-remote --exit-code --tags origin "refs/tags/$TAG" "refs/tags/$TAG^{}")"
REMOTE_SHA="$(printf '%s\n' "$REMOTE_TAGS" | awk 'NR == 1 { sha = $1 } /\^\{\}$/ { sha = $1 } END { print sha }')"
[[ "$REMOTE_SHA" == "$SOURCE_SHA" ]] || { echo "Remote tag moved or no longer identifies the approved source." >&2; exit 1; }
git merge-base --is-ancestor "$SOURCE_SHA" refs/remotes/origin/main || {
	echo "Release source is not contained in fetched current origin/main." >&2
	exit 1
}
echo "PASS tag $TAG at $SOURCE_SHA is contained in current origin/main."
