---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `security-audit` / `database-health` return absolute paths, DB prefix and raw SQL errors; SSL check reflects the request, not the site'
labels: bug, priority: low
assignees: ''
---

**Describe the bug**
- `security-audit`'s `debug_log` warning embeds the absolute filesystem path (`WP_CONTENT_DIR . '/debug.log'` or the `WP_DEBUG_LOG` value) and applies `esc_html()` inside a JSON payload, so HTML entities leak into the message.
- `security-audit`'s `ssl` check uses `is_ssl()`, which describes the current MCP request; behind a TLS-terminating proxy or over an internal loopback it yields a **FAIL "Site is not using HTTPS"** on an HTTPS site.
- `database-health` returns `table` names including `$wpdb->prefix`, which also fingerprints installed plugins, and returns `$wpdb->last_error` verbatim on query failure (may contain SQL fragments and table names).

All three are Administrator-only, but these payloads go to a model provider, and `docs/security-strategy.md` lists environment details as data to minimise. The false SSL negative can steer an agent into unnecessary changes.

**Ability name**
`webmastery-site-toolkit-for-mcp/security-audit`, `webmastery-site-toolkit-for-mcp/database-health`

**Steps to reproduce**
1. As an Administrator behind a reverse proxy that terminates TLS, call `webmastery-site-toolkit-for-mcp/security-audit`.
2. Expected: `ssl` passes when `home_url()` is `https://…`.
3. Got: `fail` entry "Site is not using HTTPS"; with `WP_DEBUG_LOG` enabled, `debug_log.detail` contains e.g. `/var/www/html/wp-content/debug.log`.
4. Call `webmastery-site-toolkit-for-mcp/database-health`: `table_sizes[].table` values are `wp_posts`, `wp_<plugin>_*`, … (prefix + plugin-specific table names).

**Relevant code**
- `includes/class-security.php:45-49` — `$log_path` built from `WP_CONTENT_DIR`; `esc_html( $log_path )` in `detail`.
- `includes/class-security.php:65` — `is_ssl()`.
- `includes/class-database-health.php:209` — `'table' => (string) $row['table_name']` (prefixed).
- `includes/class-database-health.php:248-259` — `database_error()` returns `$wpdb->last_error` in the message.

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Report the log location relative to `WP_CONTENT_DIR` (e.g. `wp-content/debug.log`) or just "inside wp-content"; drop `esc_html()` (JSON, not HTML).
2. Base the SSL check on `'https' === wp_parse_url( home_url(), PHP_URL_SCHEME )` and `FORCE_SSL_ADMIN`, and label it "Site URL uses HTTPS".
3. Strip `$wpdb->prefix` from `table` (keep a boolean `is_core_table`), and return a generic `database_health_query_failed` message while `error_log()`-ing the detail.
4. Manifest: `assert_not_contains` for `/` in `debug_log.detail` and for the prefix in `table_sizes.0.table`.

**Additional context**
- Live (reference site): `database-health.table_sizes[].table` returned the real `$wpdb->prefix` on every row plus several `wp_<plugin>_*` tables whose names identify the plugins that created them — the prefix plus a plugin fingerprint. `security-audit` on that site passed the SSL check and did not hit the `debug_log` branch (logging disabled).

- Assessment finding: **A-12** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- GitHub issue: #111 (filed 2026-09-16)
- Related: #124 (`20-docs-drift-readme-readme-txt-plugin-header.md`).
