=== Webmastery Site Toolkit for MCP ===
Contributors: deboring
Tags: mcp, ai, automation, content-management, artificial-intelligence
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.2.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://paypal.me/VirtuallyBoring

Adds MCP-powered WordPress site abilities for posts, revisions, post meta, pages, custom post types, media, content hygiene, comments, plugins, SEO, health, database health, performance status, security, users, and site info.

== Description ==

Webmastery Site Toolkit for MCP is a WordPress plugin that adds MCP-powered site management abilities for posts, revisions, post meta, pages, public custom post types, media, content hygiene diagnostics, comments, plugins, SEO checks, site health, database health, performance status, security audits, user lookup, and non-sensitive site introspection. It works with the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin, which provides the transport layer while this plugin registers the abilities an AI agent can call.

Webmastery Site Toolkit for MCP registers abilities across site management groups, giving AI agents and MCP clients a full working vocabulary for your WordPress site:

**Posts**
Create, read, update, partially patch, bulk publish drafts, bulk trash, and delete posts. Supports all statuses including scheduled (future) posts, category and tag assignment, pagination, human-readable author fields, and safer targeted content updates.

**Revisions**
List saved revisions for posts and pages and restore a post or page to a specific revision. Requires `edit_posts` plus object-level edit access to the parent content.

**Revisions**
List saved revisions for posts and pages and restore a post or page to a specific revision. Requires `edit_posts` plus object-level edit access to the parent content.

**Post Meta**
Read, update, and delete post custom fields after an object-level `edit_post` check. Unprotected keys are allowed when they pass key and value safety limits; `_`-prefixed protected keys are denied unless explicitly allowlisted by the plugin. Updates support scalar values and JSON object/array values.

**Pages**
Create, read, update, and delete pages. Supports parent hierarchy, human-readable author fields, and all standard page fields.

**Custom Post Types**
Discover eligible public custom post types and use generated list, get, create, update, and delete abilities for each one. Eligibility requires `public = true`, `_builtin = false`, and `show_ui = true`. Ability names use `list-cpt-{post-type}`, `get-cpt-{post-type}`, `create-cpt-{post-type}`, `update-cpt-{post-type}`, and `delete-cpt-{post-type}`, with stable hash suffixes for rare naming collisions. Each ability uses the custom post type's registered capability map, and create/update calls can assign registered taxonomy terms when the account has the taxonomy's `assign_terms` capability.

**Content Blocks**
Inspect Gutenberg block paths and hashes, then replace a single block in a post or page by exact path or unique hash.

**Taxonomy**
List, get, create, update, and delete categories and tags. Category creation and updates support parent hierarchy.

**Comments**
List comments with filters, approve, trash, or mark as spam — all through the standard WordPress comment moderation flow.

**Media**
List, inspect, update, and permanently delete media attachments. Supports MIME type and search filters, pagination, alt text updates, title updates, and caption updates.

**Content Hygiene**
Find common cleanup items: orphaned media not attached or referenced, published posts or pages missing featured images, and scheduled posts whose publish time is already in the past. These read-only diagnostics return empty item lists when no problems are found.

**Users**
List users with role/search/pagination filters and fetch a single user by ID. Useful for account audits and resolving numeric user IDs from responses that do not already include display names.

**Site Info**
Inspect stable, non-sensitive context for the site, current authenticated user, and runtime environment. Site details include public URLs, language, WordPress version, active theme name/version, deterministic timezone fallback, multisite status, and permalink structure. User details include profile fields, roles, and a fixed key-capability summary. Environment details include PHP version, database server version, WordPress environment type, and locale only.

**Plugins**
List installed plugins and manage activation state by canonical plugin basename. Includes protected-plugin safeguards, multisite-aware network activation handling, and structured errors for capability, context, and identifier failures.

**Site Health**
Run WordPress's built-in health tests and get results grouped by severity: critical, recommended, and good.

**Database Health**
Audit read-only database bloat indicators including revision count and revision-limit status, orphaned post meta, expired transients, autoloaded option size, and per-table size details. Requires Administrator access through `manage_options`.

**Performance Status**
Inspect caching and performance-related configuration: external object cache status, object-cache and advanced-cache drop-ins, active known page-cache plugins, WordPress and PHP memory limits, post revision limit, autosave interval, and script concatenation. Requires Administrator access through `manage_options`.

**Security Audit**
Check for common misconfigurations: debug mode, file editor exposure, SSL, admin username presence, core and plugin version currency, XML-RPC status, and auth key strength. Returns findings in fail/warn/pass buckets with actionable remediation steps.

