# QA Strategy

This repository uses layered QA for a public WordPress.org plugin. The goal is to catch the cheapest problems first, then add WordPress runtime, MCP transport, security, compatibility, and release-package evidence when a change can affect users, site data, permissions, or publishing.

The plugin is now reviewed as a WordPress.org plugin, so QA must prove more than "the code runs." It must also prove ability permissions, object-level access, response privacy, WordPress compatibility, and package contents stay aligned with WordPress.org expectations.

See the [Software Development Lifecycle](sdlc-overview.md) for where QA fits in the complete development process.

Related strategy guides:

- [`ci-cd-strategy.md`](ci-cd-strategy.md) explains GitHub Actions automation, branch protection, workflow permissions, schedules, artifacts, and failure handling.
- [`security-strategy.md`](security-strategy.md) explains the WordPress plugin threat model, ability permission policy, secrets, dependency security, and vulnerability response.
- [`release-strategy.md`](release-strategy.md) explains versioning, release readiness, GitHub releases, WordPress.org SVN publishing, hotfixes, and rollback policy.

## QA checks

| GitHub Actions check | Command | What it proves | When it should run |
| --- | --- | --- | --- |
| 1 - Static QA | `composer qa:static` | PHP files parse, WordPress Coding Standards pass, PHPStan level 5 passes against reviewed baseline debt with working regression/ratchet guards, Composer dependencies have no known locked advisories, the E2E manifest is structurally valid, security-sensitive QA policy checks pass, and the diff has no whitespace errors. | Every PR and every push to `main`. |
| 2 - Unit Tests | `composer qa:unit` | Small pieces of PHP logic behave correctly without booting WordPress. These tests are the fast safety net for sanitization, response shape, permission helpers, SEO metadata normalization, taxonomy helpers, plugin safety logic, and failure paths. | Every PR and every push to `main`. |
| 3 - Ability Contract QA | `composer qa:contract` or `bash scripts/e2e-test.sh contract` | WordPress boots in Docker, required plugins load, every registered ability is represented in `tests/e2e/abilities-manifest.json`, manifest cases execute through `wp_get_ability()->execute()`, permission-sensitive cases pass, and the debug log stays clean. | Runtime PRs, ability PRs, security-sensitive PRs, `main`, releases, and manual dispatch. |
| 4 - Full MCP E2E QA | `composer qa:e2e` or `bash scripts/e2e-test.sh e2e` | A real MCP HTTP JSON-RPC session can discover and execute abilities through the MCP Adapter, including Application Password authentication, session setup, tool discovery, editor CRUD, and subscriber denial. | Runtime PRs, ability PRs, security-sensitive PRs, `main`, releases, and manual dispatch. |
| 5 - Release Package QA | `composer qa:release` or `bash scripts/release-qa.sh` | Release metadata is aligned, Ability Contract QA and Full MCP E2E QA pass, the release zip contains only packaged plugin files, and WordPress Plugin Check evaluates the built package instead of the development checkout. This check must pass before the protected WordPress.org SVN deploy job can run. | Release PRs, tags, and manual dispatch. |
| 6 - Compatibility QA | `.github/workflows/compatibility-qa.yml` | Scheduled/manual Docker QA discovers official upstream releases, isolates pinned and candidate WordPress/MCP Adapter combinations, and promotes passing versions through a maintainer-reviewed PR while exercising ability contracts, MCP transport, and debug-log cleanliness. | Weekly schedule, manual dispatch after an upstream release, release-candidate investigation, and upstream-breakage triage. |

Static QA runs the full toolchain on PHP 8.0 and syntax checks on PHP 8.4; Unit Tests run on both versions. Each workflow has a stable aggregate result. `Docker QA gate` reports successful change detection and the required Docker results; failure-induced skips cannot pass it. A separate workflow lint check runs actionlint, ShellCheck, and zizmor without making local PHP QA depend on Docker.

The `2 - Unit Tests` gate also requires a separate ten-minute Ubuntu 24.04
synthetic controller-component job using preinstalled Python 3.11 or newer.
`scripts/test-controller-components.py` fails unsupported/denied pidfd hosts
rather than accepting skipped tests. Its result ledger requires the exact ten
component IDs to start, finish and pass; skips, expected failures, substitutions
and partial execution fail. Eight additional in-process harness regressions
check that accounting and scoped retention; they are not part of the ten
controller cases and do not launch children.

