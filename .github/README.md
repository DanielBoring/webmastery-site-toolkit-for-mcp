# GitHub Automation

This folder contains the repository automation for pull request QA and release publishing.

## What it does

- Runs Docker-based E2E QA for relevant pull request and push changes.
- Publishes releases from version tags using the tag-based release workflow.
- Uses native GitHub issue-closing keywords in PR bodies (`Closes #N`, `Fixes #N`, `Resolves #N`) instead of custom close automation.

## Trigger map (what triggers what)

| Event | Workflow file | What happens |
|------|---------|---------|
| Pull request opened/synchronized/reopened | `.github/workflows/e2e-qa.yml` | Detects relevant file changes and runs Docker E2E QA when in scope; posts truthful PR comment with pass/fail result. |
| Push to `main` or `develop` | `.github/workflows/e2e-qa.yml` | Detects relevant file changes and runs Docker E2E QA when in scope. |
| Manual run (`workflow_dispatch`) | `.github/workflows/e2e-qa.yml` | Forces an on-demand E2E QA run. |
| Push tag matching `v*` | `.github/workflows/release.yml` | Validates version/changelog consistency, builds release ZIP, validates artifact contents, and publishes GitHub release. |

## Main files

| File | Purpose |
|------|---------|
| `.github/workflows/e2e-qa.yml` | Runs Docker-based E2E QA with scoped execution and accurate PR reporting |
| `.github/workflows/release.yml` | Creates tag-based GitHub releases after validation gates pass |
| `.github/PULL_REQUEST_TEMPLATE.md` | PR checklist and issue-linking reminder |
| `.github/AUTOMATION.md` | Detailed workflow behavior and contributor process |
| `.github/SETUP-COMPLETE.md` | High-level setup summary |

## Contributor flow

1. Create and scope an issue.
2. Create a branch and open a PR with `Closes #N` only when merge should close that issue.
3. Ensure E2E QA passes.
4. Merge PR.
5. Create/push version tag (`vX.Y.Z`) when ready to publish release.
