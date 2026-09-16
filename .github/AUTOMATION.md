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
| `push` to `main` or `develop` | Static, unit, and Docker QA workflows | Re-runs the appropriate numbered checks after merge. |
| `workflow_dispatch` | Static, unit, Docker, or release workflows | Runs the selected QA layer on demand. |
| `push` tag `v*` | `.github/workflows/release.yml` | Runs release validation and `5 - Release Package QA`, waits for protected `wordpress-org` approval, deploys to WordPress.org SVN, then publishes a GitHub release. |

## Workflow details

### Docker QA (`e2e-qa.yml`)

**Execution flow**
1. Detect whether changed files are runtime-impacting.
2. If in scope, run Ability Contract QA with `scripts/e2e-test.sh contract`.
3. Run Full MCP E2E QA with `scripts/e2e-test.sh e2e`.
4. Upload Docker/WordPress failure artifacts when either Docker lane fails.
5. Post a PR comment from an isolated comment job with runtime-derived run facts, contract coverage, tested dependency versions, and changed files.
6. Always clean up Docker resources.

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

Each lane pulls fresh Docker images, runs Ability Contract QA plus Full MCP E2E QA, rejects WordPress debug-log warnings/notices/deprecations/errors, and records the resolved WordPress, PHP, MySQL, MCP Adapter, and plugin versions in the job summary. A failure therefore identifies whether the regression is at the support floor, pinned baseline, latest WordPress, latest Adapter, or combined candidate.

When one or both latest versions are newer than the pinned baselines and every compatibility lane passes, the scheduled workflow runs `scripts/update-compatibility-baselines.php` and opens a version-specific pull request. The PR updates `.github/compatibility-versions.json`, the default WordPress Docker image, and the pinned MCP Adapter ZIP. It is never auto-merged. The workflow explicitly dispatches Static QA, Unit Tests, Docker QA, and Release Package QA for the bot-created branch because events created with `GITHUB_TOKEN` do not recursively trigger workflows.

### Release (`release.yml`)

**Execution flow**
1. Trigger on tag push matching `v*`.
2. Validate `tag version == plugin header version == readme stable tag`.
3. Verify plugin release notes exist in `readme.txt` for the tagged version.
4. Run `scripts/release-qa.sh` to run contract and transport Docker QA, build the plugin ZIP, validate required/forbidden contents, and run WordPress Plugin Check against the built package.
5. Fail if a release for the same tag already exists.
6. Wait for approval in the protected `wordpress-org` GitHub Environment.
7. Deploy `build/webmastery-site-toolkit-for-mcp` to WordPress.org SVN with `SLUG=webmastery-site-toolkit-for-mcp` and SVN tag `X.Y.Z`.
8. Publish the GitHub release with the built ZIP and notes from `readme.txt`.

**WordPress.org deployment**
- The SVN deploy step uses `10up/action-wordpress-plugin-deploy` pinned to an immutable commit.
- `SVN_USERNAME` and `SVN_PASSWORD` are read from GitHub Actions secrets. Prefer `wordpress-org` environment secrets; repository-level secrets can work as a fallback when the job is still environment-gated.
- The workflow deploys code only. WordPress.org listing assets are intentionally deferred until a `.wordpress-org/` asset directory is added and reviewed.
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

## Branch protection recommendation

Require `1 - Static QA` and `2 - Unit Tests` before every merge. Require `3 - Ability Contract QA` and `4 - Full MCP E2E QA` before merging runtime-impacting PRs.

Keep `6 - Compatibility QA` scheduled/manual until the matrix is stable enough to promote selected jobs to branch protection. Manual dispatch tests versions without opening a PR unless `open_update_pr` is selected.
