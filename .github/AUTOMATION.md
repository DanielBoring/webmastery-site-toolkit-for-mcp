# GitHub Actions Automation Guide

This repository uses GitHub Actions for layered WordPress.org plugin QA plus tag-based release publishing. See [`docs/ci-cd-strategy.md`](../docs/ci-cd-strategy.md) for the automation strategy, [`docs/qa-strategy.md`](../docs/qa-strategy.md) for QA evidence, [`docs/security-strategy.md`](../docs/security-strategy.md) for security policy, and [`docs/release-strategy.md`](../docs/release-strategy.md) for release governance.

## Trigger map (event -> workflow -> behavior)

| Event | Workflow file | Behavior |
|------|------|------|
| `pull_request` (`opened`, `synchronize`, `reopened`) | `.github/workflows/coding-standards.yml` | Runs `1 - Static QA` on every PR. |
| `pull_request` (`opened`, `synchronize`, `reopened`) | `.github/workflows/unit-tests.yml` | Runs `2 - Unit Tests` on every PR. |
| `pull_request` (`opened`, `synchronize`, `reopened`) | `.github/workflows/e2e-qa.yml` | Detects runtime-impacting files, then runs `3 - Ability Contract QA` and `4 - Full MCP E2E QA` when in scope. |
| release-impacting `pull_request` or `workflow_dispatch` | `.github/workflows/release-package-qa.yml` | Runs `5 - Release Package QA` without publishing a GitHub release. |
| `schedule` or `workflow_dispatch` | `.github/workflows/compatibility-qa.yml` | Discovers current upstream versions, runs `6 - Compatibility QA` against baseline and candidate combinations, and opens a reviewed baseline-update PR after successful scheduled candidate tests. |
| `workflow_dispatch` | `.github/workflows/import-issue-backlog.yml` | Previews or imports Markdown files from `ISSUES/` as GitHub Issues, preserving titles, labels, and bodies while skipping duplicates. |
| `push` to `main` | Static, unit, and Docker QA workflows | Re-runs the appropriate numbered checks after merge. |
| `workflow_dispatch` | Static, unit, Docker, or release-package QA workflows | Runs the selected QA layer on demand; does not publish or satisfy required PR checks. |
| `pull_request`, `push` to `main`, `workflow_dispatch` | Workflow lint | Runs actionlint, ShellCheck, and zizmor separately from PHP-only local QA. |
| `push` tag `v*` | `.github/workflows/release.yml` | Requires main ancestry and static/unit QA, validates the package, waits for `wordpress-org` approval, and publishes the validated artifact. |

## Workflow details

### Docker QA (`e2e-qa.yml`)

**Execution flow**
1. Detect whether changed files are runtime-impacting.
2. If in scope, run Ability Contract QA with `scripts/e2e-test.sh contract`.
3. Run Full MCP E2E QA with `scripts/e2e-test.sh e2e`.
4. Always publish available JSON summaries with bounded retention; collect detailed logs on failure.
5. Post a PR comment from an isolated, checkout-free job only when the PR context permits writes. Fork and read-only bot runs retain job summaries.
6. Always clean up the run's disposable Docker resources.
7. Report `Docker QA gate`: require successful change detection and both runtime jobs, or positively identified non-runtime changes with intentionally skipped jobs. Failed detection, invalid outputs, and failure-induced skips cannot pass the gate.

**PR comment data sources**
- Result and workflow URL come from the current workflow run.
- PR head commit uses `github.event.pull_request.head.sha`; tested merge commit uses `github.sha`, which is GitHub's synthetic merge commit for pull request runs.
- Ability coverage, manifest case totals, passed cases, failed cases, and negative permission cases come from `e2e-artifacts/e2e-summary.json`, written by `tests/e2e/ability-runner.php`.
- WordPress, PHP, MySQL, MCP Adapter, Yoast SEO, SEOPress, and plugin versions are collected from the live Docker/WordPress runtime after E2E runs.
- Changed files come from the workflow's changed-file detection job.
- Debug log status comes from the WordPress debug log scan step.

**Ability coverage contract**
- Every new `webmastery-site-toolkit-for-mcp/*` ability must add coverage in `tests/e2e/abilities-manifest.json`.
- CI fails when registered abilities are not covered by the manifest.
- CI fails when manifest entries reference abilities that are no longer registered.
- For permission-sensitive abilities, include both allowed and denied role cases where practical.
- Security-sensitive abilities must keep negative cases and sensitive-field absence assertions required by `composer validate:security-qa`.
- See `tests/e2e/README.md` for the manifest format and update rules.

**Security model**
- E2E execution jobs run with read-only permissions.
- PR commenting is isolated to a dedicated job with comment-only write scope and no repository checkout.
- Actions are pinned to immutable SHAs.
- Static QA blocks risky `permission_callback => '__return_true'` ability registrations unless they are explicitly reviewed and allow-listed.

### Compatibility QA (`compatibility-qa.yml`)

The weekly/manual compatibility matrix reads pinned versions from `.github/compatibility-versions.json`, queries the official WordPress version API and the latest stable MCP Adapter GitHub release, then isolates the main upstream change surfaces:

- WordPress 6.9 and PHP 8.1 with the pinned MCP Adapter baseline near the plugin support floor
- the exact pinned WordPress and MCP Adapter baselines
- the pinned WordPress baseline with the latest stable MCP Adapter release
- the latest stable WordPress release with the pinned MCP Adapter baseline
- a combined-latest lane only when both upstream projects release newer versions in the same weekly interval

