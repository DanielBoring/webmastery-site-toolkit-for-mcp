#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-tag-tests-$$"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT
git init --quiet --bare --initial-branch=main "$WORK/origin.git"
git clone --quiet "$WORK/origin.git" "$WORK/work"
cd "$WORK/work"
git config user.name "Release fixture"
git config user.email "release-fixture@example.invalid"
echo initial > fixture
git add fixture
git commit --quiet -m fixture
INITIAL_SHA="$(git rev-parse HEAD)"
git push --quiet origin main
git tag v2.5.0
git push --quiet origin v2.5.0
bash "$REPO_ROOT/scripts/release-tag-check.sh" v2.5.0
for invalid in v2.5.0-rc1 v2.5 v02.5.0 release-v2.5.0; do
	if bash "$REPO_ROOT/scripts/release-tag-check.sh" "$invalid" >/dev/null 2>&1; then
		echo "Invalid tag accepted: $invalid" >&2
		exit 1
	fi
done
git switch --quiet -c unreviewed
echo unreviewed >> fixture
git commit --quiet -am unreviewed
git tag v2.6.0
git push --quiet origin v2.6.0
if bash "$REPO_ROOT/scripts/release-tag-check.sh" v2.6.0 >/dev/null 2>&1; then
	echo "Non-main source accepted." >&2
	exit 1
fi
git switch --quiet main
git merge --quiet --ff-only unreviewed
git push --quiet origin main
git update-ref refs/remotes/origin/main "$INITIAL_SHA"
bash "$REPO_ROOT/scripts/release-tag-check.sh" v2.6.0
git tag -a v2.6.1 -m "Annotated fixture"
git push --quiet origin v2.6.1
bash "$REPO_ROOT/scripts/release-tag-check.sh" v2.6.1
# A newer main remains a valid ancestor proof for a reviewed earlier release.
echo newer >> fixture
git commit --quiet -am newer
git push --quiet origin main
git checkout --quiet v2.6.0
bash "$REPO_ROOT/scripts/release-tag-check.sh" v2.6.0
git push --quiet --force origin main:refs/tags/v2.6.0
if bash "$REPO_ROOT/scripts/release-tag-check.sh" v2.6.0 >/dev/null 2>&1; then
	echo "Moved remote tag accepted." >&2
	exit 1
fi
git rev-parse HEAD > .git/shallow
if bash "$REPO_ROOT/scripts/release-tag-check.sh" v2.6.1 >/dev/null 2>&1; then
	echo "Shallow checkout accepted." >&2
	exit 1
fi
rm .git/shallow
echo "PASS exact/annotated tag, non-main rejection, fresh fetch, ancestor release, moved-tag and shallow-history fixtures"
