# QA Strategy

This repository uses QA as a set of layered confidence checks. Each layer answers a different question, from "does the PHP parse?" to "can a real MCP client change WordPress content through the adapter?"

The goal is not to run every expensive check for every typo. The goal is to run the cheapest useful checks first, then run Docker and release checks when a change can affect runtime behavior or shipping.

## The five QA layers

| Layer | Command | What it proves | When it should run |
| --- | --- | --- | --- |
| Static QA | `composer qa:static` | PHP files parse, WordPress Coding Standards pass, PHPStan can analyze the plugin at the configured level, Composer dependencies have no known locked advisories, the E2E manifest is structurally valid, and the diff has no whitespace errors. | Every PR and every push to `main`. |
| Unit Tests | `composer qa:unit` | Small pieces of PHP logic behave correctly without booting WordPress. These tests are the fast safety net for sanitization, response shape, and helper behavior. | Every PR and every push to `main`. |
| Ability Contract QA | `composer qa:contract` or `bash scripts/e2e-test.sh contract` | WordPress boots in Docker, required plugins load, every registered ability is represented in `tests/e2e/abilities-manifest.json`, manifest cases execute through `wp_get_ability()->execute()`, and the debug log stays clean. | Runtime PRs, `main`, releases, and manual dispatch. |
| Full MCP E2E QA | `composer qa:e2e` or `bash scripts/e2e-test.sh e2e` | A real MCP HTTP JSON-RPC session can discover and execute abilities through the MCP Adapter, including editor CRUD and subscriber denial. | Runtime PRs, `main`, releases, and manual dispatch. |
| Release Package QA | `composer qa:release` or `bash scripts/release-qa.sh` | The version metadata is aligned, the release zip contains only packaged plugin files, and WordPress Plugin Check evaluates the built package instead of the development checkout. | Release PRs, tags, and manual dispatch. |

## Ability Contract QA vs Full MCP E2E QA

These two checks both use Docker WordPress, but they prove different things.

Ability Contract QA is the plugin contract layer. It asks: "Inside WordPress, did this plugin register the abilities we expect, and do the manifest cases pass with the right permissions and response shapes?" It is broad and ability-driven.

Full MCP E2E QA is the real transport layer. It asks: "Can an MCP client actually talk to WordPress through the MCP Adapter and perform real work?" It is narrower but deeper, because it uses Application Passwords, MCP session initialization, `tools/list`, ability discovery, and real CRUD calls over HTTP JSON-RPC.

Both layers matter. Contract QA catches broad ability drift. Full MCP E2E catches transport and integration problems that direct PHP execution cannot see.

## Possible future advanced QA layer

An advanced MCP manifest replay layer could exercise selected or all `tests/e2e/abilities-manifest.json` cases through the real MCP Adapter HTTP JSON-RPC transport instead of direct `wp_get_ability()->execute()` calls.

This would answer a stronger question than the current Full MCP E2E QA: "Can the same ability cases that pass inside WordPress also pass when invoked by a real MCP client through `mcp-adapter-execute-ability`?"

This should remain separate from the default Full MCP E2E QA unless the project needs deeper transport coverage. Replaying the full manifest over MCP would be slower and more brittle than Ability Contract QA, and it would duplicate much of the same behavioral coverage. A future implementation could use a dedicated command such as `composer qa:mcp-manifest` or `bash scripts/e2e-test.sh mcp-manifest`, with options to run a focused subset for changed abilities and a full replay for release candidates or manual investigations.

## GitHub Actions trigger matrix

| Event or change type | Static QA | Unit Tests | Ability Contract QA | Full MCP E2E QA | Release Package QA |
| --- | --- | --- | --- | --- | --- |
| Docs-only PR | Required | Required | Skipped unless manually dispatched | Skipped unless manually dispatched | Skipped |
| Runtime PR | Required | Required | Required | Required | Skipped unless release-impacting |
| Push to `main` | Required | Required | Required when runtime files changed | Required when runtime files changed | Skipped |
| Release PR | Required | Required | Required | Required | Required |
| Tag `v*` | Optional through previous PR checks | Optional through previous PR checks | Covered by release QA | Covered by release QA | Required |
| `workflow_dispatch` | Runs selected workflow | Runs selected workflow | Runs | Runs | Runs |

Runtime-impacting paths include plugin source, tests, scripts, Docker configuration, Composer files, workflow files, package metadata, and `readme.txt`.

## Branch protection recommendation

Require these checks before merging any PR:

- `Static QA`
- `Unit Tests`

Require these checks before merging runtime-impacting PRs:

- `Ability Contract QA`
- `Full MCP E2E QA`

Require release/package QA before publishing a release:

- `Release Package QA`

The important GitHub concept is "required status checks." A workflow can run on many events, but branch protection decides which successful checks are required before a PR can merge.

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

For release preparation:

```bash
composer qa
E2E_MANAGE_COMPOSE=1 composer qa:contract
E2E_MANAGE_COMPOSE=1 composer qa:e2e
composer qa:release
```

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

## Environment notes

The QA layers are the same in every environment, but the safest entrypoint differs by shell.

### GitHub Actions

GitHub Actions is the authoritative validation gate before merge. The workflows install their own PHP and Composer runtime, use Ubuntu shell tools, and run Docker jobs on GitHub-hosted Linux runners.

Use branch protection to require the relevant workflow checks instead of relying only on a contributor's local machine. At minimum, require Static QA and Unit Tests for every PR. For runtime-impacting PRs, require Ability Contract QA and Full MCP E2E QA.

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

## Execution appendix

Use this appendix to sequence QA improvements without turning every PR into a large infrastructure change. Each phase should land as a small PR with updated commands, workflows, documentation, and repository changelog entries.