The job reserves a fresh mode-0700 namespace under runner temporary storage.
The test class, not the launcher, creates its absent capture child. Always-run
retention copies only allowlisted synthetic regular files, hashes their original
bytes and records intentional symlinks as metadata without following targets.
The synthetic bundle/result ledger is retained for seven days even when tests
fail. File/count/byte retention limits are synthetic CI safeguards, not the
WordPress benchmark's payload or memory budgets. This job neither starts
WordPress/Docker nor invokes the benchmark entrypoint, reads private producer
directories, or proves durable private custody, receiver completion, floor,
transport or original-package correctness. Local `composer qa` remains PHP-only.

## PHPStan level 5 and baseline ratchet

`composer phpstan` analyses the plugin entry point and all of `includes/` at level 5, explicitly targeting PHP 8.0 regardless of the local interpreter. `phpstan-baseline.neon` records pre-existing diagnostics, not permission to introduce new ones. Each generated ignore has an anchored message, identifier, positive count, and individual file path. There are no excluded production paths or identifier-only/global ignores. The existing `treatPhpDocTypesAsCertain: false` policy and WordPress stubs bootstrap are retained; no extra bootstrap constants or relaxed rules hide debt.

The initial reviewed baseline contains 35 diagnostics in 21 entries across nine files:

| Existing diagnostic group | Count | Review context |
| --- | ---: | --- |
| Missing WordPress constants | 9 | `ARRAY_A`, `WP_CONTENT_DIR`, `WP_PLUGIN_DIR`, `DAY_IN_SECONDS`, and `MINUTE_IN_SECONDS` are runtime/bootstrap knowledge absent from the current analysis setup. Kept as explicit debt, not globally suppressed. |
| Argument types | 4 | Media sideload array typing and post-update array shapes after `wp_slash`; investigate with WordPress stub and runtime evidence before changing calls. |
| Media callback control flow | 16 | Always-true/false comparisons, boolean expressions, and unreachable code around by-reference state in deferred download hooks. Do not delete runtime safeguards merely to satisfy inference. |
| Private late-static method calls | 2 | Media DNS validation calls need separate inheritance/typing review. |
| Database error-state comparisons | 2 | Defensive checks of mutable `$wpdb->last_error` after database calls. |
| Revision error check and sitemap offset | 2 | One impossible-type diagnostic and one redundant null-coalescing diagnostic; assess separately before simplifying defensive code. |

A green run means no diagnostics beyond that reviewed debt, not that these findings are fixed or that all mixed input/output types are sound. The baseline matches counts per message/identifier/file, not line or expression identity: replacing a removed error with the same error elsewhere in the same file can evade the count ratchet and still needs human diff review. This tooling change does not validate ability schemas, enums, output contracts, or runtime permissions.

### Reducing debt

1. Fix the underlying issue with focused behavior/typing evidence. Do not add casts, assertions, inline ignores, broader types, or remove defensive checks solely to silence analysis.
2. Run **the complete** `composer phpstan`. `reportUnmatchedIgnoredErrors: true` rejects removed diagnostics and reduced counts until the corresponding baseline entry is removed or its count lowered. Passing individual filenames to PHPStan skips unmatched-entry checking and is not ratchet evidence.
3. Remove only resolved entries or reduce their counts. If regeneration is necessary, run `composer phpstan -- --generate-baseline=phpstan-baseline.neon`, then review the entire baseline diff. Reject unrelated additions, increased counts, wildcard paths, widened messages, and removed identifiers. Do not regenerate the baseline just to make a new error pass.
4. Run `composer phpstan` and `composer test:phpstan-baseline` again, followed by `composer qa`. Commit the fix and baseline reduction together. New baseline debt needs explicit separate review and rationale; normal changes must not grow it.

Zero debt is a valid endpoint: after fixing the final diagnostic, keep the baseline file with `ignoreErrors: []` nested under `parameters:`. The guard accepts an empty baseline while continuing to check every remaining entry and the full-project analysis policy.

