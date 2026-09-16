---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Mark site-stored content in responses as untrusted, stop interpolating it into messages, and document the agent threat model'
labels: enhancement, priority: medium
assignees: ''
---

**Proposed ability name**
Cross-cutting: post/page/CPT/revision responses, `webmastery-site-toolkit-for-mcp/list-comments`, `list-media` / `get-media`, `seo-analyze-post`, `get-yoast-metadata`, `user-access-audit`

**What should it do?**
1. Add a field-level marker for untrusted content. Options: an `untrusted_fields` array in each response (e.g. `["content","excerpt","title"]`), or `description: "Untrusted site content"` on those properties in an `output_schema`. Affected normalisers: post `content`/`excerpt`/`title`; revision bodies; comment body, `author`, `author_email`, `author_url`; media `title`, `caption`, `alt_text`; raw Yoast head HTML/JSON; application-password `app_name` (user-chosen text).
2. Never build human-readable `message` strings from stored content. `seo-analyze-post` interpolates the stored focus keyword into `message` ("Focus keyword \"…\" found in title."); keep it in a data field only.
3. Optional `content_format: "text"` input on list abilities that returns `wp_strip_all_tags()` output instead of raw HTML/blocks (see also `11-…` note in `ASSESSMENT.md` B-11 about a `fields: summary` mode).
4. Keep `meta.annotations` accurate as abilities change. MCP Adapter 0.5.0 does forward them (`includes/Domain/Tools/RegisterAbilityAsMcpTool.php:141-152` maps them via `McpAnnotationMapper` and sets `annotations.title` from the label); confirm the emitted hint names once with `tools/list`.
5. Add an "Agent threat model" section to `docs/security-strategy.md`: prompt injection through stored content is in scope; the mitigations are confirmation inputs (issue 04), annotations and field markers — not content filtering.

**Required WordPress capability**
None (read-only enhancement; no capability changes).

**Why does an AI agent need this?**
Anyone who can leave a comment, register a username, submit a guest post, or name an application password on their own account can put text in front of the agent. The same agent session can call `bulk-trash-posts`, `delete-media`, `deactivate-plugin` (`force`) and `restore-revision`. On the live reference site a single `list-posts` item is 55 KB of raw post body, so the untrusted text is not a corner case — it is most of every listing response. Explicit markers let clients wrap or de-emphasise that content, and confirmation inputs stop a steered call from being final.

**Relevant code**
- `includes/class-posts.php:28-56` — `normalize()` (`content`, `excerpt`, `title`).
- `includes/class-posts.php:1560-1577` — `normalize_revision()` (full revision bodies).
- `includes/class-custom-post-types.php:124-163` — CPT `normalize_post()`.
- `includes/class-comments.php:16-28` — comment `normalize()` (body, `author`, `author_email`, `author_url`).
- `includes/class-media.php:15-47` — media `normalize()` (`title`, `caption`, `alt_text`).
- `includes/class-seo.php:122-124` — stored focus keyword interpolated into `message`; `:359-409` raw Yoast head passthrough.
- `includes/class-users.php:52-65`, `:86-98` — `app_name` and admin account fields.
- MCP Adapter 0.5.0: `includes/Domain/Tools/RegisterAbilityAsMcpTool.php:141-152` (annotation forwarding, verified).

**Additional context**
- Gateway-mode caveat: with the adapter's default `discover` / `get-info` / `execute` gateway, annotations are one lookup away from the LLM; field-level markers inside the response travel with the data regardless of transport.
- Exact MCP hint key names emitted by `McpAnnotationMapper` were not inspected (the class was not at the path probed); one `tools/list` call in per-tool mode will show them.

- Assessment finding: **A-14** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `04-destructive-abilities-need-confirm-dry-run-and-bounds.md`; `16-single-error-contract-across-abilities.md`.
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