Additional lanes exercise PHP 8.4, MySQL 8.4, current SEO dependencies, and current Plugin Check against the package. PHP 8.0 remains the advertised plugin minimum; PHP 8.0 syntax/unit checks are not WordPress integration evidence for that floor. See the runtime coverage limitation in `docs/qa-strategy.md`.

Runtime lanes pull images, run Ability Contract QA plus Full MCP E2E QA, reject debug-log warnings/notices/deprecations/errors, and record tested versions. An unavailable candidate image is an infrastructure failure, not permission to promote an untested baseline.

After all required candidate checks pass, `scripts/update-compatibility-baselines.php` updates the schema-preserving baseline configuration, concrete runtime references, and `readme.txt` `Tested up to`. Baseline PRs are never auto-merged. Changed main history, stale candidate branches, and closed PRs require explicit handling rather than overwriting earlier decisions. The run summary explains promotion or the reason it did not happen; persistent scheduled failures update one tracking issue.

The repository permits Actions to create PRs. PRs created with `GITHUB_TOKEN` generate approval-required PR-event runs: a maintainer must select **Approve workflows to run**. Those checks, not dispatched runs, must satisfy branch protection. A GitHub App is an alternative if automatic check startup becomes necessary; no long-lived token is required for the current flow.

### Release (`release.yml`)

**Execution flow**
1. Trigger on tag push matching `v*`.
2. Require an exact `vX.Y.Z` tag whose commit is an ancestor of freshly fetched `main`; run `composer qa`.
3. Validate source and archive metadata, changelog/upgrade entries, support requirements, and tested-baseline consistency.
4. Build once, run Docker QA and Plugin Check, and retain the ZIP, release notes, listing assets, source SHA, and integrity manifest in a run-scoped artifact.
5. Wait for approval in the protected `wordpress-org` environment.
6. Serialize production publication across release tags, download and verify the artifact, and reject version regression or mismatched existing release contents.
7. Deploy the package extracted from that ZIP to SVN and publish that same ZIP to GitHub with build provenance.
8. Verify WordPress.org publication. Report partial publication explicitly if SVN succeeds but listing confirmation or GitHub publication fails; recover from the existing validated artifact without rewriting tags.

**WordPress.org deployment**
- The SVN deploy step uses `10up/action-wordpress-plugin-deploy` pinned to an immutable commit.
- `SVN_USERNAME` and `SVN_PASSWORD` are stored only in the protected `wordpress-org` environment. Do not duplicate them as repository secrets.
- The workflow deploys the curated package and supported listing assets from `.wordpress-org/`. Unrecognized files such as `logo.png` are not Plugin Directory assets and must not enter the deploy artifact.
- WordPress.org SVN is production. Use release package QA, compatibility QA, and staging WordPress installs for test coverage rather than a separate WordPress.org test SVN.

## Issue and PR process

- Use normal issue triage and branch-based PR flow.
- Backlog Markdown files under `ISSUES/` are source documents; merging them does not create GitHub Issues automatically.
- Run `7 - Issue Backlog Import` manually with `dry_run` enabled to preview candidates. Re-run it with `dry_run` disabled to create them.
- The importer uses each file's `title` and `labels` front matter, strips the front matter from the issue body, and adds an invisible source marker. Existing issues with the same marker or exact title are skipped, so reruns are safe.
- Include `Closes #N` / `Fixes #N` / `Resolves #N` in PR body when merge should close an issue.
- GitHub native issue closing handles closure on merge; no custom close workflow is used.

## Local Docker QA

```bash
docker compose up -d
bash scripts/e2e-test.sh contract
bash scripts/e2e-test.sh e2e
docker compose down -v
```

The E2E bootstrap installs and activates Yoast SEO and SEOPress from WordPress.org. Current SEO ability assertions remain Yoast-backed, while SEOPress is active during the run to exercise dependency readiness and coexistence guardrails.

## Active main CI enforcement

Require the stable checks `1 - Static QA`, `2 - Unit Tests`, and `Docker QA gate` from GitHub Actions before every merge. The Docker gate enforces the runtime-dependent checks without allowing a failed prerequisite to appear as an intentional skip. Do not require workflow-level path-filtered Release Package QA.

The `main-ci-gates` ruleset (23522901) is **active**, verified on September 17, 2026. Under explicit maintainer authorization, enforcement was activated only after all five genuine PR-event workflows for bot PR #140 passed. It targets only `main`, has no excluded refs or bypass actors, and binds exactly the three checks above to GitHub Actions integration 15368. Only enforcement changed; both `strict_required_status_checks_policy` and `do_not_enforce_on_create` remain `false`. See [SETUP-COMPLETE.md](SETUP-COMPLETE.md) for read-back details, public run evidence, and separately dated settings.

Initial rollout is complete, but routine approvals remain: future `GITHUB_TOKEN`-created PRs need maintainer workflow approval, and baseline merges and production releases need their own review. PR #140 was open and unmerged at verification; its passing MCP Adapter 0.6.1 candidate does not replace the 0.5.0 baseline on `main` at `3dae8aa`.

Keep `6 - Compatibility QA` scheduled/manual until the matrix is stable enough to promote selected jobs to branch protection. Manual dispatch tests versions without opening a PR unless `open_update_pr` is selected.
