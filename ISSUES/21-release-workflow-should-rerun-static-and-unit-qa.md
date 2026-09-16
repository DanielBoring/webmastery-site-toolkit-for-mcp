---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Make `release.yml` depend on static + unit QA, add required status checks and a tag ruleset'
labels: enhancement, priority: medium
assignees: ''
---

**Proposed ability name**
.github/workflows/release.yml + repository rulesets; no ability involved

**What should it do?**
1. **Required status checks.** From the public rulesets page: `main-pr-no-required-approvals` (active, target `main`) requires a pull request but lists **no required status checks**, and `bypass-rule` (active, all branches) only restricts deletions and blocks force-pushes; tags are unprotected. Add the "1 - Static QA", "2 - Unit Tests", "3 - Ability Contract QA" and "4 - Full MCP E2E QA" job names as required checks on the `main` ruleset (`strict` up-to-date optional), and add a `v*` tag ruleset that restricts creation to bypass actors.
2. **`release.yml` gate.** `release.yml:88-89` runs only `scripts/release-qa.sh` (build ZIP, `validate-release-package.php`, Docker E2E, Plugin Check) — never `composer qa:static` or `composer qa:unit`. Add a `qa` job (checkout → setup-php → `composer install` → `composer qa`) and make `release-qa` `needs: qa`; optionally add `git fetch origin main && git merge-base --is-ancestor "$GITHUB_SHA" origin/main` to "Validate release inputs" so tags must point at `main` history.
3. Move `${{ steps.validate.outputs.version }}` at `release.yml:89` into `env:` (it is provably `[0-9]+\.[0-9]+\.[0-9]+` after `:61-68`, so not injectable today; this is consistency with the rest of the workflow).
4. Pin and checksum-verify the `wp-cli.phar` download in `scripts/e2e-test.sh:72` (wp-cli publishes sha512 files); the same container later builds the package in `scripts/release-qa.sh:45-49`.
5. Add `.github/dependabot.yml` (issue 15) so the audit failure class is caught before it reaches the gate.

**Required WordPress capability**
n/a (repository settings and workflow permissions; no plugin capability).

**Why does an AI agent need this?**
The QA gates are excellent on PRs; the release path and the merge path are the two places they are not enforced. A `v*` tag pushed from any commit — including one that never passed Static QA — publishes to WordPress.org after the environment approval.

**Relevant code**
- .github/workflows/release.yml:9-89` — `release-qa` job; `:91-164` `publish` job (environment-gated, secrets scoped correctly).
- `.github/workflows/coding-standards.yml`, `unit-tests.yml`, `e2e-qa.yml` — the checks to require.
- `scripts/release-qa.sh:28-49` — what the release actually runs.
- `scripts/e2e-test.sh:72` — unpinned `wp-cli.phar` fetch.
- `docs/ci-cd-strategy.md` — states branch protection expectations; update to match the rulesets.

**Additional context**
- Everything else in the six workflows checked out: SHA-pinned actions with version comments, least-privilege `permissions`, environment-gated publish with `SVN_*` secrets only in that job, PR data passed via `env:`, package-content validation, duplicate-release guard.
- Verified on github.com: rulesets `bypass-rule` (#17568191) and `main-pr-no-required-approvals` (#17568280).
- Repository-facing change: `.github/REPOSITORY_CHANGELOG.md`; update `docs/ci-cd-strategy.md` and `.github/AUTOMATION.md`.

- Assessment finding: **B-6.2** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `15-composer-audit-fails-on-dev-dependency-advisories.md`.
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
