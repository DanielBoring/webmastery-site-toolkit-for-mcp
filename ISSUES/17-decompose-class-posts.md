---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Split `includes/class-posts.php` (2,253 lines) into six cohesive classes'
labels: enhancement, priority: medium
assignees: ''
---

**Proposed ability name**
`Webmastery_MCP_Post_Access`, `Webmastery_MCP_Post_Meta`, `Webmastery_MCP_Content_Patch`, `Webmastery_MCP_Post_Revisions`, `Webmastery_MCP_Featured_Image`, `Webmastery_MCP_Bulk_Posts` (+ a slimmed `Webmastery_MCP_Posts`)

**What should it do?**
Behaviour-preserving extraction along the existing private-static seams. `Webmastery_MCP_Posts::register()` becomes a dispatcher; ability names, schemas and responses do not change, so the E2E manifest remains the regression net.

| New class / file | Moves from `class-posts.php` | ~Lines | Risk |
| --- | --- | --- | --- |
| `class-post-access.php` | `can_read_full_post` (58), `filter_readable_post_ids` (79), `query_readable_posts` (92), `permission` (543), `object_permission` (552), `create_permission` (567), `restore_permission` (586), `featured_image_permission` (602), `post_meta_permission` (623), `revision_target_permission` (639), `get_featured_image_target` (835), `get_content_target` (930), `content_permission` (948), `get_patch_content_target` (960), `patch_post_content_permission` (990) | ~330 | Low — pure functions of `$input` / `$post`; unit-testable with the existing bootstrap stubs. Later home for `class-custom-post-types.php:165-267` and `class-media.php:49-61,174-187`. |
| `class-post-meta.php` | constants (7-9), `writable_protected_meta_keys` (115) … `meta_write_error_response` (527); replace the 34 `if ( isset( $input['yoast_…'] ) )` blocks (366-467) with a table derived from `yoast_input_schema_props()` / `seopress_input_schema_props()`; `register_get/update/delete_post_meta` (1689-1873); share key tables with `class-seo.php:279-327` | ~560 | Low–Medium — move `tests/unit/PostsHelpersTest.php` with it. |
| `class-content-patch.php` | `content_hash` (865) … `find_block_paths_by_hash` (1078), `patch_content_by_heading` (1294), `patch_content_by_exact_match` (1341), `register_list_content_blocks` / `register_patch_content_block` / `register_patch_post_content` (1096-1481) | ~620 | Medium — write unit tests for `parse_block_path`, `replace_block_by_segments`, `patch_content_by_heading`, `patch_content_by_exact_match` first; fix issue 07 here. |
| `class-post-revisions.php` | `normalize_revision` (1560), `register_list_revisions` (1579), `register_restore_revision` (1633) | ~130 | Low |
| `class-featured-image.php` (or fold into `class-media.php`) | `register_set_featured_image` (1483), `register_remove_featured_image` (1529) | ~80 | Low |
| `class-bulk-posts.php` | `bulk_post_ids_schema` (665), `bulk_post_summary` (680), `register_bulk_trash_posts` (693), `register_bulk_publish_posts` (751) | ~170 | Low — natural home for issue 04's guards. |
| remaining `class-posts.php` | `normalize` (28), `register_post_type` (1875-2252) | ~420 | — |

Order: Access → Meta → Bulk / Revisions / Featured → Content_Patch. One PR per step; gates: `composer phpcs`, `composer qa:unit`, `bash scripts/e2e-test.sh contract`. Bootstrap: add `require_once` lines in `webmastery-site-toolkit-for-mcp.php:44-60`; registration order between classes does not matter (no cross-class dependency at register time).

**Required WordPress capability**
n/a

**Why does an AI agent need this?**
Reviewability of the security boundary: today the permission helpers, the meta allowlist and the block-patching algorithm live in one 2,253-line file, so a change to any of them is reviewed in the context of all of them. Smaller units also make the per-object checks (issues 01, 06, 10) easier to standardise.

**Relevant code**
- `includes/class-posts.php:11-26` — `register()` (becomes the dispatcher).
- `includes/class-posts.php:543-663` — permission-closure factories.
- `includes/class-posts.php:115-541` — meta allowlist, normalisation and write pipeline.
- `includes/class-posts.php:865-1481` — block/patch helpers and the three patch abilities.
- `webmastery-site-toolkit-for-mcp.php:44-60` — `require_once` list to extend.
- `tests/unit/PostsHelpersTest.php` — reflection into private helpers; update the class name when they move.

**Additional context**
- Estimated effort ~2 days; regression risk is concentrated in the Content_Patch move, which has the strongest E2E coverage (11 manifest cases).
- Do issue 16 first so the extracted classes import the shared response helper instead of carrying their own `error_response()`.
- Follow `.github/copilot-instructions.md`: update `tests/e2e/README.md` / `CONTRIBUTING.md` file maps, and log the change under `.github/REPOSITORY_CHANGELOG.md` (no user-facing behaviour change).

- Assessment finding: **B-2** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `16-…`; `18-…`; `19-…`; `07-patch-abilities-kses-entire-post-body.md`.
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
