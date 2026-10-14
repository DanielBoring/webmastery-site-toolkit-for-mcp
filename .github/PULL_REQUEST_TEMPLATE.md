## Closes
<!-- Link the issue being resolved: Closes #1 -->

## Summary
<!-- What changed and why, in a few sentences. -->

## Ability name (if new)
<!-- e.g. webmastery-site-toolkit-for-mcp/list-media — leave blank for bug fixes -->

## Testing
<!-- The commands you ran and their results, e.g. composer qa, composer qa:tooling, scripts/qa-local.sh --contract --e2e. Say what you could not run and why. Required PR checks: 1 - Static QA, 2 - Unit Tests, Docker QA gate. -->

## Checklist
- [ ] Capability checks use the narrowest relevant WordPress capability, authorize every returned object, and fail with `WP_Error`
- [ ] Inputs are sanitized or validated with WordPress APIs or strict schema checks
- [ ] Response shape is unchanged, or the contract change is documented
- [ ] New or changed abilities have positive and permission-denied cases in `tests/e2e/abilities-manifest.json`
- [ ] User-facing docs are updated (`README.md`, `readme.txt`, `tests/e2e/README.md`)
- [ ] Changelog: plugin-facing changes in `CHANGELOG.md`, repository/CI changes in `.github/REPOSITORY_CHANGELOG.md`
- [ ] `composer phpcs`, PHPStan without new baseline debt, E2E manifest validation and `git diff --check` pass
- [ ] Relevant E2E QA passes, or failures are unrelated and explained
- [ ] Workflow or shell changes pass `composer lint:workflows`, and CI stays within the budgets in `docs/qa-strategy.md`
- [ ] WordPress.org guideline impact was considered for naming, readme, privacy, external calls, licensing, assets or packaging
- [ ] Area-specific items in `docs/qa-harness-reference.md#area-specific-review-checklist` were checked where they apply
