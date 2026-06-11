# GitHub Actions Automation Guide

This repository uses GitHub Actions to automate the complete development workflow: from issue to PR to release.

## Workflows Overview

### 1. **E2E QA Testing** (`e2e-qa.yml`)
Runs automatically on every pull request push and on pushes to main/develop branches.

**What it does:**
- Spins up WordPress + MySQL using Docker Compose
- Installs MCP Adapter v0.5.0
- Creates test users (Author, Editor, Subscriber)
- Tests all media abilities for proper authorization
- Verifies existing features still work
- Comments test results directly on the PR

**Triggers:**
- `pull_request` (opened, synchronize, reopened)
- `push` to main/develop branches

**Required:** PR must pass E2E QA before merge (recommended branch protection rule)

### 2. **Auto-create PR from Issue** (`auto-pr-from-issue.yml`)
Creates a feature branch and PR when an issue is labeled `ready-for-dev`.

**What it does:**
- Listens for `ready-for-dev` label on issues
- Creates feature branch `issue-{number}`
- Creates PR with title and issue context
- Automatically links PR to issue with `Closes #X`
- Adds comment on issue confirming PR creation

**Triggers:**
- `issues: labeled` with label `ready-for-dev`

**How to use:**
1. Create/find an issue
2. Add label `ready-for-dev`
3. Workflow automatically creates `issue-{number}` PR
4. E2E QA runs automatically
5. Merge when ready

### 3. **Auto-release on Merge** (`auto-release.yml`)
Detects version changes and creates GitHub Release automatically.

**What it does:**
- Triggers when code is merged to main
- Compares current version to latest git tag
- Extracts changelog from `readme.txt`
- Creates GitHub Release with:
  - Tag name: `v{version}`
  - Release notes from changelog
  - Release asset (ZIP file)
- Comments on merged PR with release link

**Triggers:**
- `push` to main with changes to:
  - `wp-mcp-abilities.php` (version header)
  - `readme.txt` (changelog)
  - `includes/**` (ability changes)

**How versioning works:**
- Plugin version in `wp-mcp-abilities.php` header is source of truth
- Follow semantic versioning: `MAJOR.MINOR.PATCH`
  - **MAJOR** (X.0.0): breaking changes to ability names/inputs
  - **MINOR** (1.X.0): new features (backward compatible)
  - **PATCH** (1.4.X): bug/security fixes only
- Update version + add changelog entry + merge PR
- Release workflow automatically tags and publishes

### 4. **Auto-close Issue on PR Merge** (`auto-close-issue.yml`)
Automatically closes linked issues when their PR is merged.

**What it does:**
- Detects "Closes #X" in PR body
- Adds final comment to issue with merge confirmation
- Closes the issue automatically

**Triggers:**
- `pull_request: closed` (only if merged)

**Important:** PR must include `Closes #X` in body (added automatically by auto-pr-from-issue workflow)

## Complete Workflow Example

### Scenario: Add new media ability

1. **Create Issue** (e.g., "Add delete-media ability")
   - Describe requirements
   - Define acceptance criteria

2. **Label Issue** with `ready-for-dev`
   - ✅ Workflow: Auto-creates PR `issue-{number}`
   - ✅ Comment added to issue linking PR

3. **Push code to PR branch**
   - ✅ Workflow: E2E QA runs automatically
   - ✅ Workflow: Comments test results on PR
   - ✅ Tests must pass before merge

4. **Merge PR to main**
   - ✅ Workflow: Detects version bump in `wp-mcp-abilities.php`
   - ✅ Workflow: Creates GitHub Release with changelog
   - ✅ Workflow: Comments on PR with release link
   - ✅ Workflow: Closes linked issue

5. **Done!** 🎉
   - No manual tagging
   - No manual release creation
   - No manual issue closing

## Local Testing

You can also run E2E tests locally using Docker Compose:

```bash
# Start WordPress stack
docker-compose up -d

# Wait for services to be ready
sleep 10

# Run tests
./scripts/e2e-test.sh

# Clean up
docker-compose down -v
```

## Branch Protection Rules

Recommended branch protection settings for `main`:

```
Require status checks to pass before merging:
  ✓ E2E QA Testing (e2e-test)

Additional rules:
  ✓ Require pull request reviews before merging: 1 approval
  ✓ Require branches to be up to date before merging
  ✓ Include administrators: No (allows automation to bypass if needed)
  ✓ Restrict who can push to matching branches: Only allow specific accounts
```

## Troubleshooting

### E2E tests fail in CI but pass locally
- Check Docker image versions in `docker-compose.yml`
- Ensure `wp-mcp-adapter` v0.5.0 is compatible with your changes
- Review WordPress debug log in workflow output

### PR not being created from issue
- Verify issue has `ready-for-dev` label
- Check workflow permissions: needs `issues: write`, `pull-requests: write`, `contents: write`
- Review workflow logs in Actions tab

### Release not being created
- Ensure version in `wp-mcp-abilities.php` is different from last tag
- Check that `readme.txt` has changelog entry for new version
- Verify workflow has `contents: write` permission

### Auto-close not working
- Ensure PR body contains `Closes #X` (with exact capitalization)
- Check PR is merged, not just closed

## Files

| File | Purpose |
|------|---------|
| `.github/workflows/e2e-qa.yml` | E2E testing on every PR |
| `.github/workflows/auto-pr-from-issue.yml` | Create PR from labeled issue |
| `.github/workflows/auto-release.yml` | Auto-tag and release on merge |
| `.github/workflows/auto-close-issue.yml` | Auto-close issue on PR merge |
| `.github/PULL_REQUEST_TEMPLATE.md` | Pre-fill PR with checklist |
| `docker-compose.yml` | Local WordPress environment for testing |
| `scripts/e2e-test.sh` | E2E test script (runs in CI and locally) |

## Next Steps

1. Commit these files to your repository
2. Create a test issue and label it `ready-for-dev`
3. Watch the automation in action!
4. Update branch protection rules to require E2E QA passing

---

**Questions?** Check `.github/workflows/*.yml` for implementation details.
