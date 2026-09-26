# CI/CD Strategy

This repository uses GitHub Actions as the automation layer for pull request checks, Docker WordPress validation, scheduled compatibility checks, and release publishing. The CI/CD strategy explains how automation is organized; the QA strategy explains what confidence each check provides.

See the [Software Development Lifecycle](sdlc-overview.md) for where CI/CD connects the stages of the development process.

## Goals

1. Keep the default pull request path fast enough for routine contribution.
2. Require stronger evidence for runtime, ability, security-sensitive, and release-impacting changes.
3. Keep workflow permissions narrow and explicit.
4. Make every required status check name unique and stable for branch protection.
5. Preserve enough artifacts and PR comments to debug failures without rerunning everything locally.

## Workflow inventory

| Workflow | Primary trigger | Purpose |
| --- | --- | --- |
| `1 - Static QA` | `pull_request`, `push`, `workflow_dispatch` | PHP lint, WPCS, PHPStan, Composer audit, E2E manifest validation, security QA validation, and diff whitespace checks. |
| `2 - Unit Tests` | `pull_request`, `push`, `workflow_dispatch` | Fast PHPUnit helper tests. |
| `3-4 - Docker QA` | `pull_request`, `push`, `workflow_dispatch` | Runtime-impact detection, Ability Contract QA, Full MCP E2E QA, failure artifacts, and PR comment summaries. |
| `5 - Release Package QA` | release-impacting `pull_request`, `workflow_dispatch` | Contract + transport Docker QA, package validation, and WordPress Plugin Check without publishing. |
| `5 - Release` | release-tag push; guarded recovery `workflow_dispatch` | Normal tags require main ancestry and Static/Unit/package QA. Recovery requires an annotated `v-release-recovery-*` control tag and reuses original successful QA evidence. Both use one protected, serialized publish job; control-tag pushes do not release. |
| `6 - Compatibility QA` | weekly `schedule`, `workflow_dispatch` | Discovers official upstream releases, tests baseline and candidate WordPress/MCP Adapter combinations, and opens a reviewed baseline-update PR after successful scheduled tests. |
| `7 - Issue Backlog Import` | `workflow_dispatch` | Previews or imports issue backlog files; uses a serialized, dry-run-first workflow. |
| Workflow lint | `pull_request`, `push` to `main`, `workflow_dispatch` | Checks Actions syntax, shell scripts, and workflow security with pinned lint tools. |

## Pull request policy

All pull requests should pass:

1. `1 - Static QA`
2. `2 - Unit Tests`

Runtime, ability, and security-sensitive pull requests should also pass:

1. `3 - Ability Contract QA`
2. `4 - Full MCP E2E QA`

`Docker QA gate` is the stable required result for both runtime and non-runtime PRs. It rejects failed/cancelled change detection, malformed detection output, and failed/missing runtime results. It accepts skipped runtime jobs only after successful detection explicitly identifies non-runtime changes.

Release-impacting pull requests should pass:

1. `5 - Release Package QA`

Compatibility QA starts as scheduled/manual. Promote matrix jobs to branch protection only after they are stable and low-noise.

## Branch protection

The active `main-ci-gates` ruleset requires unique status check names before merge. GitHub warns that duplicate job names across workflows can create ambiguous status checks, so workflow and job display names should remain stable and unique.

Recommended `main` protection:

1. Require pull request before merge.
2. Require `1 - Static QA` and `2 - Unit Tests`.
3. Require `Docker QA gate`, which enforces both runtime checks when needed.
4. Require conversation resolution.
5. Require linear history if repository settings continue to disallow merge commits.
6. Do not allow force pushes or branch deletion.

The required-check names refer to stable aggregate jobs, not matrix-expanded names. Bind them to the GitHub Actions app. Do not require the path-filtered Release Package QA workflow unless it is redesigned to always report a final result.

On September 17, 2026, `main-ci-gates` (23522901) was activated with explicit maintainer authorization after successful genuine PR-event checks on Actions-created baseline PR #140. Read-back of the ruleset, effective `main` rules, and required PR checks confirmed enforcement. It applies only to `refs/heads/main`, with no exclusions or bypass actors, and requires exactly `1 - Static QA`, `2 - Unit Tests`, and `Docker QA gate`, each bound to GitHub Actions integration 15368. Only enforcement changed; `strict_required_status_checks_policy=false` and `do_not_enforce_on_create=false` remain unchanged.

