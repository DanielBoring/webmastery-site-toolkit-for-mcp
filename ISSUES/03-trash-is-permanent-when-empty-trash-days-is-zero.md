---
name: Bug report
about: Something isn't working correctly
title: '[Bug] Trash abilities permanently delete when `EMPTY_TRASH_DAYS` is `0`, while docs and responses say "moved to trash"'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
Every trash path calls `wp_trash_post()` or `wp_set_comment_status( …, 'trash' )`. In WordPress core, `wp_trash_post()` returns `wp_delete_post( $post_id, true )` when `EMPTY_TRASH_DAYS` is falsy (`wp-includes/post.php:4009-4010`), and `wp_trash_comment()` returns `wp_delete_comment( $comment_id, true )` in the same case (`wp-includes/comment.php:1583-1585`). On a site with `define( 'EMPTY_TRASH_DAYS', 0 );` — common on hardened or ops-managed hosts — the plugin permanently deletes, yet:

- the ability descriptions say "Move … to trash",
- the response is `{ "id": <id>, "status": "trash" }`,
- README's Security section says "Deletes for posts and pages move content to trash", and readme.txt's FAQ repeats it,
- `restore-post` / `restore-page` cannot recover the item.

**Ability name**
`webmastery-site-toolkit-for-mcp/delete-post`, `webmastery-site-toolkit-for-mcp/delete-page`, `webmastery-site-toolkit-for-mcp/bulk-trash-posts`, `webmastery-site-toolkit-for-mcp/delete-cpt-{base}`, `webmastery-site-toolkit-for-mcp/trash-comment`, `webmastery-site-toolkit-for-mcp/update-comment` (with `status: trash`)

**Steps to reproduce**
1. Add `define( 'EMPTY_TRASH_DAYS', 0 );` to `wp-config.php`.
2. As an Author call `webmastery-site-toolkit-for-mcp/delete-post` with `{ "post_id": <own published post> }`.
3. Expected (per docs and response contract): post in trash, `restore-post` returns it to its previous status.
4. Got: the post row and its meta are permanently deleted; the response still says `"status": "trash"`; `restore-post` returns `not_found`.

**Relevant code**
- `includes/class-posts.php:725` — `wp_trash_post( $id )` in `bulk-trash-posts` (per-ID loop).
- `includes/class-posts.php:2200` — `wp_trash_post( $id )` in `delete-post` / `delete-page`; response literal `[ 'id' => $id, 'status' => 'trash' ]` at `:2206`.
- `includes/class-custom-post-types.php:687` — `wp_trash_post( $id )` in `delete-cpt-*`; response at `:693`.
- `includes/class-comments.php:334` — `wp_set_comment_status( $id, 'trash' )` in `trash-comment`; `:288` in `update-comment`.
- `includes/class-plugins.php:181-191` — the existing `force` gate for protected plugins, the idiom to reuse.
- Core: `wp-includes/post.php:4009-4010` (`if ( ! EMPTY_TRASH_DAYS ) return wp_delete_post( $post_id, true );`), `wp-includes/comment.php:1583-1585`.

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
Preferred:
1. Add a private helper, e.g. `Webmastery_MCP_Posts::trash_is_permanent(): bool` returning `defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS`.
2. In each trash path, when it returns true and the caller has not passed `force: true`, return `error_response( 'trash_disabled', 'Trash is disabled on this site (EMPTY_TRASH_DAYS=0); pass force=true to permanently delete.' )`. Add the optional boolean `force` to the affected input schemas with a description that names the consequence.
3. When proceeding, return `'status' => 'deleted', 'permanent' => true` instead of `'status' => 'trash'`, and set `annotations.destructive` (already `true`) plus a description sentence.

Minimum: keep the behaviour but return `status: deleted, permanent: true` and describe the condition in the ability description, README Security section and readme.txt FAQ.

Tests: the runner supports per-case `setup` (`tests/e2e/ability-runner.php:374-440`); add a case that defines `EMPTY_TRASH_DAYS = 0` for the duration of the call and asserts `expect_error_code: trash_disabled` without `force`, and `data.permanent: true` with it.

**Additional context**
- Blast radius: one malformed `bulk-trash-posts` call on such a site permanently removes every post the caller may delete, with a response that claims reversibility.
- Not reproducible on the reference site (trash is enabled there); verified against WordPress 6.9 core source.

- Assessment finding: **A-4** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- GitHub issue: #109 (filed 2026-09-16)
- Related: #116 (`04-destructive-abilities-need-confirm-dry-run-and-bounds.md`) (shares the `force`/`confirm` idiom and schema change); #124 (`20-docs-drift-readme-readme-txt-plugin-header.md`) (wording fix).
