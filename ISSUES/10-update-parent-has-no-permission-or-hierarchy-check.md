---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `parent` on update paths skips the edit-permission check that create performs and allows cycles / wrong types'
labels: bug, priority: low
assignees: ''
---

**Describe the bug**
`create-page` checks `edit_post` on the parent and `create-cpt-*` does likewise, but `update-page` and `update-cpt-*` set `post_parent` with no check. Nothing verifies that the parent is the same post type, that the type is hierarchical, or that the parent is not the post itself or one of its descendants (a cycle). Because no `input_schema` sets `additionalProperties: false`, `parent` is also accepted by non-hierarchical CPT updates (the schema advertises it only for hierarchical types) and by `create-post`.

**Ability name**
`webmastery-site-toolkit-for-mcp/update-page`, `webmastery-site-toolkit-for-mcp/update-cpt-{base}` (and `create-post` / non-hierarchical CPT updates accept `parent` too)

**Steps to reproduce**
1. As an Author who can edit page A but not page B, call `webmastery-site-toolkit-for-mcp/update-page` with `{ "page_id": A, "parent": B }`.
2. Expected: `forbidden` (the create path refuses the same input).
3. Got: success; A is now a child of B, its permalink changes, and it appears under B in the admin tree.
4. Variant: `{ "page_id": A, "parent": A }` creates a self-reference; `{ "page_id": A, "parent": <child of A> }` creates a cycle.

**Relevant code**
- `includes/class-posts.php:2141-2143` — `update-page`: `$args['post_parent'] = absint( $input['parent'] )` with no check.
- `includes/class-posts.php:579` — `create_permission()` checks `edit_post` on the parent (the behaviour to copy).
- `includes/class-custom-post-types.php:398-400` — `sanitized_post_args()` sets `post_parent` for any input; `:284` create checks the parent; `:369-371` schema exposes `parent` only for hierarchical types.

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Add one shared helper, e.g. `validate_parent( WP_Post $post, int $parent_id ): true|WP_Error`: `0` is always allowed; otherwise the parent must exist, be the same post type, the type must be hierarchical, the caller must hold `edit_post` on the parent, and the parent must not be `$post->ID` or in `get_post_ancestors( $parent_id )`.
2. Call it from create and update in both classes before the write; return `invalid_parent` / `forbidden`.
3. Set `additionalProperties: false` on the schemas whose property set is closed so `parent` cannot be smuggled into non-hierarchical types.
4. Manifest: denial case for `update-page` with a parent the `limited_editor` cannot edit; a self-parent case expecting `invalid_parent`.

**Additional context**
- Low impact: mis-parenting and permalink churn, plus the theoretical cycle that can wedge hierarchy walkers.
- Verified from source; not run live.

- Assessment finding: **A-10** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- GitHub issue: #106 (filed 2026-09-16)
- Related: #126 (`24-raise-phpstan-level-and-close-schema-only-validation.md`) (`additionalProperties: false`); #119 (shared input helpers).
