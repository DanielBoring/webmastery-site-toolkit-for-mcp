#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-prerequisite-tests-$$"
mkdir -p "$WORK/bin"
trap 'rm -rf "$WORK"' EXIT
WORKFLOW="$REPO_ROOT/.github/workflows/release.yml"
awk '/name: Install and verify publish-host Subversion before first use/ { found=1; next }
	found && /run: \|/ { block=1; next }
	block && /^          / { sub(/^          /, ""); print; next }
	block { exit }' "$WORKFLOW" > "$WORK/install.sh"
test -s "$WORK/install.sh"
export FIXTURE_LOG="$WORK/commands.log"
sudo() {
	echo "$*" >> "$FIXTURE_LOG"
	[[ "$*" == "apt-get update" || "$*" == "apt-get install --yes --no-install-recommends subversion" ]] || return 20
	[[ "${FAIL_INSTALL:-0}" != "1" ]] || return 21
	if [[ "$*" == *"install "* && "${OMIT_SVN:-0}" != "1" ]]; then
		# shellcheck disable=SC2329 # Called by the workflow block in the child shell.
		svn() {
			echo "svn $*" >> "$FIXTURE_LOG"
			[[ "$*" == "--version --quiet" ]] || return 22
			echo fixture-version
		}
	fi
}
export -f sudo
# Only shell builtins and mocks are reachable; no installer or real SVN can run.
PATH="$WORK/bin" "$BASH" "$WORK/install.sh"
printf '%s\n' "apt-get update" "apt-get install --yes --no-install-recommends subversion" "svn --version --quiet" > "$WORK/expected.log"
cmp "$WORK/expected.log" "$FIXTURE_LOG"
if OMIT_SVN=1 PATH="$WORK/bin" "$BASH" "$WORK/install.sh" >/dev/null 2>&1; then
	echo "Missing SVN accepted after installation." >&2
	exit 1
fi
if FAIL_INSTALL=1 PATH="$WORK/bin" "$BASH" "$WORK/install.sh" >/dev/null 2>&1; then
	echo "Failed installation accepted." >&2
	exit 1
fi
install_line="$(grep -n 'name: Install and verify publish-host Subversion before first use' "$WORKFLOW" | cut -d: -f1)"
preflight_line="$(grep -n 'release-publish-check.sh preflight' "$WORKFLOW" | head -1 | cut -d: -f1)"
[[ "$install_line" -lt "$preflight_line" ]]
grep -A 6 'id: verify-public' "$WORKFLOW" | grep -q 'svn --version --quiet'
echo "PASS publish-host provisioning order, host SVN verification and missing/failed prerequisite rejection"

# Execute the actual job condition with Bash's equivalent boolean grammar.
# shellcheck disable=SC2016 # The generated condition must retain literal variable references.
gate_expression="$(awk '/^  publish:/ { publish=1 }
	publish && /if: >-/ { gate=1; next }
	gate && /runs-on:/ { exit }
	gate { print }' "$WORKFLOW" |
	sed -e 's/${{//g' -e 's/}}//g' \
		-e 's/!cancelled()/$CANCELLED == false/g' \
		-e 's/github.event_name/$EVENT/g' \
		-e 's/needs.release-qa.result/$QA/g' \
		-e 's/needs.recover.result/$RECOVERY/g' | tr '\n' ' ')"
printf 'release_gate() { [[ %s ]]; }\n' "$gate_expression" > "$WORK/gate.sh"
# shellcheck source=/dev/null
source "$WORK/gate.sh"
for EVENT in push workflow_dispatch pull_request; do
	for QA in success failure cancelled skipped unknown; do
		for RECOVERY in success failure cancelled skipped unknown; do
			for CANCELLED in true false; do
				expected=false
				if [[ "$CANCELLED" == false ]] &&
					{ [[ "$EVENT" == push && "$QA" == success && "$RECOVERY" == skipped ]] ||
					[[ "$EVENT" == workflow_dispatch && "$RECOVERY" == success && "$QA" == skipped ]]; }; then
					expected=true
				fi
				actual=false
				if release_gate; then actual=true; fi
				[[ "$actual" == "$expected" ]] || {
					echo "Unsafe publication gate: $EVENT/$QA/$RECOVERY/$CANCELLED" >&2
					exit 1
				}
			done
		done
	done
done
echo "PASS all 150 normal/recovery/foreign-event/skipped/failed/cancelled job-result combinations"
