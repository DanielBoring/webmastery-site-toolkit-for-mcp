=== Webmastery Site Toolkit for MCP ===
Contributors: deboring
Tags: mcp, ai, automation, content-management, artificial-intelligence
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.4.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://paypal.me/VirtuallyBoring

Adds MCP-powered WordPress abilities for content, media, SEO, audits, comments, plugins, users, health, and security.

== Description ==

Webmastery Site Toolkit for MCP adds WordPress abilities that AI agents and MCP clients can call through the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.

The MCP Adapter provides the transport layer. This plugin provides the site-management vocabulary: posts, pages, media, comments, taxonomy, custom post types, post meta, content hygiene, SEO checks, public webmaster verification, site info, health, security, users, plugins, database, performance, and backup status.

Core editorial workflows work well with a dedicated Editor service account. Sensitive workflows such as plugin management, user auditing, site health, database health, backup status, performance status, and security audits require a separate Administrator service account.

Highlights:

* Create, update, list, restore, trash, bulk publish, and bulk trash posts and pages.
* Inspect Gutenberg blocks and patch one targeted block or content section instead of rewriting an entire post.
* Manage categories, tags, comments, media metadata, featured images, and public image URL uploads.
* Discover eligible public custom post types and use generated CRUD abilities for each one.
* Read, update, and delete safe post meta, including supported Yoast SEO and SEOPress metadata fields.
* Run content hygiene checks for orphaned media, missing featured images, and stuck scheduled posts.
* Inspect safe site, user, and environment context without exposing secrets or raw server internals.
* Audit plugins, administrator accounts, backups, performance settings, database bloat, site health, and security posture.
* Analyze SEO metadata and public Google/Bing webmaster verification proof.

All abilities enforce WordPress capability checks. If the connected account cannot perform the equivalent WordPress action, the ability fails instead of bypassing WordPress permissions.

For full setup instructions, ability tables, and the deeper security model, visit:
https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/

== Installation ==

1. Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin first.
2. Upload the `webmastery-site-toolkit-for-mcp` folder to `/wp-content/plugins/`, or install the plugin zip from **Plugins > Add New > Upload Plugin**.
3. Activate **Webmastery Site Toolkit for MCP** from the WordPress Plugins screen.
4. Create a dedicated WordPress user for your MCP client. Use Editor for day-to-day content work.
5. Create an application password for that user from the WordPress user profile screen.
6. Configure your MCP client with the site endpoint, username, and application password.
7. Ask your MCP client to call `mcp-adapter-discover-abilities` and confirm the `webmastery-site-toolkit-for-mcp/*` abilities appear.

== Frequently Asked Questions ==

= Does this work without the MCP Adapter plugin? =

No. This plugin extends the MCP Adapter plugin and depends on it for MCP transport and ability registration.

= Does this work on WordPress.com? =

It requires a WordPress site where custom plugins can be installed. Self-hosted WordPress and managed hosts that allow custom plugins should work. WordPress.com Free, Personal, and Premium plans do not allow custom plugin installation.

= Which WordPress role should my agent use? =

Use a dedicated Editor account for normal content workflows: posts, pages, taxonomy, comments, media, revisions, content blocks, and content hygiene.

Use a separate dedicated Administrator account only when you need Administrator-only workflows such as plugin management, user access audits, site health, database health, performance status, backup status, security audits, or site-wide SEO overview.

= Why use a dedicated account? =

A dedicated account limits the agent to the role you choose, makes activity easier to attribute, and lets you revoke access by deleting the application password or user.

= Do I need Yoast SEO or SEOPress? =

No. Structural SEO checks still work without either plugin. Yoast-specific metadata and score abilities require Yoast SEO. SEOPress-specific metadata inspection and writes require SEOPress.

= Are write operations safe? =

Write operations go through WordPress APIs and capability checks. Posts and pages move to trash rather than being permanently deleted. Media deletion is permanent. Block and partial-content patching can use hashes so stale or ambiguous edits fail safely.

= What if discovery shows fewer abilities than the documentation? =

The connected WordPress site may be running an older plugin version. Update the plugin on that site, then call `mcp-adapter-discover-abilities` again.

= Where is the full documentation? =

The complete ability reference and client setup guide are maintained at:
https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/

== Changelog ==

= 2.4.0 =
* Add expanded Yoast SEO free metadata coverage for canonical URLs, breadcrumb titles, Schema.org page/article types, Open Graph and Twitter metadata, primary category, robots directives, inclusive-language score inspection, generated Yoast head inspection, and deeper sitemap index diagnostics.
* Add first-class SEOPress free metadata coverage for titles, descriptions, target keywords, canonical URLs, Open Graph and Twitter/X metadata, primary category, robots directives, breadcrumb titles, read-only metadata inspection, and SEOPress-specific site overview diagnostics.

= 2.3.0 =
* Add public image URL uploads with URL safety checks, image MIME and upload-size enforcement, optional metadata, and optional featured-image assignment.
* Add public Google/Bing webmaster verification checks without Google or Bing API credentials.
* Add Administrator-only user access, plugin, database, performance, and backup audits.
* Add content hygiene diagnostics for orphaned media, posts/pages missing featured images, and stuck scheduled posts.
* Add bulk post trash and bulk draft-publish abilities with per-ID summaries.
* Add eligible custom post type discovery and generated CRUD abilities.
* Add safe site, current-user, and environment introspection abilities.
* Add post meta read, update, and delete abilities with object-level permissions and protected-key safeguards.
* Add revision listing and restore abilities for posts and pages.
* Add category and tag get/update abilities.
* Add comment reply and update abilities.
* Add human-readable author fields to post and page responses.

= 2.2.0 =
* Add Yoast SEO score and readability score abilities.
* Persist supported Yoast SEO protected meta keys from post and page create/update abilities.

= 2.1.0 =
* Add block inspection, single-block replacement, and safer partial post body edits.

== Upgrade Notice ==

= 2.4.0 =
Adds expanded Yoast SEO and SEOPress free metadata coverage, including richer social, robots, canonical, breadcrumb, Schema, read-only inspection, and site overview diagnostics.

= 2.3.0 =
Adds media URL uploads, webmaster verification checks, content hygiene diagnostics, CPT CRUD, post meta, revisions, comment replies/updates, and Administrator-only site audits.

= 2.2.0 =
Adds Yoast score list abilities and supported Yoast protected meta writes.

= 2.1.0 =
Adds safer block-level and partial-content editing abilities for MCP clients.
