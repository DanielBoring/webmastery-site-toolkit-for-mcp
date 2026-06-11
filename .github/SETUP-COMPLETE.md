# GitHub Automation Setup Summary

The repository automation has been consolidated and hardened around two workflows:

1. **E2E QA** (`.github/workflows/e2e-qa.yml`)
2. **Tag-based Release** (`.github/workflows/release.yml`)

Removed workflows:
- `.github/workflows/auto-pr-from-issue.yml`
- `.github/workflows/auto-close-issue.yml`
- `.github/workflows/auto-release.yml`

## Trigger map

| Event | Workflow | Result |
|------|------|------|
| Pull request opened/synchronized/reopened | `e2e-qa.yml` | Runs changed-file detection and executes Docker E2E QA when in scope. |
| Push to `main` or `develop` | `e2e-qa.yml` | Runs changed-file detection and executes Docker E2E QA when in scope. |
| Manual dispatch | `e2e-qa.yml` | Executes on-demand E2E QA run. |
| Push tag `v*` | `release.yml` | Validates versions/changelog, builds artifact, validates contents, publishes release. |

## Contributor flow

1. Create issue.
2. Create branch and open PR.
3. Include `Closes #N` in PR body when merge should close the issue.
4. Merge after E2E passes.
5. Push release tag (`vX.Y.Z`) when publishing.

## Notes

- Issue closure uses native GitHub closing keywords (no custom close workflow).
- See `.github/AUTOMATION.md` for detailed behavior and security model.
