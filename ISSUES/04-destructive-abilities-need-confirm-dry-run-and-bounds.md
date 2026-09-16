---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Add `confirm`, `dry_run` and `maxItems` guards to irreversible and bulk abilities'
labels: enhancement, priority: medium
assignees: ''
---

**Proposed ability name**
Enhancement to existing abilities: `webmastery-site-toolkit-for-mcp/delete-media`, `webmastery-site-toolkit-for-mcp/delete-category`, `webmastery-site-toolkit-for-mcp/delete-tag`, `webmastery-site-toolkit-for-mcp/bulk-trash-posts`, `webmastery-site-toolkit-for-mcp/bulk-publish-posts`

**What should it do?**
1. **Required `confirm: true`** on `delete-media`, `delete-category` and `delete-tag` (all irreversible) and on both bulk abilities. Add it to `input_schema.required`; reject with `missing_confirmation` in execute when absent or not exactly `true`.
2. **`maxItems: 100`** on `bulk_post_ids_schema()` plus an execute-time guard returning `too_many_ids` with the limit in `data`.
3. **`dry_run: true`** on `bulk-trash-posts` and `bulk-publish-posts`: run the per-ID type/capability/status checks and return the same `successes` / `failures` summary without calling `wp_trash_post()` / `wp_update_post()`; include `"dry_run": true` in the response.
4. **In-use check for `delete-media`**: reuse the logic of `Webmastery_MCP_Content_Hygiene::is_attachment_referenced()` (featured-image meta and content URL references) and refuse with `media_in_use` unless `force: true`; always return `in_use: bool` in the success payload.
5. Keep `annotations.destructive: true` on all of these (already set).

**Required WordPress capability**
Unchanged: `delete_post` (media), `manage_categories` (terms), `delete_posts` / `publish_posts` (bulk). The new inputs are safety interlocks, not capability changes.

**Why does an AI agent need this?**
An LLM caller can hallucinate an ID, or be steered by content it just read on the site, into a destructive call. On the live reference site `get-ability-info` for `bulk-trash-posts` shows `minItems: 1` and no `maxItems`, and in the MCP Adapter's default three-tool gateway (the configuration in the README Quickstart) per-ability annotations are not in the LLM's tool list — they are only visible after a separate `get-ability-info` call. A required, unambiguous `confirm` input is therefore the only guard that works in every transport mode, and `maxItems` bounds the blast radius of a single call. This is Track A item 8 of the self-audit.

**Relevant code**
- `includes/class-media.php:456-491` — `delete-media`; `wp_delete_attachment( $id, true )` at `:478` (force delete, no trash).
- `includes/class-taxonomy.php:255-298` — `delete-category` / `delete-tag`; `wp_delete_term()` at `:279`.
- `includes/class-posts.php:665-678` — `bulk_post_ids_schema()` (`minItems: 1`, no `maxItems`).
- `includes/class-posts.php:693-748` and `:751-832` — bulk execute loops (the per-ID check sequence to reuse for `dry_run`).
- `includes/class-content-hygiene.php:131-182` — `is_attachment_referenced()` to reuse for the in-use check.
- `includes/class-plugins.php:181-191`, `:294` — existing `force` gate and schema property, the idiom to copy.

**Additional context**
- Pair with `03-trash-is-permanent-when-empty-trash-days-is-zero.md` (`force`) and `05-annotate-untrusted-site-content-in-responses.md` in one "safer destructive abilities" changelog entry, because all three change input schemas.
- Manifest: update existing cases for these abilities to pass `confirm: true`; add negative cases without it (`expect_error_code: missing_confirmation`) and a `dry_run` case asserting no state change; add the abilities to `$required_failure_cases` in `scripts/validate-security-qa.php`.
- Docs: README Security section ("Deletes …") and readme.txt FAQ "Are write operations safe?" should describe `confirm` / `dry_run`.

- Assessment finding: **A-5** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `03-…`, `05-…`, `13-taxonomy-writes-use-global-cap-and-default-category-delete-reports-success.md`.
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
