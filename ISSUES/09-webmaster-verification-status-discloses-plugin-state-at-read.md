---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `webmaster-verification-status` reports Site Kit install/activation state and fires outbound requests for any `read` user'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
The permission callback requires only `read`. `check_google_site_kit()` calls `get_plugins()` / `is_plugin_active()` and returns `installed`, `active` and the plugin basename for `google-site-kit/google-site-kit.php`. `docs/security-strategy.md` says plugin details are Administrator-level data and must not be exposed to lower-privilege users, and README's Security section says the same — yet README's "Verify" list recommends this ability as a Subscriber-safe check. Each call also performs four to six `wp_remote_request()` calls against the site's own front end plus a `dns_get_record()` lookup, which any Subscriber can trigger repeatedly. The spec is right; the code and the README Verify list are wrong.

**Ability name**
`webmastery-site-toolkit-for-mcp/webmaster-verification-status`

**Steps to reproduce**
1. As a Subscriber call `webmastery-site-toolkit-for-mcp/webmaster-verification-status` with `{}`.
2. Expected: no plugin state in the response (or `forbidden`).
3. Got: `data.google.site_kit.installed`, `.active` and `.plugin` are present.

**Relevant code**
- `includes/class-webmaster-verification.php:24-30` — `permission()`: `current_user_can( 'read' )`.
- `includes/class-webmaster-verification.php:92-122` — `check_google_site_kit()`: `get_plugins()` + `is_plugin_active()`, returned verbatim.
- `includes/class-webmaster-verification.php:32-59` — `execute()`: homepage GET, BingSiteAuth HEAD, robots.txt GET, sitemap HEAD(s), DNS TXT.
- `includes/class-site-kit.php:90-101` — `get-site-kit-status` already covers plugin state for Administrators.
- `README.md` Verify section — lists the ability as a safe first check.

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
Either of:
1. Raise the ability to `edit_posts` (it is a webmaster tool, not reader context) **and** omit `check_google_site_kit()` for callers without `activate_plugins`; or
2. Keep `read` and remove `check_google_site_kit()` entirely, pointing users to `get-site-kit-status`.

In both cases: add an `assert_missing_paths` manifest case for `data.google.site_kit` at Subscriber and register it in `scripts/validate-security-qa.php`; update the README Verify list and the "Security Best Practices" bullet accordingly; consider a per-user rate limit or transient cache (e.g. 60 s) on the outbound checks.

**Additional context**
- Live (reference site, as administrator): the response contains `google.site_kit: { installed: true, active: true, plugin: "google-site-kit/google-site-kit.php" }` and every check is emitted twice (`checks.*` and the per-provider blocks), which also doubles the payload (see `ASSESSMENT.md` B-11).
- Not tested as a Subscriber (no low-privilege credential was available); the `read` gate is verified from source.

- Assessment finding: **A-7** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- GitHub issue: #114 (filed 2026-09-16)
- Related: #124 (`20-docs-drift-readme-readme-txt-plugin-header.md`); #125 (`14-site-kit-abilities-need-a-wordpress-capability-floor-and-spec-entry.md`).