**SEO Analysis**
Analyze individual posts for title length, word count, meta description, focus keyword placement, image alt text, internal links, and slug length. Get a site-wide overview including sitemap and robots.txt accessibility and counts of published posts missing optimization. Read Yoast SEO and readability score lists with pagination and optional post type, status, and modified-after filters.

Post and page create/update abilities can write supported Yoast SEO protected meta keys such as `_yoast_wpseo_focuskw`, `_yoast_wpseo_metadesc`, and `_yoast_wpseo_title`. Unsupported protected or unregistered meta keys fail with structured details instead of being silently ignored. Dedicated post meta abilities can read, update, and delete unprotected custom fields, plus explicitly allowlisted protected keys, only after the current user can edit the target post.

When Yoast SEO is installed, all checks run fully including the Yoast sitemap verification and score-list abilities. Without Yoast, meta description and focus keyword checks will warn on every post (since those fields are never populated), the sitemap check will fail, and score-list abilities will return empty results with a note — the structural checks still work correctly.

All abilities enforce WordPress capability checks — an editor cannot call abilities requiring admin caps. Bulk post operations require `delete_posts` for trashing or `edit_posts` for publishing drafts and return per-ID success and failure summaries. Custom post type abilities use each CPT's capability map rather than generic post or page capabilities. Site info abilities require `read` and deliberately exclude filesystem paths, secrets, auth keys, salts, and raw server internals. Content is sanitized on write using WordPress core functions.

== Installation ==

1. Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin first — Webmastery Site Toolkit for MCP depends on it.
2. Upload the `webmastery-site-toolkit-for-mcp` folder to `/wp-content/plugins/`, or install via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Create a dedicated WordPress user for your AI agent: go to **Users > Add New User**, set the Role to **Editor**, and save. Using a dedicated account limits access and makes it easy to revoke later. If you need user lookup, plugin management, or sensitive site-audit abilities, create a separate dedicated **Administrator** service account because those require administrative capabilities such as `list_users`, `activate_plugins`, or `manage_options`.
5. Generate an application password for that user: open the user profile, scroll to **Application Passwords**, enter a name (e.g. `MCP Client`), and click **Add New Application Password**. Copy it immediately — it is only shown once.
6. Configure your MCP client with the site URL, the dedicated username, and the application password. All abilities are then automatically available.

== Frequently Asked Questions ==

= Does this work on WordPress.com? =

This plugin requires a WordPress installation where custom plugins can be installed — self-hosted WordPress or a managed host (WP Engine, Kinsta, Flywheel, etc.). It is not compatible with WordPress.com Free, Personal, or Premium plans, which do not allow custom plugin installation. WordPress.com Business and Commerce plans do allow plugins and should work.

= Does this work without the MCP Adapter plugin? =

No. Webmastery Site Toolkit for MCP extends the MCP Adapter plugin. Install and activate it first from the plugin directory.

= What MCP clients are supported? =

Any client that speaks the Model Context Protocol and can connect to `@automattic/mcp-wordpress-remote` or an equivalent MCP bridge should work.

= Do I need Yoast SEO installed? =

No, but behavior differs depending on whether it is active:

**With Yoast SEO:** All SEO checks run fully — structural analysis (title length, word count, image alt text, internal links, slug), meta description and focus keyword checks per post, site-wide counts of unoptimized posts, Yoast sitemap verification, and Yoast SEO/readability score lists.

**Without Yoast SEO:** Structural checks work correctly. However, `seo-analyze-post` will always flag "No Yoast meta description set" and "No Yoast focus keyword set" on every post, and `seo-site-overview` will report every published post as missing those fields. The sitemap check will also fail since it checks the Yoast-specific `/sitemap_index.xml` URL. `get-seo-scores` and `get-readability-scores` return empty results with a note. If you are not using Yoast, treat those specific warnings as not applicable.

= What WordPress user role should I use? =

For core content workflows, use the **Editor** role. It covers the editorial capabilities used by posts, pages, taxonomy, comments, media, and content hygiene diagnostics: `edit_posts`, `edit_pages`, `delete_posts`, `delete_pages`, `upload_files`, `manage_categories`, and `moderate_comments`.

Site introspection workflows (`get-site-info`, `get-user-info`, and `get-environment-info`) require only `read`, so they work with Subscriber and higher roles.

Custom post type workflows depend on each CPT's registered capability map. Use `list-post-types` to discover the generated ability names and required capabilities before assigning an MCP service account.