`composer test:phpstan-baseline` checks the effective repository configuration and ignore scopes, then generates isolated synthetic PHP under a random `build/phpstan-tests-*` directory. It inherits the real rules and unmatched-ignore setting, proves existing fixture debt passes, and requires nonzero analysis with the expected diagnostics for new level-5 argument/return errors, identical errors in another path, different messages under the same identifier, excess counts, partially stale counts, and fully stale entries. Lowering a fixed count and removing a resolved entry must restore a clean run. Fixtures are analysed, never executed, and cleaned up on exit; they are not shipped in the plugin.

The guard runs in `composer qa:static` and in `composer test:ci-safeguards`, so CI exercises it on PHP 8.0 and 8.4 without changing the runtime test suites. PHPStan dependency upgrades remain a separate review because inference or diagnostic wording changes can alter baseline matching.

## Security QA policy

Security QA is a cross-cutting gate, not just a separate scanner. The permission issue fixed in PR #90 showed that valid syntax, WPCS, and broad ability coverage are not enough unless the tests explicitly prove the permission model.

For every new or changed `webmastery-site-toolkit-for-mcp/*` ability, review whether it is security-sensitive. An ability is security-sensitive when it does any of the following:

- reads private, draft, pending, scheduled, trashed, user, environment, plugin, security, database, backup, or performance data
- returns full object payloads, totals, author identity, login names, emails, backend versions, filesystem/plugin details, or other fingerprinting data
- creates, updates, deletes, publishes, schedules, privates, restores, activates, deactivates, uploads, or otherwise changes site state
- accepts object IDs, URLs, HTML, metadata keys, taxonomy terms, plugin identifiers, statuses, or role/capability-sensitive inputs

Security-sensitive abilities must include:

1. A positive manifest case for a role/capability that should be allowed.
2. A negative manifest case for a role/capability that should be denied, unless the ability intentionally returns public-safe data and that rationale is documented.
3. Object/status-aware assertions for list/query abilities that can return mixed authorization results.
4. `assert_missing_paths` for sensitive fields that must not appear for lower-privilege callers.
5. Failure-path assertions for status escalation, protected metadata, unsafe URLs, ambiguous identifiers, stale preconditions, and destructive actions where applicable.

`composer validate:security-qa` enforces the current high-risk policy:

- `permission_callback => '__return_true'` is blocked in plugin ability registrations unless intentionally allow-listed in the validator after explicit security review.
- The permission-hardening regression cases from PR #90 must keep negative manifest coverage.
- Comment update, approval, trash, and spam abilities must keep moderator-only denial cases with persisted content/status assertions, including an update that supplies a moderation status.
- Comment QA also requires mapped-CPT allowed/denied cases, the Author moderation floor, zero-ID no-write controls, and exact missing-object results for allowed/denied callers. A dedicated CLI-only runner compares direct, ability, and real HTTP behavior and retains comment table/metadata hashes and raw transport envelopes.
- Sensitive identity and fingerprinting absence assertions must remain in the manifest.

This validator is intentionally conservative. It should catch known dangerous patterns without replacing human review, WordPress.org review, or future deeper static analysis such as CodeQL or Semgrep.

## Ability Contract QA vs Full MCP E2E QA

These two checks both use Docker WordPress, but they prove different things.

Ability Contract QA is the plugin contract layer. It asks: "Inside WordPress, did this plugin register the abilities we expect, and do the manifest cases pass with the right permissions and response shapes?" It is broad and ability-driven.

