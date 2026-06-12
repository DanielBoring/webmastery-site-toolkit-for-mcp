# GitHub Actions Automation Guide

This repository uses GitHub Actions for two purposes:
1. Docker-based E2E QA validation.
2. Tag-based release publishing.

## Trigger map (event -> workflow -> behavior)

| Event | Workflow file | Behavior |
|------|------|------|
| `pull_request` (`opened`, `synchronize`, `reopened`) | `.github/workflows/e2e-qa.yml` | Detects relevant changed files, runs E2E QA when in scope, and posts an accurate PR comment (pass/fail). |
| `push` to `main` or `develop` | `.github/workflows/e2e-qa.yml` | Detects relevant changed files and runs E2E QA when in scope. |
| `workflow_dispatch` | `.github/workflows/e2e-qa.yml` | Runs E2E QA on demand. |
| `push` tag `v*` | `.github/workflows/release.yml` | Validates plugin version/release-note alignment, builds and validates release ZIP, then publishes a GitHub release. |

## Workflow details

### E2E QA (`e2e-qa.yml`)

**Execution flow**
1. Detect whether changed files are in E2E scope.
2. If in scope, start Docker stack and run `scripts/e2e-test.sh`.
3. Run manifest-driven ability coverage from `tests/e2e/abilities-manifest.json`.
4. Fail if a registered `unlock-mcp-potential/*` ability is missing from the manifest or if the manifest references an unregistered ability.
5. Write `e2e-artifacts/e2e-summary.json` with registered ability, covered ability, test case, and negative case counts.
6. Validate WordPress debug log for fatal/error-level entries.
7. Upload E2E failure artifacts when the test fails.
8. Post truthful PR status comment from an isolated comment job, including coverage and tested dependency versions.
9. Always clean up Docker resources.

**Ability coverage contract**
- Every new `unlock-mcp-potential/*` ability must add coverage in `tests/e2e/abilities-manifest.json`.
- CI fails when registered abilities are not covered by the manifest.
- CI fails when manifest entries reference abilities that are no longer registered.
- For permission-sensitive abilities, include both allowed and denied role cases where practical.
- See `tests/e2e/README.md` for the manifest format and update rules.

**Security model**
- E2E execution jobs run with read-only permissions.
- PR commenting is isolated to a dedicated job with comment-only write scope.
- Actions are pinned to immutable SHAs.

### Release (`release.yml`)

**Execution flow**
1. Trigger on tag push matching `v*`.
2. Validate `tag version == plugin header version == readme stable tag`.
3. Verify plugin release notes exist in `readme.txt` for the tagged version.
4. Build plugin ZIP and validate required/forbidden contents.
5. Fail if a release for the same tag already exists.
6. Publish release with notes from `readme.txt`.

## Issue and PR process

- Use normal issue triage and branch-based PR flow.
- Include `Closes #N` / `Fixes #N` / `Resolves #N` in PR body when merge should close an issue.
- GitHub native issue closing handles closure on merge; no custom close workflow is used.

## Local E2E testing

```bash
docker compose up -d
bash scripts/e2e-test.sh
docker compose down -v
```

## Branch protection recommendation

Require the E2E QA check before merging to `main`.
