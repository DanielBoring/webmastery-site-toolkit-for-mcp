---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `update-cpt-*` validates taxonomy assignment capability after `wp_update_post()` has already persisted changes'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
In the generated CPT update ability, `wp_update_post()` runs first and `assign_taxonomy_terms()` — which performs `validate_taxonomy_terms()` (taxonomy registered for this type, caller holds the taxonomy's `assign_terms` capability) — runs afterwards. A caller who lacks `assign_terms`, or who names a taxonomy not attached to the type, receives `forbidden` / `invalid_taxonomy` **after** title, content, excerpt, slug, status and parent have already been saved. The create ability validates before inserting, so the two abilities disagree.

This is a "late per-object check / partial write" defect, not a privilege escalation: the publish-capability check still happens before the write.

**Ability name**
`webmastery-site-toolkit-for-mcp/update-cpt-{base}` (generated per eligible public custom post type, e.g. `update-cpt-mcp-book` in the E2E fixture)

**Steps to reproduce**
1. Use the E2E `limited_book_manager` role (`tests/e2e/ability-runner.php:527-536`: has `edit_mcp_books`, lacks `assign_mcp_genres`).
2. Call `webmastery-site-toolkit-for-mcp/update-cpt-mcp-book` with `{ "id": <book id>, "title": "Changed by test", "taxonomy_terms": { "mcp_genre": [<term id>] } }`.
3. Expected: `forbidden`, and the book's title is unchanged.
4. Got: `forbidden`, but `get-cpt-mcp-book` now returns the title "Changed by test".

**Relevant code**
- `includes/class-custom-post-types.php:636` — `wp_update_post( wp_slash( $args ), true )` in the update execute callback.
- `includes/class-custom-post-types.php:642` — `assign_taxonomy_terms()` called after the write.
- `includes/class-custom-post-types.php:305-329` — `validate_taxonomy_terms()` (registered-taxonomy and `assign_terms` checks).
- `includes/class-custom-post-types.php:331-356` — `assign_taxonomy_terms()` (calls the validator, then `wp_set_object_terms()`).
- `includes/class-custom-post-types.php:567` — the create ability validates **before** `wp_insert_post()` (`:576`); this is the ordering to copy.
- `includes/class-custom-post-types.php:629` — publish-capability check that correctly precedes the write.

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Move `$validated_terms = self::validate_taxonomy_terms( $post_type_name, $input['taxonomy_terms'] ?? [] );` to immediately after the publish-capability check (`:629-631`) and return `self::error_response( … )` on `WP_Error` before `wp_update_post()`.
2. Keep `assign_taxonomy_terms()` after the write (it re-validates cheaply), or add a `$skip_validation` argument to avoid the double query.
3. Tests: add a manifest case for `update-cpt-mcp-book` as `limited_book_manager` with `taxonomy_terms`, `expect: failure`, followed by a `get-cpt-mcp-book` case asserting `data.title` is unchanged (the runner supports ordered cases with fixture placeholders).

**Additional context**
- An agent that receives the error will retry or attempt to "undo" a change it believes never happened; the partial write is what makes this worth a fix in 2.6.0 alongside the other authorization items.
- Verified by reading the code; not run live (no eligible CPTs on the reference site).

- Assessment finding: **A-3** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- GitHub issue: #107 (filed 2026-09-16)
- Related: #119 (`18-shared-helpers-for-duplicated-permission-input-and-response-code.md`) (one shared create/update pipeline would make the ordering impossible to get wrong twice).
