---
name: Bug report
about: Something isn't working correctly
title: '[Bug] Comment moderation abilities check global `moderate_comments` instead of per-object `edit_comment`'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
The four moderation abilities gate on the global `moderate_comments` capability only, in both the `permission_callback` and the execute body. WordPress core gates comment edits and status changes on the `edit_comment` meta-capability (wp-admin `comment.php`, REST `WP_REST_Comments_Controller::update_item_permissions_check`), which `map_meta_cap()` resolves to `edit_post` on the comment's parent post (`wp-includes/capabilities.php:554-575` in 6.9). `reply-comment` already performs the per-object check; the moderation abilities do not, so they grant more than wp-admin would to any role that holds `moderate_comments` without the matching post-edit capabilities.

This contradicts `docs/security-strategy.md` ("Writes and deletes → object-specific `edit_*` / `delete_*` checks"). The spec is right; the code is wrong.

**Ability name**
`webmastery-site-toolkit-for-mcp/update-comment`, `webmastery-site-toolkit-for-mcp/approve-comment`, `webmastery-site-toolkit-for-mcp/trash-comment`, `webmastery-site-toolkit-for-mcp/spam-comment` (and `webmastery-site-toolkit-for-mcp/list-comments` for the listing half)

**Steps to reproduce**
1. Create a role with `read` + `moderate_comments` but without `edit_others_posts` (a "comment moderator"), or use a CPT with its own capability type that the role does not hold.
2. As that user call `webmastery-site-toolkit-for-mcp/approve-comment` (or `trash-comment` / `spam-comment` / `update-comment`) with `{ "comment_id": <comment on a post the user cannot edit> }`.
3. Expected: `forbidden` — wp-admin `comment.php` and REST `POST /wp/v2/comments/<id>` both refuse the same user.
4. Got: success; the comment's status or content is changed.

**Relevant code**
- `includes/class-comments.php:85-93` — `moderate_permission()` closure: `current_user_can( 'moderate_comments' )` only, no `$input`, no comment lookup.
- `includes/class-comments.php:136-141` — `list-comments` permission callback (same check; acceptable for listing, matches `edit-comments.php`).
- `includes/class-comments.php:242-305` — `update-comment` execute body: loads the comment, validates content/status, calls `wp_update_comment()` and `wp_set_comment_status()` with no per-object capability check.
- `includes/class-comments.php:326-341` — `approve/trash/spam-comment` execute body: `get_comment()` then `wp_set_comment_status()` with no per-object check.
- `includes/class-comments.php:175` — `reply-comment` does it correctly: `current_user_can( 'edit_post', (int) $post->ID )` on the parent post.
- Core reference: `wp-includes/capabilities.php:554-575` (`case 'edit_comment'` → `map_meta_cap( 'edit_post', … )`).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Give `moderate_permission()` an `$input = []` signature, resolve `$comment_id = absint( $input['comment_id'] ?? 0 )`, return `not_found` when the comment does not exist, and require `current_user_can( 'edit_comment', $comment_id )` in addition to (or instead of) `moderate_comments`.
2. Repeat the `edit_comment` check at the top of each execute callback (same double-check pattern as `Webmastery_MCP_Media::upload_image_permission()`), so the boundary holds even if the adapter's call order changes.
3. Keep `moderate_comments` for `list-comments`; optionally filter listed comments by `current_user_can( 'read_post', $comment->comment_post_ID )` so comments on private posts the caller cannot read are not listed.
4. Tests: add a `comment_moderator` role in `tests/e2e/ability-runner.php` (next to `limited_editor`, `:515-525`) with `read` + `moderate_comments` only, and one denial case per ability in `tests/e2e/abilities-manifest.json` (`expect: failure`, `expect_error_code: forbidden`). Add the four abilities to `$required_failure_cases` in `scripts/validate-security-qa.php:113-132` so the coverage cannot regress.
5. Docs: no README change needed (the Comments row already says Editor); add a line to `docs/security-strategy.md` naming `edit_comment` as the required per-object check for comment writes.

**Additional context**
- Default Editor and Administrator roles already hold both `moderate_comments` and `edit_others_posts`, so they are unaffected; the exposure is custom roles, membership/role-editor plugins, and CPTs with dedicated capability types.
- Not exercised on the live reference site because the connected MCP account is an administrator; the finding is verified by reading the plugin and WordPress 6.9 core source.

- Assessment finding: **A-1** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- GitHub issue: #105 (filed 2026-09-16)
- Related: #120 (`19-close-test-coverage-gaps-ranked-by-blast-radius.md`) (adds the `comment_moderator` role); #119 (`18-shared-helpers-for-duplicated-permission-input-and-response-code.md`) (shared object-permission helper).
