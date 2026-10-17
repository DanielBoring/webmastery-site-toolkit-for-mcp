# Agent Instructions

Use this workflow for code, documentation, ability, and release-readiness changes in this repository.

## Branch freshness preflight

Before starting issue or PR work, reduce avoidable merge conflicts:

1. Fetch the default branch with `git fetch origin main`.
2. Check whether the current branch is based on the latest `origin/main` and whether its upstream still exists.
3. If the branch is stale or its upstream is gone, update it from `origin/main` or start a fresh issue-specific branch before editing.
4. Before opening a PR, fetch `origin main` again and confirm the branch is mergeable against the current base.

## Release-readiness workflow

When asked to add, change, or release abilities, complete all applicable steps before the final response.

1. Audit abilities
   - Compare all registered `webmastery-site-toolkit-for-mcp/*` abilities against `tests/e2e/abilities-manifest.json`.
   - Ensure each registered ability has at least one manifest case.
   - For permissioned abilities, include allowed and denied role or capability cases.

2. Update documentation
   - Update `README.md` for user-facing behavior, role requirements, ability tables, verification examples, and security notes.
   - Update `readme.txt` for WordPress.org-facing description, FAQ, changelog, and upgrade notice when applicable.
   - Update related markdown files such as `tests/e2e/README.md`, `CONTRIBUTING.md`, and `.github/PULL_REQUEST_TEMPLATE.md`.

3. Update changelogs
   - Add plugin-facing unreleased work under `CHANGELOG.md` `## Unreleased`.
   - Keep `CHANGELOG.md` focused on plugin release notes: changes that affect plugin users, MCP tool compatibility, WordPress behavior, packaged plugin functionality, security, or bug fixes.
   - Add repository, CI, contributor, GitHub platform, template, or agent workflow changes under `.github/REPOSITORY_CHANGELOG.md` `## Unreleased`.
   - Use user-facing ability descriptions or short ability labels in plugin release notes; avoid raw internal ability namespace strings unless the exact MCP tool name is necessary for compatibility notes.
   - Do not put new work directly under a released version unless preparing that release section.

4. Validate contribution guidance
   - Keep `.github/PULL_REQUEST_TEMPLATE.md` and `CONTRIBUTING.md` in sync.
   - The PR checklist must cover capability checks, sanitization, response shape, E2E manifest coverage, docs, plugin/repository changelog selection, PHPCS, E2E QA, and WordPress.org guideline impact where relevant.

5. Validate
   - When running locally, run available checks in the local checkout before finishing:
     - `composer phpcs`
     - E2E workflow or manifest validation
     - `git diff --check`
   - Expect GitHub Actions to run the authoritative PR checks again after push or PR creation.
   - Run development runtime QA where this session executes: local Docker for local app/CLI sessions, cloud containers or Actions for cloud-agent sessions. Use `scripts/qa-run.sh all` for plugin changes, and add `scripts/qa-run.sh package` when ZIP inputs change; do not silently dispatch cloud QA from a local session.
   - Run `composer qa:tooling` when you change `scripts/` or `tests/` tooling; `composer qa` covers product unit tests only.
   - Keep product/package results separate from `scripts/qa-run.sh publishing`. `scripts/qa-run.sh release` preserves combined release requirements. Record blocked checks, actual engine/interpreter versions, and unverified Podman configurations; do not provision a Podman machine without user approval.
   - If PHP, Composer, Docker, or another required tool is unavailable locally, state that clearly in the final response.

6. Keep QA proportionate
   - Respect the CI budgets in `docs/00-sdlc-overview.md#ci-budgets-and-pruning`. If a change would push PR checks or the release job over budget, say so and propose moving work off the critical path (nightly, change-scoped or parallel) instead of adding serial steps.
   - A new gate, safeguard or QA-tooling test must name the specific failure it prevents. Do not add a check that duplicates one that already catches the same failure.
   - Propose removing gates and tooling tests that have not failed in a quarter, as readily as you propose adding them. Prefer readable policy tests over byte-for-byte or digest freezes of workflow files.
   - Workflows and docs share one numbering: `.github/workflows/NN-name.yml` is named `NN - Name` and described by `docs/NN-name.md`. When you change a workflow's triggers, jobs or gate, update that doc's "What the workflow does" section and, if the flow changed, `docs/images/*.drawio` and its exported PNG in the same PR. Ruleset changes go in `.github/rulesets/*.json` in the same PR; the maintainer applies them in the GitHub UI, then `11 - Ruleset Audit` must pass (see `docs/91-ci-cd-platform.md#changing-a-ruleset`).
   - Keep PR descriptions short: what changed and why, then the commands you ran and their results. Leave detailed evidence in CI logs and artifacts.

7. Final response
   - Summarize changed files and the meaningful outcome.
   - Mention any validation that could not run due to missing local tools.
