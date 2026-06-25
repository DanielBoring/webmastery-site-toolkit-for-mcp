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

## How to read failures

Static QA failures usually mean the code has a syntax, style, static-analysis, manifest-shape, dependency-audit, or whitespace problem. Fix these first because they are the cheapest.

Unit test failures usually mean a small helper contract changed. Either fix the behavior or intentionally update the test if the contract changed.

Ability Contract QA failures usually mean the plugin and manifest disagree, a permission case is wrong, an ability response shape changed, or WordPress logged an error.

Full MCP E2E failures usually mean the real MCP Adapter path broke: session setup, tool discovery, ability execution, WordPress side effects, or denied-role behavior.

Release Package QA failures usually mean the package is not ready to ship: version metadata is out of sync, release notes are missing, dev files leaked into the zip, or Plugin Check found a WordPress.org readiness issue.