### Phase 0: keep the strategy and implementation aligned

Before adding new QA depth, confirm the documented layers match the commands and workflows that exist in the repository.

1. Reconcile release coverage: either make `scripts/release-qa.sh` run Full MCP E2E QA or update the trigger matrix so tag releases do not claim Full MCP E2E is covered by release QA.
2. Keep `composer.json`, `scripts/qa-local.sh`, `scripts/qa-local.ps1`, GitHub Actions, and this document in sync whenever a QA command is renamed or split.
3. Keep `.github/PULL_REQUEST_TEMPLATE.md` and `CONTRIBUTING.md` aligned with the QA commands contributors are expected to run.

Exit criteria:

- The command table, trigger matrix, local wrapper flags, and CI workflow names describe the same QA layers.
- A maintainer can follow the release-preparation commands without needing undocumented steps.

### Phase 1: harden the fast checks

The fast checks should catch syntax, standards, type, dependency, and small helper regressions before Docker starts.

1. Expand unit coverage beyond the initial helper tests to cover sanitization, response shape helpers, SEO metadata normalization, plugin safety logic, taxonomy helpers, and permission-denial result shapes.
2. Add focused tests for failure paths, not only happy paths: invalid IDs, unsupported enum values, protected meta keys, ambiguous block targets, missing plugins, and denied capabilities.
3. Raise PHPStan strictness gradually. Start from the current baseline, fix high-signal findings, then increase levels only when the new level is practical for day-to-day PRs.
4. Consider adding CodeQL or Semgrep as a separate security-analysis workflow once PHPStan and Composer audit are stable.

Exit criteria:

- `composer qa` stays fast enough for every PR.
- Unit tests cover representative logic from multiple ability groups.
- Static QA failures are actionable and do not rely on broad ignored-error lists.

### Phase 2: deepen Docker contract and transport coverage

The Docker layers should prove WordPress integration and MCP transport behavior without duplicating every assertion in every layer.

1. Keep Ability Contract QA broad: every registered ability must have manifest coverage, with positive and negative cases where permissions apply.
2. Keep Full MCP E2E QA narrow but deep: it should prove real MCP Adapter session setup, tool discovery, ability execution, side effects, and denied-role propagation.
3. Add the optional MCP manifest replay layer only when the project needs stronger transport coverage for selected abilities. Prefer focused replay for changed abilities and reserve full replay for release candidates or manual investigations.
4. Improve artifacts by writing machine-readable summaries for both contract and transport runs, and upload them on failure.

Exit criteria:

- Contract failures identify ability, role, expected result, and assertion context.
- Transport failures identify the MCP phase that failed: initialize, tools/list, discover, execute, side effect, denial, or cleanup.
- Full MCP manifest replay remains optional and does not slow the default PR path.

### Phase 3: add compatibility and dependency confidence

Compatibility checks should run where they add signal without blocking routine PRs for unrelated matrix noise.

1. Add a scheduled or manual compatibility workflow for supported PHP versions, current and minimum supported WordPress versions, and relevant database variants.
2. Keep the default PR path on the minimum supported PHP version plus the primary Docker stack so compatibility regressions are caught early without excessive runtime.
3. Add a floating-latest scheduled run for WordPress, MCP Adapter, Yoast SEO, SEOPress, and Plugin Check so upstream changes are detected before release week.
4. Document which compatibility failures are release blockers and which require triage before being made required checks.

Exit criteria:

- Required PR checks remain stable and reasonably fast.
- Scheduled compatibility checks expose upstream or version-specific breakage with enough context to reproduce locally.

### Phase 4: scale E2E maintainability

As the ability count grows, split large E2E surfaces by purpose instead of making one monolithic runner absorb every case.

1. Group manifest cases by ability domain, such as posts/pages, custom post types, taxonomy, media, comments, SEO, diagnostics, plugins, and site introspection.
2. Add runner filters for ability group, ability name, or manifest label so maintainers can reproduce focused failures locally.
3. Consider CI sharding only after group filters exist and the full contract run becomes slow enough to justify parallel jobs.
4. Preserve a full contract run for release candidates and manual dispatch even if PR checks become selective.

Exit criteria:

- A maintainer can run one affected ability group locally without editing the manifest.
- CI can report which ability group failed.
- Full release coverage remains available.

### Phase 5: add advanced quality signals only where they pay off

Coverage and mutation metrics are useful when applied to security-sensitive or heavily reused logic, but they should not become vanity gates.

1. Add coverage reporting for unit tests after the fast unit layer has meaningful breadth.
2. Set thresholds only for critical helper areas that are stable enough to support thresholds.
3. Consider mutation testing for sanitization, capability, and response-shape helpers if ordinary coverage stops finding meaningful gaps.
4. Treat new metrics as advisory first, then promote them to required gates only after they are stable and low-noise.

Exit criteria:

- Coverage reports help guide missing tests rather than blocking unrelated work.
- Mutation testing, if added, is scoped to high-value code and can run manually or on schedule.

## How to read failures

Static QA failures usually mean the code has a syntax, style, static-analysis, manifest-shape, dependency-audit, or whitespace problem. Fix these first because they are the cheapest.

Unit test failures usually mean a small helper contract changed. Either fix the behavior or intentionally update the test if the contract changed.

Ability Contract QA failures usually mean the plugin and manifest disagree, a permission case is wrong, an ability response shape changed, or WordPress logged an error.

Full MCP E2E failures usually mean the real MCP Adapter path broke: session setup, tool discovery, ability execution, WordPress side effects, or denied-role behavior.

Release Package QA failures usually mean the package is not ready to ship: version metadata is out of sync, release notes are missing, dev files leaked into the zip, or Plugin Check found a WordPress.org readiness issue.