The initial CI rollout is complete. See the [dated setup and rollout evidence](../.github/SETUP-COMPLETE.md#rollout-evidence) for the passing compatibility pipeline and all five approved PR-event workflows. At that verification, PR #140 was still open: MCP Adapter 0.6.1 was a tested candidate, while `main` at `3dae8aa` retained 0.5.0. The checked-in adapter baseline is now 0.6.1. Future bot PR workflow approvals, current-source baseline validation and merge, and production release approval remain separate maintainer decisions.

The production environment, main-history/PR rules, and tag protections were last read back on September 16, 2026; the September 17 CI activation check did not reverify those settings or establish completion of unrelated safeguards work.

## Workflow permissions

Use least privilege per job:

- Read-only jobs use `contents: read`.
- PR comment jobs use `pull-requests: write` and avoid checking out PR-controlled code.
- Release publishing uses `contents: write` only in the protected publish job of the tag release workflow.
- Recovery resolution is read-only (`contents: read`, `actions: read`). The protected publisher also has `actions: read` for an explicit-token cross-run artifact download; only the original validated run/artifact IDs may be used.
- Secrets should not be needed for PR validation from forks. Read-only fork/bot contexts use job summaries instead of requiring a writable PR comment token.
- Actions tokens default to read-only; jobs request only their necessary scopes. The isolated compatibility failure reporter has issue-write access, not access to checkout or release credentials.
- WordPress.org SVN credentials are named `SVN_USERNAME` and `SVN_PASSWORD` and stored only in the protected `wordpress-org` environment. They become available after approval.

## Environment gates

The `wordpress-org` GitHub Environment protects **Publish release (GitHub approval required)**. A ready-for-approval summary from normal Release QA or the recovery resolver identifies the exact release/source/run/attempt/artifact before the protected job starts. This is GitHub production approval by DanielBoring, not WordPress.org staff review. The environment ID remains `wordpress-org` and can still appear in **Review deployments**; its established secrets, reviewer and tag policy are unchanged.

Recommended `wordpress-org` environment settings:

1. Require manual approval before deployment.
2. Restrict secrets to the environment when possible.
3. Store the WordPress.org SVN-specific password, not the normal account password.
4. Treat approval as confirmation that the validated tag should publish to WordPress.org production SVN.

As read back again on September 21, 2026, the configured reviewer is DanielBoring, with self-review allowed for solo maintenance and administrator environment bypass disabled. Only tags matching `v*` may deploy. The tag ruleset separately restricts creation/update/deletion to repository administrators; it does not grant permission to bypass environment approval.

Recovery cannot dispatch from `main`: checkout of an original tag does not change the workflow's deployment ref. It requires a separately authorized annotated `v-release-recovery-*` control tag on reviewed main history, excludes that namespace from normal tag-push releases, and rejects branch or ordinary-release-tag dispatch. The control tag selects only reviewed CI logic; publication uses the original successful QA source/run/artifact. The publish host explicitly installs and verifies Subversion before any SVN preflight. See the [normal/recovery runbook](release-strategy.md#recovery-when-the-frozen-workflow-itself-needs-a-fix).

There is no separate WordPress.org test SVN. Use pull request checks, `5 - Release Package QA`, manual compatibility checks, and staging WordPress installs for pre-production confidence.

## Scheduling policy

Scheduled jobs should detect drift that a PR did not cause:

- `6 - Compatibility QA` runs weekly to query the official WordPress version API and latest stable MCP Adapter GitHub release, then catches upstream WordPress, PHP, MCP Adapter, Yoast SEO, SEOPress, Plugin Check, and Docker image changes.
- Compatibility lanes pull fresh Docker images and record resolved runtime versions in the job summary so failures can be attributed to the support floor, pinned baseline, latest WordPress, latest MCP Adapter, or a combined update.
- Passing newer versions produce a version-specific pull request that updates concrete pins but never auto-merges. `GITHUB_TOKEN` PRs need maintainer approval of their real PR-event workflows. Dispatched checks do not satisfy branch-ruleset requirements.
- Candidate image availability and all required lane results must be confirmed before promotion. An unavailable image blocks the affected update rather than silently skipping it.
- Scheduled failures should create maintainer follow-up work only after triage confirms the failure is not transient infrastructure noise.
- Compatibility failures are release blockers only when they affect the supported floor, current WordPress line, or another supported version combination.

For an independently reviewed frozen source, the same registered compatibility workflow has an explicit `candidate_sha` dispatch path. It runs only pinned WordPress 6.9/PHP 8.1 full E2E on that exact commit, with read-only repository permissions and separately pinned workflow/evidence tools; it never substitutes current main or discovered dependency pins. Candidate mode rejects `open_update_pr=true` and excludes discovery, the ordinary matrix/checker/promotion chain, and the issue-writing reporter. Empty `candidate_sha` retains the existing scheduled/manual pipeline and its default-branch freshness guards. See [exact-candidate floor execution](../tests/e2e/README.md#exact-candidate-supported-floor-ci) for inputs and retained source/runtime/cleanup evidence. This ordinary hosted QA needs no private evidence infrastructure; it does not replace PR checks, original-package QA, or the large-library benchmark's unchanged numerical and private-custody requirements.

## Artifact and reporting policy

Docker jobs retain available contract/MCP summary JSON on success and failure, with bounded retention, and write readable job summaries.

Parent-assignment regressions also retain dedicated detailed JSON for seven
days on success or failure: direct-callback and ability-wrapper reports from
Contract QA, and an actual HTTP report from Full MCP E2E QA. These include
fixture-only before/after database state and write-hook observations, not live
site data. Missing expected parent evidence is an artifact-upload error, not a
successful substitute summary.

Collect detailed diagnostics on failure:

- Docker Compose logs
- WordPress debug log
- E2E summary JSON when available
- MCP CRUD summary JSON when available

PR comments should summarize runtime facts rather than static success claims:

- tested commit
- workflow run
- ability coverage counts
- pass/fail counts
- debug-log status
- tested WordPress/PHP/MySQL/plugin versions

Cancel superseded PR runs using workflow/ref-scoped concurrency. Production publication uses one shared group across tags and never cancels an active publish. Required checks explicitly evaluate prerequisite results instead of relying on implicit skipped-job success.

Pipeline linting is a separate opt-in local toolchain and GitHub workflow; PHP-only `composer qa` does not acquire a Docker dependency. Lint success does not prove live environment protections, bot PR permissions, or release provenance policy.

## Failure handling

1. Fix `1 - Static QA` failures first; they are usually syntax, standards, static-analysis, security-policy, dependency, manifest, or whitespace issues.
2. Fix `2 - Unit Tests` before Docker failures; unit failures often indicate helper contract drift.
3. For Docker failures, inspect the PR comment and uploaded artifacts.
4. For release failures, distinguish package metadata/content failures, Plugin Check failures, protected environment approval issues, SVN deployment failures, and GitHub Release creation failures.
5. For scheduled compatibility failures, reproduce manually before changing required branch protection.

Compatibility metadata generation uses the same updater CLI in the current Plugin Check and proposed-commit jobs. Both pass all eight version/digest options, including `--mcp-adapter-sha256` and `--wp-cli-sha512`, followed by `--confirmed-wordpress`. A parser failure here occurs before Plugin Check executes; passing runtime lanes alone is not a passing checker or promotion.

Run `composer qa:unit -- --filter CompatibilityBaselinesTest` for the local regression. It launches the actual CLI with workflow-shaped argv in disposable fixture roots, checks both workflow call sites, verifies promotion and no-op output, and confirms invalid/duplicate/unknown options and invalid references leave all baseline files unchanged. These tests also run in `composer qa`; they do not promote repository pins or replace the required runtime/package and real PR-event evidence.

## Official references

- GitHub Docs: [Continuous integration](https://docs.github.com/en/actions/get-started/continuous-integration)
- GitHub Docs: [Workflow syntax for GitHub Actions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
- GitHub Docs: [About protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
- GitHub Docs: [Secure use reference](https://docs.github.com/en/actions/reference/security/secure-use)
- GitHub Docs: [About releases](https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases)
