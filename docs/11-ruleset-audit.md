# 11 - Ruleset Audit

Workflow: [`.github/workflows/11-ruleset-audit.yml`](../.github/workflows/11-ruleset-audit.yml)
· Required: no (repository guard) · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The live GitHub rulesets match the desired state checked in under
[`.github/rulesets/`](../.github/rulesets/), so the protection the docs and
the release process rely on is actually in force: the three required merge
checks, PR-only squash merges, no force-push or deletion of `main`, and
protected release tags. It does not test the plugin.

## When it runs

- **Weekly**, Mondays at 09:47 UTC, and **manual dispatch**.
- **PRs** that change `.github/rulesets/`, this workflow,
  `scripts/ruleset-drift.py` or `tests/unit/test_ruleset_drift.py`.
- A failed scheduled run is reported by GitHub's normal failed-workflow
  notification.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `11 - Ruleset Audit` | 5 min | Runs `tests/unit/test_ruleset_drift.py`, then `python3 scripts/ruleset-drift.py`, which reads every live ruleset through the GitHub API and compares it with the files. Writes the result to the job summary and fails on any drift. |

The comparison, per ruleset, matched by name:

- A ruleset that exists only live, or only as a file, is drift.
- `target`, `enforcement`, `conditions` and `bypass_actors` must match exactly.
- The set of rule types must match exactly.
- Every parameter written in the file must match the live value. Parameters
  GitHub returns that the file does not mention are ignored, so a new GitHub
  default does not fail the check; add the key to the file to start checking it.
- List order never matters.

The tests also require `main-ci-gates` to list exactly the names of the
summary jobs of 01, 02 and 03-04, so renaming one of those workflows without
updating the ruleset fails CI.

Permissions: `contents: read`. The default token can read every ruleset
field except `bypass_actors`, which GitHub returns only to repository admins.
The workflow therefore checks everything else and lists the rulesets whose
bypass actors it could not see; run the script locally as an admin (for
example before a release) to check bypass actors as well.

## Run locally

```bash
python3 -B scripts/ruleset-drift.py          # uses gh api; as an admin it also checks bypass actors
python3 -B -m unittest discover -s tests/unit -p test_ruleset_drift.py -v
```

## Reading failures

Each finding names the ruleset and the field, with the expected (file) and
live values. Decide which side is right:

- **The live change was a mistake:** restore it in Settings → Rules →
  Rulesets to match the file.
- **The live change is intended:** update the file in a PR (see
  [changing a ruleset](91-ci-cd-platform.md#changing-a-ruleset)).

A renamed ruleset shows up twice: once as "in the repository files but not
live" and once as "live but has no file".
