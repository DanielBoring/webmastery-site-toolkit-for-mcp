---
name: Bug report
about: Something isn't working correctly
title: '[Bug] Unparseable `scheduled_date` with `status: future` publishes the post immediately instead of erroring'
labels: bug, priority: low
assignees: ''
---

**Describe the bug**
`scheduled_date` is passed through `strtotime()` and `wp_date()` with no validation. `strtotime()` returns `false` for unparseable input; `wp_date( 'Y-m-d H:i:s', false )` returns `false` because a non-numeric timestamp is rejected (`wp-includes/functions.php:246-249`); `wp_insert_post()` treats an empty `post_date` as "now"; and a `future` post whose GMT date is within 60 seconds of now is switched to `publish` (`wp-includes/post.php:4712-4715`). The ability returns success with `status: publish`. `scheduled_date` is also honoured for any status, so `post_date` can be back-dated on a draft without the `edit_date` semantics core applies in the REST API.

**Ability name**
`webmastery-site-toolkit-for-mcp/create-post`, `create-page`, `update-post`, `update-page`, `create-cpt-{base}`, `update-cpt-{base}`

**Steps to reproduce**
1. As an Editor call `webmastery-site-toolkit-for-mcp/create-post` with `{ "title": "T", "content": "C", "status": "future", "scheduled_date": "next tuesday at nine-ish" }`.
2. Expected: `invalid_scheduled_date` error, nothing created.
3. Got: `success: true`, `data.status: "publish"` — the post is live immediately.
4. Variant: `update-post` with `{ "post_id": <draft>, "scheduled_date": "2001-01-01T00:00:00" }` back-dates the draft silently.

**Relevant code**
- `includes/class-posts.php:2024-2027` — create: `wp_date( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $input['scheduled_date'] ) ) )`.
- `includes/class-posts.php:2137-2140` — update: same.
- `includes/class-custom-post-types.php:394-397` — `sanitized_post_args()`: same for CPTs.
- Core: `wp-includes/functions.php:246-249` (`wp_date()` returns `false` for non-numeric timestamps); `wp-includes/post.php:4712-4715` (`future` → `publish` when the date is not in the future).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Add a shared helper (e.g. `Webmastery_MCP_Input::schedule( array $input )`) that returns `WP_Error( 'invalid_scheduled_date' )` when `strtotime()` is `false`, requires `scheduled_date` when `status === 'future'` (`missing_scheduled_date`), requires it to be in the future (`scheduled_date_in_past`), and returns `post_date` / `post_date_gmt` pairs.
2. Ignore `scheduled_date` for statuses other than `future` (or document that it sets `post_date`), matching REST's `date` semantics.
3. Manifest: cases for bad date, missing date with `status: future`, and past date, each `expect: failure` with the new codes.

**Additional context**
- Requires `publish_posts`, so there is no privilege escalation — the harm is an agent that mis-formats a date and publishes instead of scheduling.
- Verified against WordPress 6.9 core source; not run live.

- Assessment finding: **A-8** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- GitHub issue: #113 (filed 2026-09-16)
- Related: #119 (`18-shared-helpers-for-duplicated-permission-input-and-response-code.md`) (the helper removes three copies of this block).
