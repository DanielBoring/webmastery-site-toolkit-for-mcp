#!/usr/bin/env bash

set -euo pipefail

directory="${1:-ISSUES}"
dry_run="${2:-true}"
repository="${GITHUB_REPOSITORY:-}"

if [[ -z "$repository" ]]; then
	echo "GITHUB_REPOSITORY must identify the target repository." >&2
	exit 1
fi

if [[ "$dry_run" != "true" && "$dry_run" != "false" ]]; then
	echo "dry_run must be true or false." >&2
	exit 1
fi

if [[ ! -d "$directory" ]]; then
	echo "Issue backlog directory not found: $directory" >&2
	exit 1
fi

for command in gh; do
	if ! command -v "$command" >/dev/null 2>&1; then
		echo "Required command not found: $command" >&2
		exit 1
	fi
done

python_command=""
for candidate in python3 python; do
	if command -v "$candidate" >/dev/null 2>&1; then
		python_command="$candidate"
		break
	fi
done
if [[ -z "$python_command" ]]; then
	echo "Required command not found: python3 or python" >&2
	exit 1
fi

mapfile -t files < <(find "$directory" -maxdepth 1 -type f -name '*.md' -print | sort)
if (( ${#files[@]} == 0 )); then
	echo "No Markdown issue files found in $directory." >&2
	exit 1
fi

existing_issues="$(mktemp)"
trap 'rm -f "$existing_issues"' EXIT
gh issue list \
	--repo "$repository" \
	--state all \
	--limit 1000 \
	--json number,title,body,url > "$existing_issues"

created=0
skipped=0
declare -A seen_titles=()

for file in "${files[@]}"; do
	title="$(
		awk '
			NR == 1 && $0 != "---" { exit 2 }
			NR > 1 && $0 == "---" { exit }
			/^title: / {
				sub(/^title: /, "")
				print
				exit
			}
		' "$file"
	)"
	labels="$(
		awk '
			NR == 1 && $0 != "---" { exit 2 }
			NR > 1 && $0 == "---" { exit }
			/^labels: / {
				sub(/^labels: /, "")
				print
				exit
			}
		' "$file"
	)"

	if [[ "$title" == \'*\' ]]; then
		title="${title:1:${#title}-2}"
		title="${title//\'\'/\'}"
	elif [[ "$title" == \"*\" ]]; then
		title="${title:1:${#title}-2}"
	fi

	if [[ -z "$title" ]]; then
		echo "Missing title in $file." >&2
		exit 1
	fi

	body="$(
		awk '
			BEGIN { delimiters = 0 }
			$0 == "---" {
				delimiters++
				next
			}
			delimiters >= 2 { print }
		' "$file"
	)"
	source_path="${file//\\//}"
	marker="<!-- issue-source: $source_path -->"
	existing_url="$(
		"$python_command" - "$existing_issues" "$marker" "$title" <<'PY'
import json
import sys

path, marker, title = sys.argv[1:]
with open(path, encoding="utf-8") as issues_file:
    issues = json.load(issues_file)

for issue in issues:
    if marker in (issue.get("body") or "") or issue.get("title") == title:
        print(issue.get("url") or "")
        break
PY
	)"

	if [[ -n "$existing_url" || -n "${seen_titles[$title]:-}" ]]; then
		echo "SKIP: $title${existing_url:+ ($existing_url)}"
		((skipped += 1))
		continue
	fi

	seen_titles["$title"]=1
	if [[ "$dry_run" == "true" ]]; then
		echo "DRY RUN: $title"
		((created += 1))
		continue
	fi

	label_args=()
	IFS=',' read -ra label_values <<< "$labels"
	for label in "${label_values[@]}"; do
		label="${label#"${label%%[![:space:]]*}"}"
		label="${label%"${label##*[![:space:]]}"}"
		if [[ -n "$label" ]]; then
			label_args+=(--label "$label")
		fi
	done

	issue_url="$(
		gh issue create \
			--repo "$repository" \
			--title "$title" \
			--body "${marker}"$'\n\n'"${body}" \
			"${label_args[@]}"
	)"
	echo "CREATED: $title ($issue_url)"
	((created += 1))
done

{
	echo "## Issue backlog import"
	echo
	echo "- Mode: \`$([[ "$dry_run" == "true" ]] && echo "dry run" || echo "create")\`"
	echo "- Candidate issues: \`$created\`"
	echo "- Skipped duplicates: \`$skipped\`"
} >> "${GITHUB_STEP_SUMMARY:-/dev/null}"

echo "Import complete: $created candidate(s), $skipped duplicate(s)."
