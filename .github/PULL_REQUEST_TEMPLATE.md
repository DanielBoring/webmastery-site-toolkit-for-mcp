## Closes
<!-- Link the issue being resolved: Closes #1 -->

## Summary
<!-- What does this PR change and why? -->

## Ability name (if new)
<!-- e.g. webmastery-site-toolkit-for-mcp/list-media — leave blank for bug fixes -->

## Testing
<!-- How did you verify this works? Include local QA commands such as composer qa, scripts/qa-local.sh --contract, scripts/qa-local.sh --e2e, scripts/qa-local.ps1 -Contract, scripts/qa-local.ps1 -E2E, or scripts/qa-local.ps1 -PreflightOnly, plus any manual ability calls and what they returned. See docs/qa-strategy.md for which command fits each change type. For process-impacting changes, also check docs/ci-cd-strategy.md, docs/security-strategy.md, and docs/release-strategy.md. -->
<!-- 1 - Static QA, 2 - Unit Tests, and Docker QA gate report on every PR; Actions-created PRs need workflow approval. 3 - Ability Contract QA and 4 - Full MCP E2E QA run for runtime-impacting changes. -->

## Checklist
- [ ] Capability checks use the narrowest relevant WordPress capability and return/surface `WP_Error` on failure
- [ ] List/query abilities filter returned objects with object/status-aware checks and do not leak unauthorized totals or sensitive identity fields
- [ ] Security-sensitive abilities include allowed and denied E2E manifest cases, plus `assert_missing_paths` for fields hidden from lower-privilege callers
- [ ] Inputs are sanitized or validated (`sanitize_text_field`, `sanitize_key`, `absint`, `wp_kses_post`, enum validation, or an equivalent WordPress API)
- [ ] Ability responses follow the existing success/error shape for the affected ability group
- [ ] New ability names use the `webmastery-site-toolkit-for-mcp/` prefix and set accurate `annotations` (`readonly`, `destructive`, `idempotent`)
- [ ] If this PR adds or changes `webmastery-site-toolkit-for-mcp/*` abilities, `tests/e2e/abilities-manifest.json` includes matching positive and negative cases where permissions apply
- [ ] User-facing changes update relevant docs (`README.md`, `readme.txt`, `tests/e2e/README.md`, or other affected markdown)
- [ ] Plugin-facing changes update `CHANGELOG.md` under `## Unreleased`
- [ ] Repository, CI, contributor, GitHub platform, template, or agent workflow changes update `.github/REPOSITORY_CHANGELOG.md` under `## Unreleased`
- [ ] Local QA checked with `composer qa` and the relevant Docker/release QA wrapper from `docs/qa-strategy.md`, or the missing tool/blocker is documented above
- [ ] Compatibility QA was considered for release candidates, dependency-sensitive changes, or WordPress/PHP support changes
- [ ] Workflow/shell changes pass the dedicated workflow linters; required PR checks do not rely on manually dispatched runs or failure-induced skips
- [ ] Dependency updates preserve the declared PHP support floor, verified download digests, and baseline metadata; unavailable candidate lanes cannot promote versions
- [ ] CI/CD, security, or release process changes update the matching strategy document when policy changes
- [ ] Release-impacting changes account for GitHub tag `vX.Y.Z`, WordPress.org SVN tag `X.Y.Z`, protected `wordpress-org` deployment approval, and package/readme/version alignment
- [ ] Release changes preserve the exact validated artifact through approval and publication, and cover partial-publish recovery without rewriting tags
- [ ] WordPress.org Detailed Plugin Guidelines were considered for public-facing or release-impacting changes such as naming, readme text, privacy/external calls, licensing, bundled assets, and release packaging; see `CONTRIBUTING.md`, `docs/security-strategy.md`, and `docs/release-strategy.md`
- [ ] E2E QA is passing, or failures are unrelated and explained above
