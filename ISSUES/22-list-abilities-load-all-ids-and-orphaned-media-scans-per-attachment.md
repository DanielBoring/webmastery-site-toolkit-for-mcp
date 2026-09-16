---
name: Bug report
about: Something isn't working correctly
title: '[Bug] List abilities load every matching ID and per-object check each; `list-orphaned-media` runs 2–3 queries per attachment including full-table `LIKE` scans'
labels: bug, priority: medium
assignees: ''
---

**Describe the bug**
To produce exact filtered totals, `query_readable_posts()` and its siblings query with `posts_per_page => -1`, then call `get_post()` and `current_user_can()` for every ID before slicing the requested page. `list-orphaned-media` loads all unattached attachments and, per attachment, runs a `_thumbnail_id` lookup plus one `post_content LIKE '%url%'` scan of `wp_posts` per candidate URL. Correctness of the totals is the reason for the pattern and is worth keeping; the cost is unbounded and any Author can trigger it repeatedly.

Related payload problem (`ASSESSMENT.md` B-11): `normalize()` always includes full `post_content`, so on the reference site a `list-posts` call with `per_page: 1` returned **55,020 characters** for one post; the default `per_page: 20` would be roughly 1 MB.

**Ability name**
`webmastery-site-toolkit-for-mcp/list-posts`, `list-pages`, `list-cpt-{base}`, `list-media`, `get-seo-scores`, `get-readability-scores`, `list-orphaned-media`

**Steps to reproduce**
1. On a site with ~20k posts / ~10k attachments, as an Author call `webmastery-site-toolkit-for-mcp/list-posts` with `{ "per_page": 20 }` or `webmastery-site-toolkit-for-mcp/list-orphaned-media` with `{}`.
2. Expected: a bounded number of queries proportional to `per_page`.
3. Got: ~20k `map_meta_cap` evaluations for `list-posts`; ~30k queries including ~10k full scans of `wp_posts` for `list-orphaned-media`.
4. Reference site (75 posts): `list-posts` `per_page: 1` → 55 KB response.

**Relevant code**
- `includes/class-posts.php:92-113` — `query_readable_posts()` (`posts_per_page => -1`, per-ID `get_post()` + capability check).
- `includes/class-custom-post-types.php:199-220` — CPT copy.
- `includes/class-media.php:63-91` — `query_readable_attachments()`.
- `includes/class-seo.php:615-660` — `execute_score_list()`.
- `includes/class-content-hygiene.php:69-101` — `execute_list_orphaned_media()`; `:131-182` `is_attachment_referenced()` (`LIKE '%…%'` per URL).
- `includes/class-posts.php:28-56` — `normalize()` always returns `content`.

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Over-fetch a bounded window (e.g. `posts_per_page => 5 * $per_page`, `paged` from the requested page), filter, and return `total_is_estimate: true` when the window was full; or use `WP_Query`'s `perm => 'readable'` for the public/private split and per-object-check only non-public statuses.
2. For orphaned media: one query for all `_thumbnail_id` values, batched `LIKE … OR …` over 50 URLs at a time, or a cron-maintained index; cap candidates per call.
3. Add `fields: "summary" | "full"` (default `summary`: omit `content`, keep `excerpt`) to list abilities and `list-revisions`; return `content` only from `get-*` (B-11).
4. Add a per-call query-count assertion to the E2E runner for `list-posts` (e.g. via `SAVEQUERIES`) to keep it bounded.

**Additional context**
- Availability finding (Medium): no data exposure, but repeatable by Author-level accounts and proportional to library size.
- Live: verified the 55 KB single-item payload on the reference site; query counts inferred from code.

- Assessment finding: **B-7 (and B-11)** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `05-…` (untrusted content volume); `18-…` (shared `query_readable` helper).
