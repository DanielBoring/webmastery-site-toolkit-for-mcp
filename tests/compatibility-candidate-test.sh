#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ "$(uname -s)" != Linux ]]; then
	echo 'BLOCKED: compatibility candidate shell/provenance regression requires native Linux.' >&2
	exit 78
fi
umask 077
work="$(mktemp -d "${TMPDIR:-/tmp}/wstm-compatibility-candidate-XXXXXXXX")"
trap 'status=$?; printf "Compatibility candidate originals retained at %s (exit %s)\n" "$work" "$status" >&2; exit "$status"' EXIT

for job in compatibility-qa current-plugin-check; do
	script="$work/$job.sh"
	awk -v job="$job" '
		$0 == "  " job ":" { inside=1; next }
		inside && /^  [a-z-]+:/ { inside=0 }
		inside && $0 == "      - name: Commit exact generated inputs for source-bound QA" { step=1; next }
		step && /^      - name:/ { exit }
		step && $0 == "        run: |" { body=1; next }
		body { if (substr($0,1,10) != "          ") exit 2; print substr($0,11); lines++ }
		END { if (!lines) exit 3 }
	' "$root/.github/workflows/compatibility-qa.yml" > "$script"

	for scenario in unchanged baseline readme unexpected staged untracked wrong-base receipt-collision baseline-receipt-collision baseline-receipt-symlink; do
		case_root="$work/$job-$scenario"
		checkout="$case_root/source"
		mkdir -p "$checkout/.github" "$checkout/tests"
		cp -R "$root/scripts" "$root/includes" "$checkout/"
		cp -R "$root/tests/e2e" "$checkout/tests/"
		cp -R "$root/.github/workflows" "$checkout/.github/"
		cp "$root/.github/compatibility-versions.json" "$checkout/.github/"
		cp "$root/webmastery-site-toolkit-for-mcp.php" "$root/readme.txt" "$root/LICENSE" "$root/docker-compose.yml" "$checkout/"
		git -C "$checkout" init --quiet
		git -C "$checkout" config --local core.autocrlf false
		git -C "$checkout" add -- .github scripts includes tests webmastery-site-toolkit-for-mcp.php readme.txt LICENSE docker-compose.yml
		git -C "$checkout" -c user.name='QA fixture' -c user.email='qa@example.test' commit --quiet \
			-m 'Create isolated compatibility source fixture' \
			-m 'Co-authored-by: Copilot App <223556219+Copilot@users.noreply.github.com>'
		base="$(git -C "$checkout" rev-parse HEAD)"
		mkdir "$checkout/compatibility-artifacts"
		expected=pass
		changed=false
		selected_base="$base"
		case "$scenario" in
			baseline)
				printf '\n' >> "$checkout/.github/compatibility-versions.json"
				changed=true
				;;
			readme)
				sed -i -E 's/^Tested up to: .*/Tested up to: 99.1/' "$checkout/readme.txt"
				changed=true
				if [[ "$job" == compatibility-qa ]]; then expected=refuse; fi
				;;
			unexpected)
				printf '\nUnexpected input\n' >> "$checkout/LICENSE"
				expected=refuse
				;;
			staged)
				printf '\n' >> "$checkout/.github/compatibility-versions.json"
				git -C "$checkout" add -- .github/compatibility-versions.json
				expected=refuse
				;;
			untracked)
				printf 'untracked\n' > "$checkout/unexpected-input"
				expected=refuse
				;;
			wrong-base)
				selected_base=0000000000000000000000000000000000000000
				expected=refuse
				;;
			receipt-collision|baseline-receipt-collision)
				if [[ "$scenario" == baseline-receipt-collision ]]; then
					printf '\n' >> "$checkout/.github/compatibility-versions.json"
				fi
				printf 'retained receipt\n' > "$checkout/compatibility-artifacts/tested-source.json"
				cp "$checkout/compatibility-artifacts/tested-source.json" "$case_root/receipt.before"
				expected=refuse
				;;
			baseline-receipt-symlink)
				printf '\n' >> "$checkout/.github/compatibility-versions.json"
				ln -s "$case_root/absent-receipt" "$checkout/compatibility-artifacts/tested-source.json"
				expected=refuse
				;;
		esac
		git -C "$checkout" diff --cached --binary > "$case_root/index.before"
		git -C "$checkout" diff --binary > "$case_root/worktree.before"
		owner=0123456789abcdef0123456789abcdef
		if [[ "$scenario" == baseline* || "$scenario" == readme ]]; then
			status=0
			(
				cd "$checkout"
				php scripts/untrusted-provenance.php "$owner" compatibility-fixture all "e2e-artifacts/untrusted-$owner"
			) > "$case_root/dirty-provenance.stdout" 2> "$case_root/dirty-provenance.stderr" || status=$?
			printf '%s\n' "$status" > "$case_root/dirty-provenance.exit"
			test "$status" != 0
			grep -Fq 'Cannot attest the exact Git source identity.' "$case_root/dirty-provenance.stderr"
		fi
		status=0
		(
			cd "$checkout"
			BASE_SOURCE_SHA="$selected_base" GITHUB_OUTPUT="$case_root/outputs" bash --noprofile --norc "$script"
		) > "$case_root/candidate.stdout" 2> "$case_root/candidate.stderr" || status=$?
		printf '%s\n' "$status" > "$case_root/candidate.exit"
		tested="$(git -C "$checkout" rev-parse HEAD)"
		if [[ "$expected" == refuse ]]; then
			test "$status" != 0
			test "$tested" = "$base"
			test ! -e "$case_root/outputs"
			git -C "$checkout" diff --cached --binary > "$case_root/index.after"
			git -C "$checkout" diff --binary > "$case_root/worktree.after"
			cmp "$case_root/index.before" "$case_root/index.after"
			cmp "$case_root/worktree.before" "$case_root/worktree.after"
			if [[ "$scenario" == receipt-collision || "$scenario" == baseline-receipt-collision ]]; then
				cmp "$case_root/receipt.before" "$checkout/compatibility-artifacts/tested-source.json"
			elif [[ "$scenario" == baseline-receipt-symlink ]]; then
				test -L "$checkout/compatibility-artifacts/tested-source.json"
				test "$(readlink "$checkout/compatibility-artifacts/tested-source.json")" = "$case_root/absent-receipt"
				test ! -e "$case_root/absent-receipt"
			else
				test ! -e "$checkout/compatibility-artifacts/tested-source.json"
			fi
		else
			test "$status" = 0
			if [[ "$changed" == true ]]; then
				test "$tested" != "$base"
				test "$(git -C "$checkout" rev-parse HEAD^)" = "$base"
			else
				test "$tested" = "$base"
			fi
			git -C "$checkout" diff --exit-code HEAD
			test "$(git -C "$checkout" remote)" = ''
			grep -Fx "base-source-sha=$base" "$case_root/outputs"
			grep -Fx "source-sha=$tested" "$case_root/outputs"
			grep -Fx "source-changed=$changed" "$case_root/outputs"
			jq -e --arg base "$base" --arg tested "$tested" --argjson changed "$changed" \
				'.base_source == $base and .tested_source == $tested and .changed == $changed' \
				"$checkout/compatibility-artifacts/tested-source.json" >/dev/null
			status=0
			(
				cd "$checkout"
				php scripts/untrusted-provenance.php "$owner" compatibility-fixture all "e2e-artifacts/untrusted-$owner"
			) > "$case_root/provenance.stdout" 2> "$case_root/provenance.stderr" || status=$?
			printf '%s\n' "$status" > "$case_root/provenance.exit"
			test "$status" = 0
			jq -e --arg tested "$tested" '.binding.source_sha == $tested' "$case_root/provenance.stdout" >/dev/null
		fi
		printf 'PASS %s %s\n' "$job" "$scenario"
	done
done
echo 'Actual workflow candidate snippets preserve base/tested identities and committed-source provenance; no Docker or network used.'
