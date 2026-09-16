---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Extract shared helpers for the permission, input-normalisation, list-query and response blocks duplicated across the 17 classes'
labels: enhancement, priority: medium
assignees: ''
---

**Proposed ability name**
New `includes/class-permissions.php`, `includes/class-input.php`, `includes/class-response.php` (issue 16); reuse of `Webmastery_MCP_Plugins` helpers

**What should it do?**
Consolidate the following duplicated blocks into shared helpers, one PR per row where practical:

| Pattern | Copies | Proposed helper |
| --- | --- | --- |
| `error_response()` | `class-posts.php:849`, `class-custom-post-types.php:222`, `class-media.php:93`, `class-comments.php:30` (identical) | `Webmastery_MCP_Response::error` (issue 16) |
| `permission( $cap )` closure | `class-posts.php:543`, `class-media.php:49`, `class-content-hygiene.php:28`, `class-custom-post-types.php:238`; hand-written `manage_options` closures in `class-health.php:13`, `class-security.php:13`, `class-database-health.php:23`, `class-backup-status.php:33`, `class-performance-status.php:38`, `class-site-info.php:21`, `class-seo.php:199`, `class-site-kit.php:90` | `Webmastery_MCP_Permissions::cap( string $cap )`, `::admin()` |
| `object_permission()` | `class-posts.php:552`, `class-custom-post-types.php:250`, `class-media.php:174` | `Webmastery_MCP_Permissions::object( $type, $input_key, $cap )` |
| `can_read_full_post` / `filter_readable_post_ids` / `query_readable_posts` | `class-posts.php:58-113`, `class-custom-post-types.php:165-220`, `class-media.php:58-91` | `Webmastery_MCP_Post_Access::query_readable( WP_Post_Type $type, array $args, int $page, int $per_page )` |
| list-argument building + `edit_others_*` author restriction | `class-posts.php:1903-1927`, `class-custom-post-types.php:478-499`, `class-seo.php:613-636`, `class-media.php:204-224` | `Webmastery_MCP_Post_Access::list_args( $input, $type )` |
| `normalize( $post )` | `class-posts.php:28-56`, `class-custom-post-types.php:124-163` | one normaliser with optional taxonomy map (and the `fields: summary` mode from `ASSESSMENT.md` B-11) |
| `scheduled_date` handling | `class-posts.php:2024-2027`, `:2137-2140`, `class-custom-post-types.php:394-397` | `Webmastery_MCP_Input::schedule( $input )` (fixes issue 08 once) |
| `get_active_plugin_basenames()` | `class-backup-status.php:195-209`, `class-performance-status.php:124-138` (identical); `class-seo.php:542`, `class-site-kit.php:257-260` reimplement "is plugin active" | `Webmastery_MCP_Plugins::active_basenames()` |
| Yoast / SEOPress key tables | `class-posts.php:115-154` vs `class-seo.php:279-327` | single source in `class-post-meta.php` |
| pagination clamp `min( max( 1, (int) … ), 100 )` | `class-posts.php:1925-1926`, `class-custom-post-types.php:497-498`, `class-media.php:223-224`, `class-content-hygiene.php:20-26`, `class-seo.php:613-614`, `class-users.php:117-118`, `class-comments.php:112-113` | `Webmastery_MCP_Input::pagination( $input, $default, $max )` |
| `wp_slash()` applied inconsistently | `class-posts.php:520` vs `:1797`; `class-media.php:374,434` vs `class-posts.php:2145` | write through one helper that slashes exactly once (issue 23) |
| `array()` vs `[]` | long syntax in `class-backup-status.php`, `class-performance-status.php`, `class-webmaster-verification.php`; short elsewhere | pick one and enforce in `phpcs.xml.dist` (`Generic.Arrays.DisallowLongArraySyntax`) |

**Required WordPress capability**
n/a

**Why does an AI agent need this?**
Every copy is a place where a security fix (issues 01, 06, 08, 10) has to be applied N times; the E2E manifest catches divergence only where a case happens to exist. Shared helpers make the next boundary change a one-line diff.

**Relevant code**
- See the table above; all line references are to the working tree at HEAD `b853411`.

**Additional context**
- Do after issue 16 and alongside issue 17; keep ability names and schemas unchanged so the manifest remains the regression net.
- Repository-facing change: log under `.github/REPOSITORY_CHANGELOG.md`.

- Assessment finding: **B-3** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- GitHub issue: #119 (filed 2026-09-16)
- Related: #118; #127; #113; #122 (`23-wp-slash-applied-inconsistently.md`).
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
