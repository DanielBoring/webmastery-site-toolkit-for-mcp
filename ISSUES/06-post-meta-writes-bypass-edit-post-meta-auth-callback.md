---
name: Bug report
about: Something isn't working correctly
title: '[Bug] Post-meta read/write/delete bypass the `edit_post_meta` meta-cap and registered `auth_callback`s'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
`can_access_post_meta_key()` allows any key that is not `is_protected_meta()` or that is on the Yoast/SEOPress allowlist, and the write paths then call `update_post_meta()` / `delete_post_meta()` after only an `edit_post` check. Core's REST layer (`WP_REST_Meta_Fields::update_value`) additionally requires `current_user_can( 'edit_post_meta', $post_id, $key )`, which `map_meta_cap()` routes through the key's registered `auth_callback` via the `auth_post_meta_{$key}_for_{$subtype}` filter (`wp-includes/capabilities.php:424-500`). Keys that a plugin registered with a restrictive `auth_callback` (non-underscore keys) are therefore writable, deletable and readable by anyone with `edit_post`. The `$rest_keys` branch of `prepare_meta_writes()` has the same gap for REST-registered keys written through `create-*` / `update-*`.

**Ability name**
`webmastery-site-toolkit-for-mcp/update-post-meta`, `webmastery-site-toolkit-for-mcp/delete-post-meta`, `webmastery-site-toolkit-for-mcp/get-post-meta`, and the `meta` input of `create-post` / `create-page` / `update-post` / `update-page`

**Steps to reproduce**
1. In a mu-plugin register a gated key: `register_post_meta( 'post', 'access_level', [ 'show_in_rest' => true, 'single' => true, 'type' => 'string', 'auth_callback' => static fn() => current_user_can( 'manage_options' ) ] );`
2. As an Author call `webmastery-site-toolkit-for-mcp/update-post-meta` with `{ "post_id": <own post>, "meta_key": "access_level", "meta_value": "vip" }`.
3. Expected: `forbidden` (REST `PATCH /wp/v2/posts/<id>` with `meta.access_level` refuses the same user).
4. Got: `success: true`, `updated: true`; the value is stored. The same key is also written via `update-post` with `{ "meta": { "access_level": "vip" } }`.

**Relevant code**
- `includes/class-posts.php:176-178` — `can_access_post_meta_key()` (`! is_protected_meta() || allowlisted`).
- `includes/class-posts.php:1787-1797` — `update-post-meta` execute: `edit_post` only, then `update_post_meta()`.
- `includes/class-posts.php:1849-1854` — `delete-post-meta` execute: `edit_post` only, then `delete_post_meta()`.
- `includes/class-posts.php:1721-1733` — `get-post-meta` reads every non-protected key.
- `includes/class-posts.php:493-505` — `prepare_meta_writes()` `$rest_keys` branch (REST-registered keys written without `edit_post_meta`); `:516-525` `apply_meta_writes()`.
- `includes/class-posts.php:115-154` — `writable_protected_meta_keys()` allowlist (must remain: Yoast/SEOPress do not register these keys with auth callbacks).
- Core: `wp-includes/capabilities.php:424-500` (`edit_post_meta` → `auth_{type}_meta_{key}_for_{subtype}` filter).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. For keys **not** in `writable_protected_meta_keys()`, require `current_user_can( 'edit_post_meta', $post_id, $key )` before writes and `current_user_can( 'delete_post_meta', $post_id, $key )` before deletes, in `update-post-meta`, `delete-post-meta` and the `$rest_keys` branch of `prepare_meta_writes()` (report refused keys under `not_written` with `reason: 'forbidden'`).
2. For reads, consider limiting `get-post-meta` to keys the caller could edit (`edit_post_meta`) or to REST-registered + non-protected keys; at minimum document that non-protected keys are returned in full.
3. Keep the Yoast/SEOPress allowlist as the explicit exception and say why in a code comment.
4. Tests: add a fixture in `tests/e2e/ability-runner.php` that registers a key with a `manage_options` `auth_callback`, and manifest cases for `update-post-meta`, `delete-post-meta` and `update-post` (`meta`) as `editor` expecting `forbidden` / `not_written`.

**Additional context**
- Scope on a vanilla install is "whatever the Custom Fields metabox already allows an editor to do"; the auth-callback bypass is the real gap and depends on which plugins are installed.
- Live reference site: `get-post-meta` on a real post returned `_yoast_wpseo_*` (allowlisted) and `footnotes` (non-protected); no gated third-party key was present to demonstrate the bypass, so this is verified from plugin and core source.

- Assessment finding: **A-2** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `18-shared-helpers-for-duplicated-permission-input-and-response-code.md`; `17-decompose-class-posts.md` (meta code moves to `class-post-meta.php`).
