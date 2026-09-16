---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `wp_slash()` applied to some writes and not others, so backslashes are lost on certain paths'
labels: bug, priority: low
assignees: ''
---

**Describe the bug**
Core's `update_post_meta()` and `wp_update_post()` expect slashed input and unslash it. `apply_meta_writes()` calls `update_post_meta()` without `wp_slash()`, while `update-post-meta` slashes; `update-media` and `upload-image` call `wp_update_post()` without `wp_slash()`, while post updates slash. Values containing backslashes therefore lose them on some paths and round-trip on others — exactly the kind of inconsistency an agent notices ("I set X, I read back Y").

**Ability name**
`webmastery-site-toolkit-for-mcp/create-post`, `create-page`, `update-post`, `update-page` (meta writes), `update-media`, `upload-image`

**Steps to reproduce**
1. Call `webmastery-site-toolkit-for-mcp/update-post` with `{ "post_id": <id>, "yoast_meta_description": "C:\\path\\to" }` then `webmastery-site-toolkit-for-mcp/get-post-meta` with `{ "post_id": <id>, "meta_key": "_yoast_wpseo_metadesc" }`.
2. Expected: the value round-trips.
3. Got: backslashes removed. The same value written via `update-post-meta` round-trips.
4. Repeat with `update-media` `title` containing a backslash.

**Relevant code**
- `includes/class-posts.php:516-525` — `apply_meta_writes()` → `update_post_meta( $post_id, $key, $value )` (no slash).
- `includes/class-posts.php:1797` — `update_post_meta( $post_id, $key, wp_slash( $value ) )` (slashed).
- `includes/class-media.php:374`, `:434` — `wp_update_post( $post_update )` / `wp_update_post( $args )` (no slash).
- `includes/class-posts.php:2145` — `wp_update_post( wp_slash( $args ), true )` (slashed).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
Route all writes through the shared input helper from issue 18 that slashes exactly once (or add `wp_slash()` at the four sites above), and add a unit/manifest case with a backslash value for meta, title and caption.

**Additional context**
- Cosmetic data-integrity issue; no security impact.
- Verified from source; not run live (writes were out of scope).

- Assessment finding: **B-8** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- Related: `18-shared-helpers-for-duplicated-permission-input-and-response-code.md`.
