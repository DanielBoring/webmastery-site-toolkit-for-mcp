---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Standardise on one error contract via a shared response helper, and make it an MCP error with a code'
labels: enhancement, priority: high
assignees: ''
---

**Proposed ability name**
Cross-cutting (all 75+ abilities); new `includes/class-response.php`

**What should it do?**
Today failures come back in three incompatible shapes, and — verified against MCP Adapter v0.5.0 source (`includes/Handlers/Tools/ToolsHandler.php:207-231`) and live on the reference site — they reach the client differently:

| Plugin returns | Where | What the client receives |
| --- | --- | --- |
| (a) `[ 'success' => false, 'error' => 'Post not found.' ]` | ~30 sites in posts/media/seo/taxonomy/comments/users/content-hygiene | `isError: true`, message only (explicit compat branch, adapter `:222-231`). **No code.** |
| (b) `[ 'success' => false, 'error' => [ 'code' => …, 'message' => … ] ]` | `error_response()` ×4 copies (~86 call sites) + inline literals in `class-seo.php` | **`isError: false`** — the compat branch requires `is_string( $result['error'] )`, so the structured shape is delivered as a successful `structuredContent`. Code survives, error flag lost. |
| (c) `WP_Error` from the execute callback | plugins, database-health, content-hygiene, site-kit | `isError: true`, message only (`create_error_result()`, adapter `:325-343`). Code and `data` logged server-side and dropped. |

So the same "not found" is an MCP error from `get-post`, a success from `list-content-blocks`, and a code-less error from `activate-plugin`. Codes also differ for the same condition (`forbidden` vs `missing_capability`; `not_found` vs `plugin_not_found`). The E2E runner needs three branches to read them and cannot assert `expect_error_code` for shape (a); the unit test `test_error_response_shape_is_stable` pins shape (b) — the one that never becomes an MCP error.

Proposal:
1. `Webmastery_MCP_Response::error( string $code, string $message, array $details = [] ): array` and `::ok( array $data ): array` in `includes/class-response.php`; delete the four private `error_response()` copies.
2. **One shape everywhere** — the structured array (b), because it is the only shape the adapter passes the code through.
3. In the bootstrap, add a `mcp_adapter_tool_call_result` filter (adapter `ToolsHandler.php:200`) that converts `success:false` results into `new WP_Error( $code, "[{$code}] {$message}", $details )`, so MCP clients receive `isError: true` **with the code in the text**, while Abilities-API / REST consumers keep the structured shape.
4. Fixed code vocabulary: `forbidden`, `not_found`, `invalid_input`, `precondition_failed`, `conflict`, `unsupported`, `upstream_failed`. Bulk per-item failures keep inline `{ id, code, message }`.
5. Manifest assertion `expect_error_shape` so the runner enforces the contract; update `e2e_result_error_code()` accordingly.
6. Upstream: file an adapter issue asking `create_error_result()` to carry `structuredContent` with the code, and to treat the structured shape as an error.

**Required WordPress capability**
n/a (no capability change).

**Why does an AI agent need this?**
An agent has to branch on error codes to recover (retry, reload hashes, ask the user). Three shapes, two vocabularies, and an adapter that flags only some of them as errors make that unreliable — a real MCP usability defect. Do this before splitting `class-posts.php` (issue 17) so the shared helper is what the extracted classes import.

**Relevant code**
- `includes/class-posts.php:849` / `class-custom-post-types.php:222` / `class-media.php:93` / `class-comments.php:30` — four identical `error_response()` helpers (shape b).
- Shape (a) sites: `class-posts.php:1958,1961,2007,2041,2106,2109,2148,2194,2232`; `class-media.php:257,260,418,421,437,472,475,481`; `class-seo.php:55,58,605,610,641`; `class-taxonomy.php:60,102,160,210,231,237,276,282`; `class-comments.php:331,337`; `class-users.php:176`; `class-content-hygiene.php:221`.
- Shape (b) inline literals: `class-seo.php:428,431,461,503,506`.
- Shape (c): `class-plugins.php:51,72,131,136,173,178` (and `error()` at `:562-571` — the best existing model); `class-database-health.php:34-54`; `class-content-hygiene.php:94,226`; `class-site-kit.php:144-149,174,195,207-231`.
- `tests/e2e/ability-runner.php:305-331` — `e2e_result_error_code()` / `e2e_result_error_message()` handle all three shapes.
- `tests/unit/PostsHelpersTest.php` — `test_error_response_shape_is_stable`.
- MCP Adapter 0.5.0: `includes/Handlers/Tools/ToolsHandler.php:148,189,200,207-231,325-343`.

**Additional context**
- Live confirmation (reference site, adapter 0.5.0, via the execute-ability gateway): `get-post-meta` with an invalid key returned `{"success":true,"data":{"success":false,"error":{"code":"invalid_meta_key",…}}}` — an execute-path error delivered as a successful call — while `get-post` with a missing ID returned a bare MCP error `Post not found.` with no code. Every response is also double-wrapped (`success/data` twice) through that gateway (`ASSESSMENT.md` B-12); decide in the same change whether the plugin's own `success` envelope is still needed.
- Breaking for clients that string-match `error`; call it out in the 2.6.0 upgrade notice.

- Assessment finding: **B-1 (and B-12)** in `ASSESSMENT.md` (severity High → label `priority: high`).
- GitHub issue: #118 (filed 2026-09-16)
- Related: #127 (`17-decompose-class-posts.md`); #119 (`18-shared-helpers-for-duplicated-permission-input-and-response-code.md`); #120 (`19-close-test-coverage-gaps-ranked-by-blast-radius.md`).
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
