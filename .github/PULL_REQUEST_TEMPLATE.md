## Closes
<!-- Link the issue being resolved: Closes #1 -->

## Summary
<!-- What does this PR change and why? -->

## Ability name (if new)
<!-- e.g. webmastery-site-toolkit-for-mcp/list-media — leave blank for bug fixes -->

## Testing
<!-- How did you verify this works? Include exact commands and their results, such as composer qa, scripts/qa-local.sh --contract --e2e, scripts/qa-local.sh --release, or scripts/qa-local.ps1 -Contract -E2E -Release. Distinguish unit/static, source runtime, original-ZIP runtime, and genuine PHP interpreter lanes. State missing tools, failures, skipped checks, or inapplicable items explicitly. See docs/qa-strategy.md; process changes also affect docs/ci-cd-strategy.md, docs/security-strategy.md, and docs/release-strategy.md. -->
<!-- 1 - Static QA, 2 - Unit Tests, and Docker QA gate report on every PR; Actions-created PRs need workflow approval. 3 - Ability Contract QA and 4 - Full MCP E2E QA run for runtime-impacting changes. Local results do not replace required exact-head PR-event checks. -->

## Checklist
- [ ] Capability checks use the narrowest relevant WordPress capability and return/surface `WP_Error` on failure
- [ ] Delegated permissions retain a local WordPress floor plus upstream authorization in both entry paths, fail closed on missing/non-callable upstream checks, and distinguish fixture evidence from version-specific real-provider inspection
- [ ] List/query abilities authorize every returned object, preserve order and empty-window continuation, and do not leak unauthorized totals or sensitive identity fields
- [ ] Bounded list changes retain measured candidate/query/capability, memory and response-size budgets, stable ID ties, summary omission versus exact full values, effective object/key authorization, and independently selected expected windows; unit/static evidence is distinct from real source/package measurement
- [ ] Extracted-owner changes retain current-source dispatcher/bootstrap/callback ownership, full/summary marker composition, and behavior-preserving regression assertions without changing ability names or support floors
- [ ] Direct SQL follows the diagnostic policy or narrow attachment-reference exception: existing caller/object authorization, prepared candidate-ID/literal-pattern batches, unchanged reference scope, no referring-content/ID/count disclosure, and fail-closed query errors even with force; retain permission, parity, escaping, budget, and no-write/failure tests
- [ ] Security-sensitive abilities include allowed and denied E2E manifest cases, plus `assert_missing_paths` for fields hidden from lower-privilege callers
- [ ] Metadata changes preserve pre-mutation combined-input rejection, real-object effective key authorization, and direct/ability/gateway/individual-tool no-write/no-forbidden-read proof, with plain-content and draft/write/publish migration controls
- [ ] Destructive changes retain exact confirmation, raw bulk bounds, strict optional flags, unchanged-state/hook/cron preview and denial proof, distinct callback/per-item permission evidence, and fail-closed media reference scans even with force; migrated callers retain their original assertions
- [ ] Destructive runtime wiring retains eight serial boundary/trash modes, native audit before test MU capabilities, actual CLI/HTTP config parity, exclusive evidence before credentials, exact owned restoration and original failure status; mocked stages do not replace source/original-package proof
- [ ] Schema stage retains three contract/two HTTP selections, 152 exact cases per invocation, no config writes, original HTTP restoration and owner/source/project-bound cleanup proof; retained posts also retain actors/credentials and block outer teardown until proven safe
- [ ] Inputs are sanitized or validated (`sanitize_text_field`, `sanitize_key`, `absint`, `wp_kses_post`, enum validation, or an equivalent WordPress API)
- [ ] Production array literals use PHPCS-enforced `[]`
- [ ] Fixed input objects are closed, raw permissions and execute callbacks reject malformed types/enums/nulls/unknown keys before work, intentional maps remain open, and mutation/no-query/no-write controls preserve exact boundary-specific errors and hierarchical detach/omission
- [ ] Native input validation delegates to core once before strict types, preserving core errors/empty schemas/normalization/lifecycle, genuine permission denials and direct destructive diagnostics; zero-work evidence does not claim zero core hooks
- [ ] Permission-default changes retain null-only exact-object-root materialization before one parent permission call, no normalization-filter replay or raw-callback relaxation, and Adapter empty-object/allowed-denied/nested-null/invalid-default/lifecycle/reentrancy/privacy controls
- [ ] Successful values/types are preserved except for documented contract changes; failures use canonical code/reason/message/object-details, native permissions retain `WP_Error`, and MCP gateway/individual-tool errors plus foreign-namespace isolation are covered (real HTTP errors have `isError:true`, one JSON text block, and omitted wire `structuredContent`, internally null)
- [ ] Untrusted-field changes preserve exact values/types/markup, use unique present-only record-local names, retain privacy omissions and unavailable SEO fields, and never mark canonical errors or diagnostic error subrecords
- [ ] Annotation changes compare registered hints with actual Adapter individual `tools/list` output; distinguish gateway/get-info metadata, unit/source checks, and real runtime evidence
- [ ] Runtime QA uses an explicitly owned disposable project with loopback HTTP, no database host port, generated private credentials, read-only source/package mounts, separate private artifacts, and actual nonroot candidate CLI/HTTP workers; no shared/live installation or assumed ownership
- [ ] Product runners require explicit disposable opt-in and an exact versioned context; capture original wire/stdout/stderr privately before parsing, retain each original response/case once and a complete final summary within unchanged limits including journal/failure reservations, independently distinguish metadata row cardinality and native provider read capability, validate owned fixture cleanup, and preserve partial evidence and the original failure status
- [ ] The trusted local/hosted Docker engine and image initialization are documented environment assumptions, not whole-host provenance or attestation claims; no candidate code runs as root and cleanup affects only verified owned resources
- [ ] New ability names use the `webmastery-site-toolkit-for-mcp/` prefix and set accurate `annotations` (`readonly`, `destructive`, `idempotent`)
- [ ] If this PR adds or changes `webmastery-site-toolkit-for-mcp/*` abilities, `tests/e2e/abilities-manifest.json` includes matching positive and negative cases where permissions apply
- [ ] User-facing changes update relevant docs (`README.md`, `readme.txt`, `tests/e2e/README.md`, or other affected markdown)
- [ ] Plugin-facing changes update `CHANGELOG.md` under `## Unreleased`
- [ ] Repository, CI, contributor, GitHub platform, template, or agent workflow changes update `.github/REPOSITORY_CHANGELOG.md` under `## Unreleased`
- [ ] Local QA checked with `composer qa` and relevant Docker/release wrappers, including original-ZIP checks for release-bound changes, or missing tools/runtime blockers are documented above
- [ ] `composer phpcs`, E2E manifest validation, relevant E2E QA, and `git diff --check` ran, or missing tools/runtime blockers are documented
- [ ] PHPStan level 5 passes without new baseline debt; resolved entries/counts are removed or lowered, and baseline changes follow the ratchet policy in `docs/qa-strategy.md`
- [ ] Genuine PHP 8.0/8.4 interpreter evidence is distinguished from PHPStan's PHP 8.0 analysis target and the separate WordPress 6.9/PHP 8.1 runtime compatibility lane
- [ ] Compatibility QA was considered for release candidates, dependency-sensitive changes, or WordPress/PHP support changes
- [ ] Workflow/shell changes pass dedicated workflow linters and applicable safeguard regressions; the unit aggregate requires all applicable unit/safeguard jobs and fails on failure, cancellation, or unexpected skip
- [ ] Required PR checks use the exact final head and do not rely on manually dispatched runs, failure-induced skips, disabled protection, or local-only evidence
- [ ] Dependency updates preserve the declared PHP support floor, verified download digests, and baseline metadata; unavailable candidate lanes cannot promote versions
- [ ] CI/CD, security, or release process changes update the matching strategy document when policy changes
- [ ] Release-impacting changes account for GitHub tag `vX.Y.Z`, WordPress.org SVN tag `X.Y.Z`, protected `wordpress-org` GitHub production approval (not WordPress.org staff review), and package/readme/version alignment
- [ ] Release changes preserve the exact validated artifact through approval and publication, and cover partial-publish recovery without rewriting release tags; recovery dispatch requires a separately authorized annotated control tag in reviewed main history, never a branch or a normal release-tag dispatch
- [ ] WordPress.org Detailed Plugin Guidelines were considered for public-facing or release-impacting changes such as naming, readme text, privacy/external calls, licensing, bundled assets, and release packaging; see `CONTRIBUTING.md`, `docs/security-strategy.md`, and `docs/release-strategy.md`
- [ ] E2E QA is passing, or failures are unrelated and explained above