For user lookup, plugin management, and sensitive site-audit workflows (`list-users`, `get-user`, `list-plugins`, `activate-plugin`, `deactivate-plugin`, `site-health-check`, `database-health`, `performance-status`, `security-audit`, and `seo-site-overview`), use a separate dedicated **Administrator** account because those abilities require `list_users`, `activate_plugins`, or `manage_options`.

Note on role scope: the `edit_posts` and `upload_files` capabilities are available to Authors as well, but WordPress scopes results and write access to the authenticated user's own content unless `edit_others_posts` / `delete_others_posts` are also present (which Editors have). Use an Author-role account only if you intentionally want the agent limited to content it created. For full site-wide editorial control, use Editor.

For those workflows, create a second dedicated Administrator service account and keep the Editor account for content. Using two accounts limits blast radius: the Editor account cannot touch site configuration, and the Administrator account is used only for auditing.

Always create a dedicated user for each role rather than using your personal account — this makes access easy to revoke independently.

= How do I verify the plugin is working? =

After activation, call the MCP Adapter discovery tool, `mcp-adapter-discover-abilities`, from your MCP client. You should see the MCP Adapter's built-in meta/discovery abilities plus all abilities registered by this plugin.

= Are write operations safe? =

Delete operations for posts and pages, including bulk post trashing, move content to trash. Media delete permanently removes the attachment and its files. Plugin activation and deactivation require Administrator plugin capabilities, and protected-plugin deactivation is blocked unless explicitly forced. All inputs are sanitized using WordPress core functions. Operations go through the WordPress API except `database-health`, which uses read-only `$wpdb` queries for Administrator-only diagnostics.

Post and page create/update meta writes are limited to REST-registered keys and supported Yoast SEO protected keys. Unsupported keys return `meta_write_failed` with the rejected keys listed in `data.meta.not_written`. Dedicated post meta abilities enforce object-level `edit_post` checks, reject unsafe key names and oversized values, support scalar and JSON object/array values, and deny protected `_`-prefixed keys unless they are explicitly allowlisted by the plugin.

For post and page body edits, `list-content-blocks` returns precise block paths and hashes, then `patch-content-block` can replace one exact Gutenberg block by path or unique hash. `patch-post-content` can still update the content under one exact Gutenberg heading or perform a strict exact-match replacement for classic/raw HTML content. These abilities fail when the target is missing, ambiguous, or stale and support optional hash preconditions to avoid overwriting newer edits.

== Changelog ==

= Unreleased =
* Add an Administrator-only performance status ability for object-cache status, page-cache plugin detection, memory limits, revision limits, autosave interval, and script concatenation diagnostics.
* Add read-only content hygiene abilities to list orphaned media, published posts or pages missing featured images, and stuck scheduled posts with capability checks and empty results when no problems are found.
* Add an Administrator-only database health ability for revision bloat, orphaned post meta, expired transients, autoloaded option size, and per-table size diagnostics.
* Add bulk post trash and bulk draft-publish abilities with per-ID success/failure summaries and `delete_posts` / `edit_posts` capability checks.
* Add discoverability and CRUD abilities for eligible public custom post types, with deterministic naming, CPT-specific capability checks, and taxonomy term assignment support.
* Add site introspection abilities for stable, non-sensitive site, current-user, and runtime environment context with `read` capability checks.
* Add post meta read, update, and delete abilities with object-level permissions, protected-key safeguards, typed responses, scalar/JSON value support, and key/value limits.
* Add revision abilities to list saved post/page revisions and restore a post or page to a specific revision with `edit_posts` and object-level edit checks.
* Add category and tag get-by-ID abilities requiring `read`, plus category and tag update abilities requiring `manage_categories`.
* Add `author_name` and `author_login` to post and page responses so listings expose human-readable author details alongside the numeric author ID.

= 2.2.0 =
* Add Yoast SEO score and readability score abilities with pagination, filters, deterministic newest-modified-first ordering, and explicit empty results when Yoast SEO is not active.
* Persist supported Yoast SEO protected meta keys from post and page create/update abilities, including focus keyphrase, meta description, and SEO title, and return structured `meta_write_failed` details for meta keys that are not writable.

= 2.1.0 =
* Add `list-content-blocks` and `patch-content-block` for precise Gutenberg block inspection and single-block replacement in posts and pages.
* Add `patch-post-content` for safer partial post body edits with block-aware heading targeting, exact-match fallback, ambiguous-target failures, and optional content-hash preconditions.

= 2.0.0 =
* Rename the plugin to Webmastery Site Toolkit for MCP for WordPress.org naming guideline compliance
* Rename the plugin slug, text domain, package folder, and MCP ability namespace to `webmastery-site-toolkit-for-mcp`
* Rename PHP class prefixes to `Webmastery_MCP_` for clearer plugin-specific namespacing