Contract QA additionally runs `tests/e2e/trash-safety-runner.php` in two fresh PHP processes. Each defines and verifies `EMPTY_TRASH_DAYS` before loading actual WordPress core: `30` for normal trash/restore and `0` for refusal before mutation. These lanes exercise registered abilities, not stubs, and report separately from the ordinary manifest counts. See [`tests/e2e/README.md`](../tests/e2e/README.md#isolated-trash-safety-regressions) for assertions and coverage boundaries.

Full MCP E2E QA is the real transport layer. It asks: "Can an MCP client actually talk to WordPress through the MCP Adapter and perform real work?" It is narrower but deeper, because it uses Application Passwords, MCP session initialization, `tools/list`, ability discovery, and real CRUD calls over HTTP JSON-RPC.

Both layers matter. Contract QA catches broad ability drift and permission regressions. Full MCP E2E catches transport and integration problems that direct PHP execution cannot see.

## Trigger matrix

| Event or change type | 1 - Static QA | 2 - Unit Tests | 3 - Ability Contract QA | 4 - Full MCP E2E QA | 5 - Release Package QA | 6 - Compatibility QA |
| --- | --- | --- | --- | --- | --- | --- |
| Docs-only PR | Required | Required | Skipped unless manually dispatched | Skipped unless manually dispatched | Skipped | Skipped |
| Runtime PR | Required | Required | Required | Required | Skipped unless release-impacting | Manual when compatibility-risky |
| New or changed ability | Required | Required | Required | Required | Skipped unless release-impacting | Manual when dependency/version-risky |
| Security-sensitive PR | Required | Required | Required | Required | Required when release-bound | Manual before release when risk is broad |
| Push to `main` | Required | Required | Required when runtime files changed | Required when runtime files changed | Skipped | Scheduled/manual |
| Release PR | Required | Required | Required | Required | Required | Manual for release candidates |
| Tag `v*` | Required by release workflow | Required by release workflow | Covered by Release Package QA | Covered by Release Package QA | Required | Review current compatibility evidence before approval |
| `workflow_dispatch` | Runs selected workflow | Runs selected workflow | Runs | Runs | Runs | Runs |
| Weekly schedule | Skipped | Skipped | Skipped | Skipped | Skipped | Runs |

Runtime-impacting paths include plugin source, tests, scripts, Docker configuration, Composer files, workflow files, package metadata, assets, and `readme.txt`.

## Required main CI checks

The active `main-ci-gates` ruleset requires exactly these GitHub Actions checks before merging any PR into `main`:

- `1 - Static QA`
- `2 - Unit Tests`
- `Docker QA gate`

For runtime-impacting, ability, or security-sensitive PRs, `Docker QA gate` requires both runtime checks to pass; they are not separate required-check entries in the ruleset:

- `3 - Ability Contract QA`
- `4 - Full MCP E2E QA`

Require release/package QA before publishing a release:

- `5 - Release Package QA`

Use `6 - Compatibility QA` as a scheduled/manual maintainer gate at first. Promote individual compatibility jobs to required only after they are stable and low-noise.

The important GitHub concept is "required status checks." A workflow can run on many events, but branch protection decides which successful checks are required before a PR can merge.

Enforcement was activated and read back on September 17, 2026, after all five genuine PR-event workflows passed on bot PR #140. See the [dated setup evidence](../.github/SETUP-COMPLETE.md#rollout-evidence), which distinguishes the dispatched compatibility pipeline from the approved PR-event checks. `workflow_dispatch` job results do not satisfy branch-ruleset requirements. Future `GITHUB_TOKEN`-created PRs still need maintainer workflow approval; the initial rollout did not create an auto-approval bypass. The path-filtered Release Package QA workflow must not become a required PR check in its current form.

## Which command should I run?

For a docs-only change:

```bash
composer qa
```

For a normal code bugfix:

```bash
composer qa
E2E_MANAGE_COMPOSE=1 composer qa:contract
E2E_MANAGE_COMPOSE=1 composer qa:e2e
```

For a new or changed ability:

```bash
composer qa
E2E_MANAGE_COMPOSE=1 composer qa:contract
E2E_MANAGE_COMPOSE=1 composer qa:e2e
```

For a security-sensitive change:

```bash
composer qa
composer validate:security-qa
E2E_MANAGE_COMPOSE=1 composer qa:contract
E2E_MANAGE_COMPOSE=1 composer qa:e2e
```

For release preparation:

```bash
composer qa
composer qa:release
```

`composer qa:release` runs the Docker contract and transport checks before Plugin Check. If Docker is unavailable locally, use GitHub Actions for the authoritative release validation and document the local blocker in the PR.

Release QA builds once (or accepts `RELEASE_ZIP`) and validates exact source/ZIP allowlist hashes before extraction. Runtime QA mounts `build/release-runtime/webmastery-site-toolkit-for-mcp`, not the checkout, through `docker-compose.release.yml`. Only `tests/`, the two compatibility helpers, the baseline JSON, and `e2e-artifacts/` are additional binds; no checkout-wide or `vendor/` mount can supply an unpackaged dependency. Plugin Check copies a separate pristine `build/release-check` extraction, so nested runtime mount placeholders cannot affect its findings. After QA, the actual runtime production tree must still match the ZIP: only the exact empty host-side bind placeholders are permitted, not arbitrary extra files or directories. The original ZIP's SHA-256 must also remain unchanged.

Use a uniquely named disposable project, for example `COMPOSE_PROJECT_NAME=wstm-release-mytest MYSQL_PORT=0 WORDPRESS_PORT=0 REQUIRE_CURRENT_PLUGIN_CHECK=1 COMPOSER_PROCESS_TIMEOUT=0 composer qa:release`. The timeout override lets the full local runtime/checker sequence exceed Composer's default 300 seconds; GitHub release jobs invoke Bash directly under workflow time limits. Ordinary `composer qa:contract`/`qa:e2e` retain the checkout bind. Package mode explicitly selects the base and release Compose files for runtime, checker, and cleanup, rather than inheriting a caller's `COMPOSE_FILE`. Do not reuse a shared Compose project. `composer test:release-safeguards` exercises the real release/E2E orchestration with safe Docker stubs, malformed/missing package guards, a checkout-mode negative control, checker failures, and archive-identity mutation without Docker or network access.

PowerShell users can use the local wrapper:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release
```

Bash users can use the local wrapper:

```bash
scripts/qa-local.sh
scripts/qa-local.sh --contract
scripts/qa-local.sh --e2e
scripts/qa-local.sh --release
```

## WordPress.org release-readiness policy

Before publishing a WordPress.org-facing release:

1. Confirm version metadata matches across the plugin header, `readme.txt` stable tag, changelog, release notes, zip name, and tag.
2. Run `composer qa` and `composer qa:release`.
3. Confirm Plugin Check evaluates the built package under the canonical `webmastery-site-toolkit-for-mcp` slug.
4. Confirm no unexpected WordPress debug-log warnings, notices, deprecations, or errors appear during Docker QA.
5. Confirm security-sensitive changes have manifest evidence for allowed and denied access.
6. Review the official [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) for release-impacting changes, especially licensing, bundled assets, external services, tracking consent, executable remote code, readme content, default WordPress libraries, SVN discipline, version increments, and trademarks.
7. Run or review the latest `6 - Compatibility QA` result before approving WordPress.org SVN deployment.
8. Document any known Plugin Check warnings, guideline considerations, or unavailable local tooling in the PR.

Plugin Check supports the guideline review, but it does not replace maintainer responsibility for the final WordPress.org package contents and behavior.

## Compatibility policy

The default PR path should stay stable and reasonably fast. Compatibility QA starts as scheduled/manual coverage because upstream images, plugin versions, and dependency repositories can change independently of a PR.

The current compatibility workflow reads `.github/compatibility-versions.json`, discovers the latest stable releases, and runs:

- WordPress 6.9 on PHP 8.1 with the pinned MCP Adapter baseline
- the exact pinned WordPress and MCP Adapter baselines
- the pinned WordPress baseline with the latest stable MCP Adapter
- the latest stable WordPress release with the pinned MCP Adapter baseline
- both latest releases together when both changed during the same interval

All lanes pull fresh images, run both Ability Contract QA and Full MCP E2E QA, fail on WordPress debug-log warnings/notices/deprecations/errors, and record resolved dependency versions in the GitHub Actions job summary. After a successful scheduled run, newer versions are proposed in an automated PR that updates the baseline file and the concrete runtime pins. Maintainer approval and merge remain mandatory.

Additional coverage separates PHP 8.4, MySQL 8.4, floating SEO dependencies, and current Plugin Check package validation. Package-check lanes run the applicable package command; they are not interchangeable with the contract/transport lanes. Ordinary QA uses the reviewed dependency pins and executable-download digests in `.github/compatibility-versions.json`.

Only tested candidates may update baselines or `readme.txt` `Tested up to`. Missing images, failed discovery, stale source history, and unavailable dependencies are explicit failures that block promotion. Baseline updates preserve the full configuration and PHP image suffix. Approval of the bot PR's genuine PR-event workflows is required; a successful manual dispatch is not a substitute.

The checked-in primary baseline is WordPress 7.1 with MCP Adapter 0.6.1 and its verified SHA-256. This adapter update leaves WordPress, `Tested up to`, other dependency pins, and PHP support unchanged.

At the September 17, 2026 verification, `main` at `3dae8aa` pinned MCP Adapter 0.5.0. The passing compatibility pipeline proposed 0.6.1 in PR #140, which then remained open and unmerged. Those historical results do not replace current-source compatibility and genuine PR checks before promotion.

### Runtime coverage limitation

The advertised plugin minimum remains PHP 8.0. Syntax and unit tests exercise that version, but the supported-floor WordPress Docker lane uses PHP 8.1. This is a documented integration-coverage gap, not authorization to raise the plugin minimum or claim PHP 8.0 WordPress integration was tested. Add a maintained PHP 8.0 integration fixture or make a separate support-policy decision before changing that claim.

PHPCompatibilityWP's available stable rules depend on the older PHPCompatibility engine. Installing those rules alone does not establish PHP 8.4 compatibility. Runtime evidence remains necessary; a future compatibility-sniff dependency must be reviewed for its actual supported language versions.

When compatibility failures occur, triage them as:

- **release blocker** when the failure affects the supported floor, current WordPress line, or another supported WordPress/PHP combination
- **upstream drift** when a floating WordPress or latest MCP Adapter dependency changed and the plugin needs an adjustment or documented compatibility boundary
- **workflow maintenance** when the failure is caused by runner/image/tooling changes unrelated to plugin behavior

## Environment notes

The QA layers are the same in every environment, but the safest entrypoint differs by shell.

### GitHub Actions

GitHub Actions is the authoritative validation gate before merge. The workflows install their own PHP and Composer runtime, use Ubuntu shell tools, and run Docker jobs on GitHub-hosted Linux runners.

Use branch protection to require the relevant workflow checks instead of relying only on a contributor's local machine.

### Windows PowerShell

PowerShell users should prefer the local wrapper because it checks prerequisites and gives Windows-specific hints:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release
```

Docker Desktop must be running for Docker QA. Native `php`, `composer`, and archive tooling may depend on Windows PATH setup. When the native PHP/Composer path is not available, the project can still run many checks through Docker-based PHP/Composer commands.

### Windows Git Bash

Git Bash users should prefer login-shell style commands for Docker QA:

```bash
bash -lc './scripts/e2e-test.sh contract'
bash -lc './scripts/e2e-test.sh e2e'
bash -lc 'bash scripts/release-qa.sh'
```

The Docker runner sets `MSYS_NO_PATHCONV=1` so Git Bash does not rewrite Linux container paths such as `/var/www/html/wp-load.php` into Windows paths. This matters because Docker commands in the runner execute inside Linux containers, even though the shell is running on Windows.

Depending on local PATH and Docker Desktop setup, `bash scripts/...` and `bash -lc './scripts/...'` can resolve Docker shims differently. If a plain Git Bash invocation fails locally but GitHub Actions passes, retry with the login-shell form above before assuming the repo script is broken.

### Local caveats

Docker Desktop must be running before Docker QA can start. WordPress, MCP Adapter, Yoast SEO, SEOPress, Plugin Check, and release package checks all depend on network access during fresh local runs.

Local Plugin Check output may include known warnings that are acceptable for this project. The release process should treat unexpected errors as blockers, while the documented warnings in `CONTRIBUTING.md` remain known review items.

## How to read failures

Static QA failures usually mean the code has a syntax, style, static-analysis, manifest-shape, security-policy, dependency-audit, or whitespace problem. Fix these first because they are the cheapest.

Unit test failures usually mean a small helper contract changed. Either fix the behavior or intentionally update the test if the contract changed.

Ability Contract QA failures usually mean the plugin and manifest disagree, a permission case is wrong, an ability response shape changed, an expected sensitive field appeared, or WordPress logged a problem.

Full MCP E2E failures usually mean the real MCP Adapter path broke: session setup, tool discovery, ability execution, WordPress side effects, denied-role behavior, or cleanup.

Release Package QA failures usually mean the package is not ready to ship: Docker contract/transport checks failed, version metadata is out of sync, release notes are missing, dev files leaked into the zip, or Plugin Check found a WordPress.org readiness issue.

Compatibility QA failures usually mean upstream WordPress, PHP, MySQL, MCP Adapter, Yoast SEO, SEOPress, Plugin Check, or GitHub runner behavior changed and needs maintainer triage.
