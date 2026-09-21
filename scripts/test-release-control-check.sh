#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-control-tests-$$"
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
git push --quiet origin main
export GITHUB_EVENT_NAME=workflow_dispatch
export GITHUB_SHA
GITHUB_SHA="$(git rev-parse HEAD)"
export GITHUB_REF=refs/tags/v-release-recovery-fixture
git tag -a v-release-recovery-fixture -m fixture
git push --quiet origin v-release-recovery-fixture
bash "$REPO_ROOT/scripts/release-control-check.sh"
deny() {
	if bash "$REPO_ROOT/scripts/release-control-check.sh" > "$WORK/rejection.log" 2>&1; then
		echo "Unsafe control ref accepted: $GITHUB_REF $GITHUB_EVENT_NAME" >&2
		exit 1
	fi
}
GITHUB_EVENT_NAME=push deny
GITHUB_REF=refs/heads/main deny
GITHUB_REF=refs/tags/v2.6.0 deny
GITHUB_REF=refs/tags/v-release-recovery- deny
GITHUB_SHA=invalid deny
git tag v-release-recovery-lightweight
git push --quiet origin v-release-recovery-lightweight
GITHUB_REF=refs/tags/v-release-recovery-lightweight deny
git switch --quiet -c unreviewed
echo unreviewed >> fixture
git commit --quiet -am unreviewed
GITHUB_SHA="$(git rev-parse HEAD)"
git tag -a v-release-recovery-unreviewed -m fixture
git push --quiet origin v-release-recovery-unreviewed
GITHUB_REF=refs/tags/v-release-recovery-unreviewed deny
git switch --quiet main
GITHUB_SHA="$(git rev-parse HEAD)"
git rev-parse HEAD > .git/shallow
deny
rm .git/shallow
# Only disposable local fixture refs are changed, never repository release refs.
git --git-dir="$WORK/origin.git" update-ref refs/tags/v-release-recovery-fixture "$(git rev-parse unreviewed)"
deny
echo "PASS control tag dispatch, annotation, exact ref, current main ancestry and moved/shallow rejection"
