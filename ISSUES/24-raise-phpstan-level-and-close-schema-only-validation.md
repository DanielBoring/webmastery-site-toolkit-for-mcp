---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Raise PHPStan above level 0 with a baseline; add `additionalProperties: false` and execute-time enum re-validation'
labels: enhancement, priority: low
assignees: ''
---

**Proposed ability name**
Tooling / schemas — `phpstan.neon.dist`, all `input_schema` definitions; no new ability

**What should it do?**
1. `phpstan.neon.dist:2` is `level: 0` and currently passes with zero errors; raise to level 5 with a generated baseline (`--generate-baseline`) and ratchet down over time. Level 0 catches almost nothing that `php -l` does not.
2. Several fields reach `WP_Query` / `WP_User_Query` straight from input, relying solely on schema enums (`status`, `orderby`, `order`). `WP_Ability::execute()` validates input before `check_permissions()` (`abilities-api/class-wp-ability.php:600-605`), so execute bodies are safe — but the MCP Adapter (v0.5.0, verified in `ToolsHandler.php:148` → `McpTool.php:351-359`) calls `$ability->check_permissions( $args )` **before** `$ability->execute()`, and `check_permissions()` does not validate, so every `permission_callback` in this plugin receives un-schema-validated input today. Add `additionalProperties: false` where property sets are closed, and re-validate enum fields in execute via a shared helper (`Webmastery_MCP_Input::enum( $input, 'status', [...] )`).
3. Consider `output_schema` definitions for the most-used abilities so the Abilities API validates responses too (and clients get typed results).

**Required WordPress capability**
n/a

**Why does an AI agent need this?**
Permission callbacks already receive raw input through the adapter; today they only `absint()` IDs and branch on IDs, so nothing is exploitable — but the next permission callback that trusts an enum, array or string from `$input` will be reachable with arbitrary values. A higher PHPStan level and closed schemas are the cheapest guard rails.

**Relevant code**
- `phpstan.neon.dist:1-9` — level 0, WordPress stubs bootstrap.
- `includes/class-posts.php:1905-1909`, `class-custom-post-types.php:480-484`, `class-users.php:124-125`, `class-comments.php:114` — enum fields passed straight to queries.
- All `input_schema` blocks — none set `additionalProperties: false` (see issue 10 for a concrete consequence).
- MCP Adapter 0.5.0: `includes/Handlers/Tools/ToolsHandler.php:148,189`; `includes/Domain/Tools/McpTool.php:351-359,262-269`.
- WordPress 6.9: `wp-includes/abilities-api/class-wp-ability.php:600-605` (`execute()` order).

**Additional context**
- Repository-facing change (`.github/REPOSITORY_CHANGELOG.md`); update `docs/qa-strategy.md` with the new PHPStan level.
- Expect friction from closures with `$input = []` defaults and mixed return types at level 5; the baseline absorbs it.

- Assessment finding: **B-9 (and B-10)** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- GitHub issue: #126 (filed 2026-09-16)
- Related: #106 (`10-update-parent-has-no-permission-or-hierarchy-check.md`); #119.
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
