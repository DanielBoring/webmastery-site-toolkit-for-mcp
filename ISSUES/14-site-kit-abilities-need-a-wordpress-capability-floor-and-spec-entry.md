---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Give delegated Site Kit abilities a WordPress capability floor and document delegated permissions in the security strategy'
labels: enhancement, priority: low
assignees: ''
---

**Proposed ability name**
`webmastery-site-toolkit-for-mcp/list-site-kit-modules`, `webmastery-site-toolkit-for-mcp/get-site-kit-permissions`, `webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed` (unreleased; on HEAD `b853411`)

**What should it do?**
1. Add a plugin-level capability floor before delegating to Site Kit's route `permission_callback` — `current_user_can( 'read' )` at minimum, `edit_posts` if you want to exclude pure subscribers from PageSpeed quota usage.
2. Add a "Delegated third-party permission" row to the policy table in `docs/security-strategy.md`: must be combined with a WordPress floor; must fail closed when the upstream route is missing (which `find_route()` already does); must be re-verified when the upstream minimum version changes.
3. Record in README which Site Kit capability each route requires once confirmed on a Site Kit install (`rest_get_server()->get_routes()['/google-site-kit/v1/core/modules/data/list'][0]['permission_callback']`).

**Required WordPress capability**
`read` (or `edit_posts`) floor **plus** Site Kit's own route permission — upstream semantics unchanged.

**Why does an AI agent need this?**
`docs/security-strategy.md` requires "the narrowest relevant WordPress capability for every ability" and has no rule for delegated third-party checks; three abilities currently enforce nothing themselves and inherit whatever Site Kit's internal, unpublished routes decide. A floor removes the spec/code gap and protects against future drift in Site Kit; it also stops `get-site-kit-pagespeed` from spending Google API quota for any user Site Kit lets view a shared dashboard.

**Relevant code**
- `includes/class-site-kit.php:103-105` — `permission_modules()` delegates entirely.
- `includes/class-site-kit.php:107-113` — `permission_permissions()` delegates (with a 1.82.0 version floor).
- `includes/class-site-kit.php:115-123` — `permission_pagespeed()` delegates.
- `includes/class-site-kit.php:317-335` — `check_route_permission()`; `:379-425` `find_route()` fails closed.
- `includes/class-site-kit.php:90-101` — `permission_status()` already has the `manage_options` floor (the pattern to copy).

**Additional context**
- UNVERIFIED which Site Kit capability each route requires; the reference site runs a current Site Kit release (above the 1.82.0 floor) but the released plugin 2.5.0 there does not include this class, so it could not be probed live.
- The response normalisers (`:439-595`) already strip OAuth, owner and raw-settings material; this issue is only about the gate.

- Assessment finding: **A-11** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- Related: `20-docs-drift-readme-readme-txt-plugin-header.md` (security-strategy update).
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
