## Closes
<!-- Link the issue being resolved: Closes #1 -->

## Summary
<!-- What changed and why, in a few sentences. -->

## Ability name (if new)
<!-- e.g. webmastery-site-toolkit-for-mcp/list-media — leave blank for bug fixes -->

## Testing
<!-- The commands you ran and their results, e.g. composer qa, composer qa:tooling, scripts/qa-local.sh --contract --e2e. Say what you could not run and why. Required PR checks: 01 - Static QA, 02 - Unit Tests, 03-04 - Docker QA. -->

## Checklist
- [ ] Capability checks use the narrowest relevant WordPress capability, authorize every returned object, and fail with `WP_Error`
- [ ] Inputs are sanitized or validated with WordPress APIs or strict schema checks
- [ ] Response shape is unchanged, or the contract change is documented
- [ ] New or changed abilities have positive cases in `tests/e2e/abilities-manifest.json`, plus permission-denied cases where permissions apply
- [ ] User-facing docs are updated (`README.md`, `readme.txt`, `tests/e2e/README.md`)
- [ ] Changelog: plugin-facing changes in `CHANGELOG.md`, repository/CI changes in `.github/REPOSITORY_CHANGELOG.md`
- [ ] `composer phpcs`, PHPStan without new baseline debt, E2E manifest validation and `git diff --check` pass
- [ ] Relevant E2E QA passes, or failures are unrelated and explained
- [ ] Workflow or shell changes pass `composer lint:workflows`, and CI stays within the budgets in `docs/00-sdlc-overview.md#ci-budgets-and-pruning`
- [ ] Workflow, trigger or gate changes update the matching numbered doc in `docs/` and the diagrams in `docs/images/` (`.drawio` source and PNG); ruleset and environment changes update `.github/rulesets/` or `.github/environments/` and pass `11 - Ruleset Audit`
- [ ] WordPress.org guideline impact was considered for naming, readme, privacy, external calls, licensing, assets or packaging
- [ ] Area-specific items in `docs/reference/review-checklist.md` were checked where they apply
