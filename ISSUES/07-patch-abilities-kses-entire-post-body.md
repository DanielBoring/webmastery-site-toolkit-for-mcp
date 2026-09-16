---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `patch-content-block` / `patch-post-content` run `wp_kses_post()` over the whole post body, stripping markup from untouched blocks'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
After replacing one block or one section, both abilities re-serialise the entire post content and pass it through `wp_kses_post()` before `wp_update_post()`. For callers with `unfiltered_html` (Editors and Administrators on single-site), WordPress core does **not** filter existing content on save, so `<iframe>`, `<script>`, `<style>`, `<form>`, `<svg>` and `on*` attributes inside Custom HTML or embed blocks that the caller never touched are silently removed. The `exact` target needle is also kses'd, so content that contains such markup can never match and the ability returns `target_not_found` for text that visibly exists. Sanitising the *replacement* fragment is correct and should stay.

**Ability name**
`webmastery-site-toolkit-for-mcp/patch-content-block`, `webmastery-site-toolkit-for-mcp/patch-post-content`

**Steps to reproduce**
1. As an Editor (has `unfiltered_html`) create a post containing a Custom HTML block with `<iframe src="https://www.youtube.com/embed/…"></iframe>` and a separate paragraph block.
2. Call `webmastery-site-toolkit-for-mcp/list-content-blocks` to get the paragraph's `block_path`, then `webmastery-site-toolkit-for-mcp/patch-content-block` with `{ "content_id": <id>, "content_type": "post", "target_type": "block_path", "block_path": "<paragraph path>", "replacement_content": "<!-- wp:paragraph --><p>New text</p><!-- /wp:paragraph -->" }`.
3. Expected: the paragraph is replaced; the iframe block is byte-identical.
4. Got: paragraph replaced **and** the `<iframe>` stripped from the other block; `content_hash_after` differs in both blocks; no error.
5. Variant: `patch-post-content` with `target_type: "exact"` and `old_content` equal to the iframe markup returns `target_not_found`.

**Relevant code**
- `includes/class-posts.php:1256` — `'post_content' => wp_kses_post( serialize_blocks( $blocks ) )` in `patch-content-block`.
- `includes/class-posts.php:1450` — `'post_content' => wp_kses_post( $patch['content'] )` in `patch-post-content`.
- `includes/class-posts.php:1437` — `wp_kses_post( $input['old_content'] )` on the `exact` needle.
- `includes/class-posts.php:1242`, `:1418` — kses of the replacement fragment (correct; keep).
- `includes/class-posts.php:2126` — `update-post` also applies `wp_kses_post()` to caller-supplied full content (acceptable: the caller supplied it).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Sanitise only the replacement fragment (`$replacement_blocks[0]` / `$replacement_content`) and pass the re-serialised whole to `wp_update_post()` without an outer `wp_kses_post()`. Core's `content_save_pre` / `wp_filter_post_kses` still applies for users without `unfiltered_html`, so no privilege is gained.
2. Compare the `exact` needle against raw content; kses the *replacement* only.
3. Optionally assert in a unit test that untouched blocks are byte-identical before/after a patch (`tests/unit/`), using the block helpers once they are extracted (issue 17).
4. Manifest: add an `unfiltered_html` case (editor) with an iframe block, asserting via `list-content-blocks` that the untouched block's hash is unchanged after the patch.

**Additional context**
- Silent data loss on a routine edit is the consequence: the editor asked for one heading fix and lost the embedded video and the newsletter form.
- Verified by reading the code; the patch abilities were not run on the live reference site (writes were out of scope for the review).

- Assessment finding: **A-6** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `17-decompose-class-posts.md` (the patch code moves to `class-content-patch.php`; fix this in the same PR).
