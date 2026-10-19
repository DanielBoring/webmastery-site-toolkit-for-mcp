# 11 - Ruleset Audit

Workflow: [`.github/workflows/11-ruleset-audit.yml`](../.github/workflows/11-ruleset-audit.yml)
· Required: no (repository guard) · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The live GitHub rulesets and the `wordpress-org` release environment match
the desired state checked in under [`.github/rulesets/`](../.github/rulesets/)
and [`.github/environments/`](../.github/environments/), so the protection the
docs and the release process rely on is actually in force: the three required
merge checks, PR-only squash merges, no force-push or deletion of `main`,
protected release tags, and the human approval gate before anything reaches
WordPress.org. It does not test the plugin.

## When it runs

- **Weekly**, Mondays at 09:47 UTC, and **manual dispatch**.
- **PRs** that change `.github/rulesets/`, `.github/environments/`, this workflow,
  `scripts/ruleset-drift.py` or `tests/unit/test_ruleset_drift.py`.
- A failed scheduled run is reported by GitHub's normal failed-workflow
  notification.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `11 - Ruleset Audit` | 5 min | Runs `tests/unit/test_ruleset_drift.py`, then `python3 scripts/ruleset-drift.py`, which reads every live ruleset and each environment named in `.github/environments/` through the GitHub API and compares them with the files. Writes the result to the job summary and fails on any drift. |

The ruleset comparison, per ruleset, matched by name:

- A ruleset that exists only live, or only as a file, is drift.
- `target`, `enforcement`, `conditions` and `bypass_actors` must match exactly.
- The set of rule types must match exactly.
- Every parameter written in the file must match the live value. Parameters
  GitHub returns that the file does not mention are ignored, so a new GitHub
  default does not fail the check; add the key to the file to start checking it.
- List order never matters.

The environment comparison, per file in `.github/environments/`:

- A missing environment is drift: GitHub would recreate it, with no
  protection, the next time a job deploys to it.
- Admin bypass, required reviewers and **Prevent self-review**, the wait timer,
  the deployment branch policy and the allowed branch and tag patterns must
  match exactly.
- Any other protection rule type (for example a custom GitHub App rule) is drift.
- Secret names are not compared; the workflow token cannot list them.

The tests also require `08 - Release` to deploy only to environments that have
a file, so a renamed environment cannot escape the audit, and require
`main-ci-gates` to list exactly the names of the
summary jobs of 01, 02 and 03-04, so renaming one of those workflows without
updating the ruleset fails CI.

Permissions: `contents: read` and `actions: read` (for the environment
endpoints). Verified in CI on October 19, 2026, including a negative control
that reported the live values back: the default token can read every
environment field above, and every ruleset field except `bypass_actors`,
which GitHub returns only to repository admins.
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
live" and once as "live but has no file". An environment reported as
"missing live" needs attention before the next release: recreate it with the
settings in its file and re-add `SVN_USERNAME` and `SVN_PASSWORD`.