= 1.6.1 =
* Fix release packaging guidance so Plugin Check evaluates the canonical `webmastery-site-toolkit-for-mcp` slug
* Shorten the WordPress.org short description to stay within parser limits
* Document accepted Plugin Check warnings for read-only plugin auto-update status reporting and bounded Yoast SEO meta checks

= 1.6.0 =
* Rename registered MCP ability names and categories to the `webmastery-site-toolkit-for-mcp` plugin slug namespace
* Add featured image abilities for setting and removing featured images on posts and pages
* Add restore abilities for restoring trashed posts and pages with object-specific `delete_post` permission checks
* Preserve backslashes in `create-post` and `update-post` content
* Add plugin management abilities: `list-plugins`, `activate-plugin`, and `deactivate-plugin`
* Add guarded plugin activation/deactivation controls with canonical `plugin_basename` identifiers, protected-plugin safeguards, multisite-aware `network_wide` handling, and structured errors
* Rename plugin/package references to align with Webmastery Site Toolkit for MCP and harden object-specific permission callbacks

= 1.5.1 =
* Rename plugin to "Webmastery Site Toolkit for MCP" and update slug references to `webmastery-site-toolkit-for-mcp`
* Replace generic `WP_MCP_` PHP class prefixes with `Webmastery_MCP_`
* Harden ability permissions with object-specific post/media checks and administrator-only sensitive audit abilities
* Add WordPress Coding Standards tooling and update WordPress.org plugin-guideline documentation

= 1.5.0 =
* Add user lookup abilities: `list-users` and `get-user`
* 30 abilities: posts (5), pages (5), taxonomy (6), comments (4), media (4), users (2), site health (1), security audit (1), SEO analysis (2)

= 1.4.0 =
* Add media management abilities: `list-media`, `get-media`, `update-media`, and `delete-media`
* 28 abilities: posts (5), pages (5), taxonomy (6), comments (4), media (4), site health (1), security audit (1), SEO analysis (2)

= 1.3.4 =
* Rename plugin to "Webmastery Site Toolkit for MCP" to comply with WordPress.org naming guidelines

= 1.3.3 =
* Update "Tested up to" to WordPress 7.0
* Suppress false positive PHPCS warning on core xmlrpc_enabled filter check
* Suppress false positive slow query warnings on Yoast meta_query checks

= 1.3.2 =
* Bump minimum PHP requirement to 8.0 (str_starts_with is unavailable on PHP 7.4)
* Add parent field support to create-page and update-page abilities
* Replace PHP date() with wp_date() per WordPress coding standards

= 1.3.1 =
* Add `yoast_meta_description` and `yoast_focus_keyword` fields to `update-post` and `update-page`

= 1.3.0 =
* Initial public release
* 24 abilities: posts (5), pages (5), taxonomy (6), comments (4), site health (1), security audit (1), SEO analysis (2)
* Scheduled (future) post status support
* Yoast SEO integration for SEO analysis abilities
* Security audit with fail/warn/pass buckets and remediation guidance

== Upgrade Notice ==

= Unreleased =
Adds Administrator-only database health diagnostics plus bulk post trash and bulk draft-publish abilities for MCP clients.

= 2.2.0 =
Adds Yoast score list abilities and fixes supported Yoast protected meta writes for post and page create/update workflows.

= 2.1.0 =
Adds safer block-level post and page editing abilities for MCP clients.

= 2.0.0 =
Renames MCP ability IDs to the `webmastery-site-toolkit-for-mcp` namespace as part of the WordPress.org review rename.

= 1.6.1 =
Fixes Plugin Check release-readiness items for the WordPress.org package.

= 1.6.0 =
Renames MCP ability IDs to the `webmastery-site-toolkit-for-mcp` namespace, adds featured image, restore, and plugin management abilities, and fixes post content backslash preservation.

= 1.5.1 =
Renames the plugin, tightens permission checks, and adds WordPress.org review-readiness tooling.

= 1.5.0 =
Adds user lookup abilities for resolving WordPress author IDs.

= 1.4.0 =
Adds media list, get, update, and permanent delete abilities.

= 1.3.4 =
Plugin renamed to "Webmastery Site Toolkit for MCP".

= 1.3.3 =
Updates tested up to WordPress 7.0.

= 1.3.2 =
Requires PHP 8.0+. Adds parent page hierarchy support to create-page and update-page.

= 1.3.1 =
Adds Yoast meta description and focus keyword fields to update-post and update-page.

= 1.3.0 =
Initial release.
