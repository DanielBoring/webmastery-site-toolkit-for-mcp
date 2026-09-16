---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `composer audit --locked` fails (3 advisories) and will take `composer qa:static` / the Static QA workflow down on the next run'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
`composer audit --locked` against the committed `composer.lock` reports:

| Package | Affected | Advisory | Severity |
| --- | --- | --- | --- |
| `phpcsstandards/phpcsutils` | >=1.0.0-alpha1, <1.2.3 | CVE-2026-65954 — arbitrary code execution | (unrated) |
| `squizlabs/php_codesniffer` | <3.13.6 or >=4.0.0,<4.0.2 | CVE-2026-67434 — OS command injection | high |
| `wp-coding-standards/wpcs` | >=0.14.1, <3.4.1 | CVE-2026-45293 — arbitrary code execution | high |

Because `qa:static` in `composer.json` runs `@audit:locked` as its fourth step, the composite exits 1 there and `validate:e2e-manifest`, `validate:security-qa` and `diff-check` never run inside it (all three pass when run alone). The "1 - Static QA" workflow runs `composer qa:static` on every PR and push to `main`.

**Ability name**
n/a — repository tooling (`composer qa:static`, `.github/workflows/coding-standards.yml`); no ability involved

**Steps to reproduce**
1. `composer install` (or use the existing `vendor/` matching the lock) then `composer audit --locked`.
2. Expected: exit 0.
3. Got: exit 1, "Found 3 security vulnerability advisories affecting 3 packages".
4. `composer qa:static` → exit 1 at `Script composer audit --locked handling the audit:locked event returned with error code 1`.

**Relevant code**
- `composer.json` — `qa:static` order: `lint:php`, `phpcs`, `phpstan`, `audit:locked`, `validate:e2e-manifest`, `validate:security-qa`, `diff-check`.
- `composer.lock` — `squizlabs/php_codesniffer` 3.13.5, `wp-coding-standards/wpcs` 3.3.0, `phpcsstandards/phpcsutils` 1.2.2.
- `.github/workflows/coding-standards.yml:46-47` — `run: composer qa:static`.
- `docs/security-strategy.md` — dependency policy recommends Dependabot / Dependency Review; no `.github/dependabot.yml` exists.

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. `composer require --dev squizlabs/php_codesniffer:^3.13.6 wp-coding-standards/wpcs:^3.4.1 phpcsstandards/phpcsutils:^1.2.3` (or `composer update` those three), commit the lock, re-run `composer qa:static` and fix any new PHPCS findings from wpcs 3.4.
2. Add `.github/dependabot.yml` with `package-ecosystem: composer` and `github-actions`, weekly.
3. Optional: reorder `qa:static` so the manifest/security validators run before `audit:locked`, so an advisory never masks a policy failure.

**Additional context**
- Verified on github.com: the last "1 - Static QA" run (#113, commit `b853411`, July 12 2026) passed; all three advisories were published later (July 27 / August 5 2026), so no run has hit them yet — the next PR or push will.
- Exposure is dev-only (code-scanning tools run on CI); nothing in the shipped ZIP is affected.

- Assessment finding: **B-6.1** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- GitHub issue: #128 (filed 2026-09-16)
- Related: #123 (`21-release-workflow-should-rerun-static-and-unit-qa.md`).
