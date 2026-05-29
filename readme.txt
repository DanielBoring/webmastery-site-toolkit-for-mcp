=== WP MCP Abilities ===
Contributors: danielboring
Tags: mcp, ai, automation, content-management, artificial-intelligence
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds 24 content management abilities to the WordPress MCP Adapter, giving AI agents full editorial access via the Model Context Protocol.

== Description ==

The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin exposes WordPress over the Model Context Protocol — but ships with no content management abilities out of the box. This plugin fills that gap.

WP MCP Abilities registers 24 abilities across six groups, giving AI agents (such as Claude) a full working vocabulary for your WordPress site:

**Posts & Pages**
Create, read, update, and delete posts and pages. Supports all statuses including scheduled (future) posts, category and tag assignment, and pagination.

**Taxonomy**
List, create, and delete categories and tags. Category creation supports parent hierarchy.

**Comments**
List comments with filters, approve, trash, or mark as spam — all through the standard WordPress comment moderation flow.

**Site Health**
Run WordPress's built-in health tests and get results grouped by severity: critical, recommended, and good.

**Security Audit**
Check for common misconfigurations: debug mode, file editor exposure, SSL, admin username presence, core and plugin version currency, XML-RPC status, and auth key strength. Returns findings in fail/warn/pass buckets with actionable remediation steps.

**SEO Analysis**
Analyze individual posts for title length, word count, image alt text, internal links, and slug length. These structural checks work on any WordPress site with no additional plugins required.

When Yoast SEO is installed, the abilities also check meta description and focus keyword placement per post, count site-wide posts missing those fields, and verify your Yoast sitemap. Without Yoast, those specific checks produce warnings on every post (since the meta fields are never set) and the sitemap check will fail — the structural checks still work correctly.

All abilities enforce WordPress capability checks — an editor cannot call abilities requiring admin caps. Content is sanitized on write using WordPress core functions.

== Installation ==

1. Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin first — WP MCP Abilities depends on it.
2. Upload the `wp-mcp-abilities` folder to `/wp-content/plugins/`, or install via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Create a dedicated WordPress user for your AI agent: go to **Users > Add New User**, set the Role to **Editor**, and save. Using a dedicated account limits access and makes it easy to revoke later.
5. Generate an application password for that user: open the user profile, scroll to **Application Passwords**, enter a name (e.g. `Claude Code`), and click **Add New Application Password**. Copy it immediately — it is only shown once.
6. Configure your MCP client with the site URL, the dedicated username, and the application password. The 24 abilities are then automatically available.

== Frequently Asked Questions ==

= Does this work without the MCP Adapter plugin? =

No. WP MCP Abilities extends the official WordPress MCP Adapter plugin. Install and activate it first from the plugin directory.

= What MCP clients are supported? =

Any client that speaks the Model Context Protocol. Claude Code, Claude Desktop, and any client using `@automattic/mcp-wordpress-remote` are tested and known to work.

= Do I need Yoast SEO installed? =

No, but behavior differs depending on whether it is active:

**With Yoast SEO:** All SEO checks run fully — structural analysis (title length, word count, image alt text, internal links, slug) plus meta description and focus keyword checks per post, site-wide counts of unoptimized posts, and Yoast sitemap verification.

**Without Yoast SEO:** Structural checks work correctly. However, `seo-analyze-post` will always flag "No Yoast meta description set" and "No Yoast focus keyword set" on every post, and `seo-site-overview` will report every published post as missing those fields. The sitemap check will also fail since it checks the Yoast-specific `/sitemap_index.xml` URL. If you are not using Yoast, treat those specific warnings as not applicable.

= What WordPress user role should I use? =

Use the **Editor** role. It has all the capabilities this plugin requires (`edit_posts`, `edit_pages`, `delete_posts`, `delete_pages`, `manage_categories`, `moderate_comments`, `read`). Administrator is not needed and gives the AI agent unnecessary access to site settings, user management, and plugin installation.

Create a dedicated user for the AI agent rather than using your personal account — this makes it easy to revoke access independently.

= How do I verify the plugin is working? =

After activation, call `mcp-adapter-discover-abilities` from your MCP client. You should see 27 total abilities: 3 from MCP Adapter itself plus 24 from this plugin.

= Are write operations safe? =

Delete operations for posts and pages move content to trash, not permanent deletion. All inputs are sanitized using WordPress core functions. All operations go through the WordPress API — no direct database queries.

== Changelog ==

= 1.3.0 =
* Initial public release
* 24 abilities: posts (5), pages (5), taxonomy (6), comments (4), site health (1), security audit (1), SEO analysis (2)
* Scheduled (future) post status support
* Yoast SEO integration for SEO analysis abilities
* Security audit with fail/warn/pass buckets and remediation guidance

== Upgrade Notice ==

= 1.3.0 =
Initial release.
