# GitHub Automation

This folder contains the repository automation for issue handling, PR validation, and releases.

## What it does

- **Auto-create PRs from issues** when an issue is labeled `ready-for-dev`
- **Run E2E QA in Docker** on every pull request
- **Auto-close linked issues** when PRs are merged
- **Auto-create releases** when versioned changes land on `main`

## Main files

| File | Purpose |
|------|---------|
| `.github/workflows/auto-pr-from-issue.yml` | Creates a PR from a labeled issue |
| `.github/workflows/e2e-qa.yml` | Runs Docker-based E2E QA on PRs |
| `.github/workflows/auto-close-issue.yml` | Closes linked issues after merge |
| `.github/workflows/auto-release.yml` | Publishes releases from versioned merges |
| `.github/PULL_REQUEST_TEMPLATE.md` | PR checklist and linking reminder |
| `.github/AUTOMATION.md` | Full automation guide |
| `.github/SETUP-COMPLETE.md` | High-level summary of the setup |

## How it flows

1. Create or label an issue with `ready-for-dev`.
2. The PR automation creates a branch and links the issue.
3. E2E QA runs automatically on PR updates.
4. Merge the PR when checks pass.
5. The issue closes and the release workflow publishes the new version if needed.

