## Closes
<!-- Link the issue being resolved: Closes #1 -->

## Summary
<!-- What does this PR change and why? -->

## Ability name (if new)
<!-- e.g. unlock-mcp-potential/list-media — leave blank for bug fixes -->

## Testing
<!-- How did you verify this works? List the ability calls you ran and what they returned. -->
<!-- E2E QA tests will run automatically on push. -->

## Checklist
- [ ] Capability checks use the narrowest relevant WordPress capability and return/surface `WP_Error` on failure
- [ ] Inputs are sanitized or validated (`sanitize_text_field`, `sanitize_key`, `absint`, `wp_kses_post`, enum validation, or an equivalent WordPress API)
- [ ] Ability responses follow the existing success/error shape for the affected ability group
- [ ] New ability names use the `unlock-mcp-potential/` prefix and set accurate `annotations` (`readonly`, `destructive`, `idempotent`)
- [ ] If this PR adds or changes `unlock-mcp-potential/*` abilities, `tests/e2e/abilities-manifest.json` includes matching positive and negative cases where permissions apply
- [ ] User-facing changes update relevant docs (`README.md`, `readme.txt`, `tests/e2e/README.md`, or other affected markdown)
- [ ] Plugin-facing changes update `CHANGELOG.md` under `## Unreleased`
- [ ] Repository, CI, contributor, GitHub platform, template, or agent workflow changes update `.github/REPOSITORY_CHANGELOG.md` under `## Unreleased`
- [ ] WordPress Coding Standards checked with `composer phpcs`, or the reason it was not run is documented above
- [ ] WordPress.org Detailed Plugin Guidelines were considered for public-facing or release-impacting changes such as naming, readme text, privacy/external calls, licensing, bundled assets, and release packaging
- [ ] E2E QA is passing, or failures are unrelated and explained above
