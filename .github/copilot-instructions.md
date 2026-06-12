# Agent Instructions

Use this workflow for code, documentation, ability, and release-readiness changes in this repository.

## Release-readiness workflow

When asked to add, change, or release abilities, complete all applicable steps before the final response.

1. Audit abilities
   - Compare all registered `unlock-mcp-potential/*` abilities against `tests/e2e/abilities-manifest.json`.
   - Ensure each registered ability has at least one manifest case.
   - For permissioned abilities, include allowed and denied role or capability cases.

2. Update documentation
   - Update `README.md` for user-facing behavior, role requirements, ability tables, verification examples, and security notes.
   - Update `readme.txt` for WordPress.org-facing description, FAQ, changelog, and upgrade notice when applicable.
   - Update related markdown files such as `tests/e2e/README.md`, `CONTRIBUTING.md`, and `.github/PULL_REQUEST_TEMPLATE.md`.

3. Update changelog
   - Add all unreleased work under `CHANGELOG.md` `## Unreleased`.
   - Keep `CHANGELOG.md` focused on plugin-facing release notes. Use user-facing ability descriptions or short ability labels; avoid raw internal ability namespace strings unless the exact MCP tool name is necessary for compatibility notes.
   - Do not put new work directly under a released version unless preparing that release section.

4. Validate contribution guidance
   - Keep `.github/PULL_REQUEST_TEMPLATE.md` and `CONTRIBUTING.md` in sync.
   - The PR checklist must cover capability checks, sanitization, response shape, E2E manifest coverage, docs, changelog, PHPCS, E2E QA, and WordPress.org guideline impact where relevant.

5. Validate
   - When running locally, run available checks in the local checkout before finishing:
     - `composer phpcs`
     - E2E workflow or manifest validation
     - `git diff --check`
   - Expect GitHub Actions to run the authoritative PR checks again after push or PR creation.
   - If PHP, Composer, Docker, or another required tool is unavailable locally, state that clearly in the final response.

6. Final response
   - Summarize changed files and the meaningful outcome.
   - Mention any validation that could not run due to missing local tools.
