# 91 - CI/CD platform

The GitHub settings and conventions every numbered workflow relies on:
naming, rulesets, permissions, environments, artifacts and the PR process.
Each check's own behavior is in its numbered document; the overview is
[00 - SDLC overview](00-sdlc-overview.md).

## Workflow conventions

- **Names.** File `NN-name.yml`, workflow `NN - Name`, summary job named exactly
  like the workflow, other jobs `NN - Name / detail`. Docker workers keep
  `03 - Ability Contract QA` and `04 - Full MCP E2E QA`.
  `tests/unit/test_rescoped_workflow_gates.py` enforces this.
- **Summary jobs.** Every change-scoped workflow ends in an `if: always()`
  summary job that evaluates its prerequisites explicitly
  (`scripts/qa-check-gate.php`, `scripts/docker-qa-gate.php`), so an implicit
  skip never counts as success. Stable summary names also avoid ambiguous
  matrix-expanded check names in the ruleset.
- **Permissions.** Each workflow starts with `permissions: {}` and each job
  requests only what it needs.
- **Pinning.** Actions are pinned to full commit SHAs with a version comment;
  downloaded tools are pinned by version and SHA-256. Dependabot proposes
  updates; they get ordinary review.
- **Concurrency.** PR runs cancel superseded runs for the same PR. 07 and the
  release publish job never cancel.
- **Diagrams.** When a workflow, trigger or gate changes, update
  `docs/images/*.drawio` in draw.io and re-export the PNG in the same PR.

## Rulesets and merge settings

Verify with `gh api repos/DanielBoring/webmastery-site-toolkit-for-mcp/rulesets`.
Last read back October 16, 2026; at that point `main-ci-gates` was temporarily
empty while its checks were renamed to the names below (see
[`.github/REPOSITORY_CHANGELOG.md`](../.github/REPOSITORY_CHANGELOG.md)).

| Ruleset | Target | Rules |
| --- | --- | --- |
| `main-ci-gates` (23522901) | `refs/heads/main` | Required status checks `01 - Static QA`, `02 - Unit Tests`, `03-04 - Docker QA` from GitHub Actions (integration 15368). Not "up to date before merge"; no bypass actors. |
| `main-pr-no-required-approvals` | `refs/heads/main` | PR required; 0 approvals; review threads must be resolved. |
| `main-history-protection` | `refs/heads/main` | No deletion, no force-push. |
| `release-tag-protection` | `refs/tags/v*` | Creation, update and deletion restricted; repository admins have an explicit bypass. |

The repository allows **squash merge only** and deletes merged branches.

Do not weaken protection or bypass a failed check to merge. 05 and 06 always
report but are not merge requirements; the release requires them instead
(see [08](08-release.md)).

**Renaming a required check.** GitHub offers only check names that have
already reported. To rename one: remove it from `main-ci-gates`, merge the
rename once all its checks pass on the PR, then add the new name (it now
exists) and read the ruleset back.

Never force-push `main`, delete unrelated branches or use environment approval
as a branch-protection bypass. Bot-created PRs may need maintainer approval
before their real workflows run.

## Pull request checks

Publish the reviewed candidate and require genuine checks on that final PR
head. Local, previous-head and manual-dispatch successes do not substitute for
protected PR-event checks. Keep original failures and reproduce defects
locally before changing code or test expectations.

## Workflow permissions

Request the smallest scope per job:

- Validation defaults to `contents: read`; fork/bot checks need no release secrets.
- PR reporting uses `pull-requests: write` only where necessary and does not run
  PR-controlled code with privileged reporting credentials. Read-only contexts
  use job summaries instead of requiring a writable comment token.
- The compatibility failure reporter has issue-write access without source
  checkout or release credentials.
- Recovery resolution is read-only with `contents: read` and `actions: read`.
- Protected publication alone uses `contents: write`, necessary cross-run
  artifact read permission and WordPress.org environment secrets.

`SVN_USERNAME` and `SVN_PASSWORD` exist only in the protected `wordpress-org`
environment. Do not duplicate them as repository secrets or expose them to PR
code. Actions remain pinned; dependency/tool updates need ordinary review.

GitHub Actions settings, read back September 17, 2026:
`default_workflow_permissions=read` and `can_approve_pull_request_reviews=true`.
Actions tokens default to read-only; jobs request the writes they need.
Actions may create PRs, but those PRs' workflows still need maintainer
approval.

## Other GitHub controls

Last verified September 16, 2026 (repository files cannot prove live settings;
read back before the next production release):

- `wordpress-org` requires DanielBoring's approval, permits self-review for solo
  maintenance, disallows administrator bypass and accepts only `v*` tags
  (re-read September 21, 2026; see [08](08-release.md#environment-and-publication-gates)).
- SVN credentials are environment-scoped, not repository secrets.
- Private vulnerability reporting, Dependabot alerts and security updates,
  secret scanning and push protection are enabled.

## Routine maintainer approvals

- Future PRs created with `GITHUB_TOKEN` need a maintainer to approve their
  genuine PR-event workflows (**Approve workflows to run**, or authorized REST
  approvals). Past approvals are not a standing bypass.
- `workflow_dispatch` results do not satisfy required ruleset checks.
- Review and merge passing baseline PRs explicitly; approve each production
  release separately through the `wordpress-org` environment.

## Artifacts and reporting

Retain inherited main contract/MCP and dedicated parent-assignment evidence for
the configured bounded retention period. Missing required evidence is an upload
failure, not a successful summary. Report the tested commit/run, actual versions,
coverage and failure counts, debug-log state and cleanup outcome.

Product raw requests/responses, headers, SQL, credentials, context, wire frames,
resource ledgers and exception logs remain private. Upload only the closed
validated product summary, never a broad raw product-directory glob. Formatting
or exporting data cannot override a stage's original nonzero exit. Missing or
partial evidence cannot generate success-shaped fallback summaries.

Preserve failed local captures and ownership/retention records. Clear retention
only after independently verified restoration and live owned-resource absence;
otherwise refuse restart and teardown. Docker cleanup success does not turn
a failed product run into acceptance.

PR workflow concurrency may cancel superseded runs. Required aggregates
explicitly evaluate prerequisite results instead of treating implicit skips as
success. Workflow lint (a job in 01) does not prove live runtime behavior or
production approval.

## Failure handling

Fix static/baseline/manifest/security-policy failures first, then helper/unit
regressions. Distinguish actual Docker behavior from bootstrap failures and
mock safeguards. Package failures may concern metadata, production hashes,
runtime checks or Plugin Check; production failures additionally involve
approval, SVN or GitHub Release publication.

Reproduce scheduled failures before changing pins or merge policy. Supported
version failures can block release; transient infrastructure or unsupported
upstream combinations need separate triage, not silent skips.

## Issue and PR process

- Use normal issue triage and branch-based PRs; GitHub Issues is the backlog.
- Put `Closes #N`, `Fixes #N` or `Resolves #N` in the PR body when the merge
  should close an issue. GitHub's native closing handles it; there is no custom
  close workflow.

## Official references

- [GitHub continuous integration](https://docs.github.com/en/actions/get-started/continuous-integration)
- [GitHub workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
- [GitHub protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
- [GitHub deployment environments](https://docs.github.com/en/actions/reference/deployments-and-environments)
