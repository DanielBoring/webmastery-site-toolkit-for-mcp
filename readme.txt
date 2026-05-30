=== WP MCP Abilities ===
Contributors: deboring
Tags: mcp, ai, automation, content-management, artificial-intelligence
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.3.2
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://paypal.me/VirtuallyBoring

Adds content management abilities to the WordPress MCP Adapter, giving AI agents full editorial access via the Model Context Protocol.

== Description ==

WP MCP Abilities is a companion plugin for the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin. The MCP Adapter is a transport framework — it handles the MCP session, REST endpoint, and protocol routing — but ships with no content management abilities out of the box. Any tools an AI agent can actually call must come from plugins that register them. This plugin fills that gap, giving your AI agent the tools to take action: publish a draft, run a security audit, check site health, or analyze a post's SEO.

WP MCP Abilities registers abilities across seven groups, giving AI agents (such as Claude) a full working vocabulary for your WordPress site:

**Posts**
Create, read, update, and delete posts. Supports all statuses including scheduled (future) posts, category and tag assignment, and pagination.

**Pages**
Create, read, update, and delete pages. Supports parent hierarchy and all standard page fields.

**Taxonomy**
List, create, and delete categories and tags. Category creation supports parent hierarchy.

**Comments**
List comments with filters, approve, trash, or mark as spam — all through the standard WordPress comment moderation flow.

**Site Health**
Run WordPress's built-in health tests and get results grouped by severity: critical, recommended, and good.

**Security Audit**
Check for common misconfigurations: debug mode, file editor exposure, SSL, admin username presence, core and plugin version currency, XML-RPC status, and auth key strength. Returns findings in fail/warn/pass buckets with actionable remediation steps.

**SEO Analysis**
Analyze individual posts for title length, word count, meta description, focus keyword placement, image alt text, internal links, and slug length. Get a site-wide overview including sitemap and robots.txt accessibility and counts of published posts missing optimization.

When Yoast SEO is installed, all checks run fully including the Yoast sitemap verification. Without Yoast, meta description and focus keyword checks will warn on every post (since those fields are never populated) and the sitemap check will fail — the structural checks still work correctly.

All abilities enforce WordPress capability checks — an editor cannot call abilities requiring admin caps. Content is sanitized on write using WordPress core functions.

== Installation ==

1. Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin first — WP MCP Abilities depends on it.
2. Upload the `wp-mcp-abilities` folder to `/wp-content/plugins/`, or install via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Create a dedicated WordPress user for your AI agent: go to **Users > Add New User**, set the Role to **Editor**, and save. Using a dedicated account limits access and makes it easy to revoke later.
5. Generate an application password for that user: open the user profile, scroll to **Application Passwords**, enter a name (e.g. `Claude Code`), and click **Add New Application Password**. Copy it immediately — it is only shown once.
6. Configure your MCP client with the site URL, the dedicated username, and the application password. All abilities are then automatically available.

== Frequently Asked Questions ==

= Does this work on WordPress.com? =

This plugin requires a WordPress installation where custom plugins can be installed — self-hosted WordPress or a managed host (WP Engine, Kinsta, Flywheel, etc.). It is not compatible with WordPress.com Free, Personal, or Premium plans, which do not allow custom plugin installation. WordPress.com Business and Commerce plans do allow plugins and should work.

= Does this work without the MCP Adapter plugin? =

No. WP MCP Abilities extends the official WordPress MCP Adapter plugin. Install and activate it first from the plugin directory.

= What MCP clients are supported? =

Any client that speaks the Model Context Protocol. Claude Code, Claude Desktop, and any client using `@automattic/mcp-wordpress-remote` are tested and known to work.

= Do I need Yoast SEO installed? =

No, but behavior differs depending on whether it is active:

**With Yoast SEO:** All SEO checks run fully — structural analysis (title length, word count, image alt text, internal links, slug) plus meta description and focus keyword checks per post, site-wide counts of unoptimized posts, and Yoast sitemap verification.

**Without Yoast SEO:** Structural checks work correctly. However, `seo-analyze-post` will always flag "No Yoast meta description set" and "No Yoast focus keyword set" on every post, and `seo-site-overview` will report every published post as missing those fields. The sitemap check will also fail since it checks the Yoast-specific `/sitemap_index.xml` URL. If you are not using Yoast, treat those specific warnings as not applicable.

= What WordPress user role should I use? =

For the current ability set, use the **Editor** role. It covers all capabilities the plugin currently uses: `edit_posts`, `edit_pages`, `delete_posts`, `delete_pages`, `manage_categories`, `moderate_comments`, and `read`. Administrator is not needed for any of these and gives the AI agent unnecessary access to site settings, user management, and plugin installation.

Note on role scope: the `edit_posts` capability is available to Authors as well, but WordPress scopes query results to the authenticated user's own content unless `edit_others_posts` is also present (which Editors have). Use an Author-role account only if you intentionally want the agent limited to content it created. For full site-wide editorial control, use Editor.

Planned audit abilities — database health, performance status, backup detection, plugin audit, and user access audit — will require an **Administrator** account because they need `manage_options`, `activate_plugins`, or `edit_users`. For those workflows, create a second dedicated Administrator service account and keep the Editor account for content. Using two accounts limits blast radius: the Editor account cannot touch site configuration, and the Administrator account is used only for auditing.

Always create a dedicated user for each role rather than using your personal account — this makes access easy to revoke independently.

= How do I verify the plugin is working? =

After activation, call `mcp-adapter-discover-abilities` from your MCP client. You should see the MCP Adapter's built-in meta/discovery abilities plus all abilities registered by this plugin.

= Are write operations safe? =

Delete operations for posts and pages move content to trash, not permanent deletion. All inputs are sanitized using WordPress core functions. All operations go through the WordPress API — no direct database queries.

== Changelog ==

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

= 1.3.2 =
Requires PHP 8.0+. Adds parent page hierarchy support to create-page and update-page.

= 1.3.1 =
Adds Yoast meta description and focus keyword fields to update-post and update-page.

= 1.3.0 =
Initial release.
