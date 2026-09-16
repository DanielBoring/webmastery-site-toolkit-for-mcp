---
name: Bug report
about: Something isn't working correctly
title: '[Bug] Taxonomy write abilities use global `manage_categories` instead of taxonomy caps; deleting the default category reports success'
labels: bug, priority: low
assignees: ''
---

**Describe the bug**
All six write abilities check `manage_categories` for both taxonomies. Core uses `$taxonomy->cap->edit_terms` / `manage_terms` / `delete_terms` plus the `edit_term` / `delete_term` meta-caps; the CPT class already resolves capabilities through the taxonomy object. For `category` and `post_tag` every one of those maps to `manage_categories` today, so behaviour is identical — until a site remaps `manage_post_tags` or a role editor grants tag management separately.

Separately, `wp_delete_term()` returns `0` for the default category without deleting it (`wp-includes/taxonomy.php:2052-2054`); the ability tests only `is_wp_error()` and returns `{ "id": …, "deleted": true }`.

**Ability name**
`webmastery-site-toolkit-for-mcp/create-category`, `create-tag`, `update-category`, `update-tag`, `delete-category`, `delete-tag`

**Steps to reproduce**
1. As an Editor call `webmastery-site-toolkit-for-mcp/delete-category` with `{ "category_id": <get_option('default_category')> }`.
2. Expected: an error such as `cannot_delete_default_category`.
3. Got: `success: true`, `data.deleted: true`; the category still exists and `get-category` still returns it.

**Relevant code**
- `includes/class-taxonomy.php:167-172`, `:242-247`, `:287-292` — `manage_categories` permission closures.
- `includes/class-taxonomy.php:279-285` — `wp_delete_term()` result only checked with `is_wp_error()`.
- `includes/class-custom-post-types.php:82-84`, `:322` — capability resolution through the taxonomy object (the pattern to reuse).
- Core: `wp-includes/taxonomy.php:2052-2054` (default category → `return 0`).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Resolve `get_taxonomy( $taxonomy )->cap->edit_terms` / `->delete_terms` in the permission callbacks and check `current_user_can( 'edit_term' | 'delete_term', $term_id )` in execute.
2. Treat a `0` / `false` return from `wp_delete_term()` as `delete_failed` (message: default category cannot be deleted), and refuse up front when `$id === (int) get_option( 'default_category' )`.
3. Manifest: add the default-category case and a denial case for each delete ability (none exist today).

**Additional context**
- Also part of #116 (`04-destructive-abilities-need-confirm-dry-run-and-bounds.md`): `delete-category` reassigns posts to the default category silently and is irreversible; `confirm: true` applies here too.
- Verified from source; not run live.

- Assessment finding: **A-13** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- GitHub issue: #117 (filed 2026-09-16)
- Related: #116; #120 (`19-close-test-coverage-gaps-ranked-by-blast-radius.md`).
