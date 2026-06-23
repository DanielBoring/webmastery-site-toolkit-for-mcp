<h2 align="center">
  <img width="80%" alt="Webmastery Site Toolkit for MCP" src="assets/gh_banner.png"><br/>
  Webmastery Site Toolkit for MCP<br/>
  <sub>Give your AI agent editorial control over WordPress.</sub>
</h2>

<div align="center">
  <h2>
    <a href="https://wordpress.org">
      <img src="https://img.shields.io/badge/WordPress-6.9%2B-21759b?logo=wordpress&logoColor=white" alt="WordPress 6.9+" />
    </a>
    <a href="https://www.php.net">
      <img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php&logoColor=white" alt="PHP 8.0+" />
    </a>
    <a href="https://www.gnu.org/licenses/gpl-2.0.html">
      <img src="https://img.shields.io/badge/license-GPL--2.0%2B-blue" alt="License GPL-2.0+" />
    </a>
  </h2>

[Quickstart](#quickstart) • [Abilities](#abilities) • [Requirements](#requirements) • [Installation](#installation) • [Verification](#verification) • [Security](#security) • [Full docs ↗](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/)

</div>

**Webmastery Site Toolkit for MCP** is a WordPress plugin that lets an AI agent manage your site over MCP — posts, revisions, post meta, pages, public custom post types, media, content hygiene diagnostics, comments, taxonomy, plugins, SEO checks, site health, database health, security audits, user lookup, and non-sensitive site introspection. It works with the official [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, which provides the transport layer while this plugin registers the abilities an agent can call.

**Use it if you want to:**

- Have an AI agent draft, update, or publish posts and pages
- Ask an AI to audit your site's security or health posture
- Bring SEO analysis into your content workflow

Without this plugin, the MCP Adapter exposes only its 3 meta/discovery abilities — no content tools. With it, your agent gets full editorial access.

<table>
<tr><td align="center"><strong>Without this plugin</strong><br/><img src="assets/before.png" alt="Before"></td>
<td align="center"><strong>With this plugin</strong><br/><img src="assets/after.png" alt="After"></td></tr>
</table>

> 📖 **Full reference** — the complete ability tables, architecture diagram, and extended security notes live on the project page: **[virtuallyboring.com/webmastery-site-toolkit-for-mcp](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/)**.

---

## Quickstart

1. **Install the MCP Adapter** — download the [latest release zip](https://github.com/WordPress/mcp-adapter/releases/latest) → WP Admin → Plugins → Add New → Upload Plugin → Install & Activate.
2. **Install this plugin** — download the [latest release zip](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/releases/latest) → upload and activate the same way.
3. **Create a dedicated Editor user** — WP Admin → Users → Add New → set Role to **Editor**.
4. **Create an application password** — edit that user → Application Passwords → add a name → copy the generated password.
5. **Connect your MCP client** — point `@automattic/mcp-wordpress-remote` at your site. See [Installation → Connect your MCP client](#5-connect-your-mcp-client) for the config for each client.
6. **Verify** — ask your agent to call `mcp-adapter-discover-abilities`; you should see this plugin's abilities appear.

---

## Abilities

Every ability enforces a WordPress capability check, so the tools an agent can call always reflect what its account is actually allowed to do. Pick the service-account role that matches the work.

| Category                  | Abilities | Typical min. role        |
| ------------------------- | --------- | ------------------------ |
| Posts                     | 9         | Author                   |
| Revisions                 | 2         | Editor                   |
| Post meta                 | 3         | Author → Editor          |
| Pages                     | 6         | Editor                   |
| Custom post types         | 1 + 5 per eligible CPT | CPT capability map |
| Content blocks            | 2         | Author → Editor          |
| Featured images           | 2         | Author → Editor          |
| Taxonomy                  | 10        | Subscriber → Editor      |
| Comments                  | 4         | Editor                   |
| Media                     | 4         | Author                   |
| Content hygiene           | 3         | Author → Editor          |
| Users                     | 2         | Administrator            |
| Site introspection        | 3         | Subscriber               |
| Plugins                   | 4         | Administrator            |
| Site health & security    | 3         | Administrator            |
| SEO analysis              | 4         | Author → Administrator   |

**Editor** is the recommended default for content workflows. User lookup, plugin management/auditing, and site-audit abilities need Administrator capabilities — use a separate Administrator service account for those.

Post and page listings include the numeric author ID plus `author_name` and `author_login`, so agents can show human-readable bylines without an extra user lookup. Bulk post operations can move multiple posts to trash with `bulk-trash-posts` (`delete_posts`) or publish multiple draft posts with `bulk-publish-posts` (`edit_posts`), returning per-ID success and failure summaries instead of stopping at the first problem. Revision abilities can list saved revisions for posts and pages and restore a post or page to a specific revision; both require `edit_posts` plus object-level edit access to the target content. Taxonomy abilities can list, get, create, update, and delete categories and tags; reads require `read`, while writes require `manage_categories`. Site introspection abilities require `read` and return stable, non-sensitive schemas: `get-site-info` returns public site metadata, active theme name/version, deterministic timezone fallback (`UTC±HH:MM` when no timezone string is configured), multisite status, and permalink structure; `get-user-info` returns the current user's profile, roles, and a fixed capability summary; `get-environment-info` returns only PHP version, database server version, WordPress environment type, and locale. Post and page body edits can use `list-content-blocks` to inspect block paths and hashes, then `patch-content-block` to replace one exact Gutenberg block by path or unique hash. `patch-post-content` remains available for heading-section edits and strict exact-match replacement. Ambiguous, missing, or stale targets fail instead of guessing.

`create-post`, `create-page`, `update-post`, and `update-page` can write REST-registered post meta plus supported Yoast SEO protected keys such as `_yoast_wpseo_focuskw`, `_yoast_wpseo_metadesc`, and `_yoast_wpseo_title`. Unsupported protected or unregistered meta keys return a `meta_write_failed` response with `data.meta.not_written` instead of being silently ignored. Dedicated post meta abilities can read, update, or delete one post's unprotected meta keys, plus explicitly allowlisted protected keys, after an object-level `edit_post` check.

`list-post-types` discovers eligible custom post types where `public = true`, `_builtin = false`, and `show_ui = true`. Each eligible CPT gets deterministic ability names in the form `list-cpt-{post-type}`, `get-cpt-{post-type}`, `create-cpt-{post-type}`, `update-cpt-{post-type}`, and `delete-cpt-{post-type}`; naming collisions append a stable short hash. CPT CRUD abilities use the post type's own capability map, including object-level `read_post`, `edit_post`, and `delete_post` checks. CPT taxonomy metadata is returned by discovery, and create/update calls can assign terms through `taxonomy_terms` when the account has the taxonomy's `assign_terms` capability.

`get-seo-scores` and `get-readability-scores` return Yoast SEO and readability score meta for posts and pages with stable pagination, optional `post_type`, `status`, and `modified_after` filters, and newest-modified-first ordering. Missing score meta is returned as `null`; when Yoast SEO is not active, the abilities return an empty result with an explanatory note.

Content hygiene diagnostics are read-only audit tools for common editorial cleanup work: `list-orphaned-media` finds unattached media that is not used as a featured image or referenced in post content (`upload_files`), `list-posts-no-featured-image` finds published posts or pages without `_thumbnail_id` (`edit_posts`, plus `edit_pages` for pages), and `list-stuck-scheduled` finds scheduled posts whose publish time is already in the past (`edit_posts`). These abilities return empty `items` arrays when no matching problems are found.

`database-health` requires `manage_options` and returns read-only database bloat indicators for administrators: post revision count and revision-limit status, orphaned post meta count, expired transient count, autoloaded option size with a 900 KB threshold flag, and per-table row/data/index size details from `information_schema`.

`plugin-audit` requires `activate_plugins` and returns a read-only security and maintenance audit of installed plugins using local plugin metadata and WordPress core's cached update transient. It reports inactive plugins, cached updates and new versions, tested-up-to and minimum WordPress version metadata, potential abandonment when tested compatibility is at least two WordPress release lines behind the current site version, file-modification-age proxy days, and critical updates when the cached update response explicitly flags a security update.

👉 **[See the full ability reference](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#available-abilities)** for every ability, its description, required capability, and minimum role.

---

## Requirements

| Requirement                                                    | Version                                                                                                          |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| WordPress                                                      | 6.9+                                                                                                             |
| PHP                                                            | 8.0+                                                                                                             |
| [MCP Adapter plugin](https://github.com/WordPress/mcp-adapter) | Latest                                                                                                           |
| [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/)      | Optional — structural SEO checks work without it; meta description, focus keyword, sitemap, and score checks require it |

> **Self-hosted WordPress only.** Both this plugin and the MCP Adapter require an install where custom plugins can be added — self-hosted WordPress or a managed host (WP Engine, Kinsta, Flywheel, etc.). They are not compatible with WordPress.com Free, Personal, or Premium plans.

---

## Installation

### 1. Install the MCP Adapter plugin

This plugin depends on the MCP Adapter being installed and active. Install it first from the official [WordPress MCP Adapter project](https://github.com/WordPress/mcp-adapter) or its [latest release](https://github.com/WordPress/mcp-adapter/releases/latest), then upload and activate it in **WP Admin → Plugins → Add New → Upload Plugin**.

### 2. Install Webmastery Site Toolkit for MCP

> **Coming soon to the WordPress Plugin Directory** — this plugin has been submitted for review. Once approved you'll be able to install it from **WP Admin → Plugins → Add New** by searching its name. Until then, use one of the options below.

**Option A — Upload zip (recommended)**

```bash
git clone https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp.git webmastery-site-toolkit-for-mcp
zip -r webmastery-site-toolkit-for-mcp.zip webmastery-site-toolkit-for-mcp --exclude='webmastery-site-toolkit-for-mcp/.git/*'
```

Then in WP Admin: **Plugins → Add New → Upload Plugin**, upload the zip, **Install Now**, **Activate**.

**Option B — Direct file copy (server access)**

```bash
cp -r webmastery-site-toolkit-for-mcp /var/www/html/wp-content/plugins/
wp plugin activate webmastery-site-toolkit-for-mcp
```

### 3. Create a dedicated WordPress user

Create a dedicated user for your agent rather than using your personal admin account — it limits what the agent can do and makes access easy to revoke. In **WP Admin → Users → Add New User**, set a username (e.g. `ai-editor`), an email, and the **Role** to **Editor**.

> **Why Editor and not Administrator?** Editor covers posts, pages, taxonomy, comments, and media. User lookup, plugin management, and site-audit abilities require Administrator capabilities (`list_users`, `activate_plugins`, `manage_options`) — keep a *separate* Administrator service account for those and use the Editor account for day-to-day content.

### 4. Create an application password

Application passwords are separate from the login password and can be revoked independently. Edit the dedicated user → **Application Passwords** → enter a name (e.g. `Claude Code`) → **Add New Application Password** → copy it immediately (it's shown once). It looks like `xxxx xxxx xxxx xxxx xxxx xxxx` — keep the spaces.

### 5. Connect your MCP client

Configure `@automattic/mcp-wordpress-remote` to point at the MCP Adapter endpoint on your site. Every client uses the same three environment variables; set `WP_API_URL` to the full `/wp-json/mcp/mcp-adapter-default-server` URL.

Most clients use this JSON block (the root key and file location vary — see the table below):

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "ai-editor",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

| Client                     | Config file                                                                 | Root key      | Notes                                                                 |
| -------------------------- | --------------------------------------------------------------------------- | ------------- | --------------------------------------------------------------------- |
| **Claude Code**            | `.mcp.json` (project) or `~/.claude.json` (global)                          | `mcpServers`  | Or run `claude mcp add-json`. **Not** `~/.claude/settings.json`.      |
| **Claude Desktop**         | `~/Library/Application Support/Claude/claude_desktop_config.json` (Mac) · `%APPDATA%\Claude\claude_desktop_config.json` (Win) | `mcpServers`  | —                                                                     |
| **GitHub Copilot (VS Code)** | `.vscode/mcp.json` (workspace) or VS Code user profile (global)           | `servers`     | Requires VS Code 1.99+. Uses `servers`, **not** `mcpServers`.         |
| **GitHub Copilot CLI**     | `~/.copilot/mcp-config.json`                                                 | `mcpServers`  | —                                                                     |
| **Codex**                  | `~/.codex/config.toml` (global) or `.codex/config.toml` (project)           | TOML tables   | TOML, not JSON — see snippet below.                                   |
| **Windsurf**               | `~/.codeium/windsurf/mcp_config.json`                                        | `mcpServers`  | —                                                                     |
| **Gemini CLI**             | `~/.gemini/settings.json`                                                    | `mcpServers`  | —                                                                     |
| **ChatGPT**                | Settings → Connected Apps (web UI)                                           | n/a           | Remote HTTP endpoints only; needs a publicly hosted server + Business/Enterprise/Edu plan. |

**Codex** (`~/.codex/config.toml`) uses TOML instead of JSON:

```toml
[mcp_servers.wordpress]
command = "npx"
args = ["-y", "@automattic/mcp-wordpress-remote@latest"]

[mcp_servers.wordpress.env]
WP_API_URL = "https://your-site.com/wp-json/mcp/mcp-adapter-default-server"
WP_API_USERNAME = "ai-editor"
WP_API_PASSWORD = "xxxx xxxx xxxx xxxx xxxx xxxx"
```

### 6. Verify

See [Verification](#verification) below.

---

## Verification

`mcp-adapter-discover-abilities` is an MCP tool registered by the MCP Adapter plugin — not a CLI command. Invoke it through your assistant's chat by asking it to call the tool (e.g. *"Call mcp-adapter-discover-abilities"*). You should see the adapter's built-in meta/discovery abilities plus all abilities registered by this plugin.

To confirm everything works, ask your agent to call a few:

- `webmastery-site-toolkit-for-mcp/list-posts` — *"List the 5 most recent published posts"*
- `webmastery-site-toolkit-for-mcp/bulk-publish-posts` — *"Publish these draft post IDs: 42, 43, and 44"*
- `webmastery-site-toolkit-for-mcp/list-revisions` — *"Show saved revisions for post 42"*
- `webmastery-site-toolkit-for-mcp/update-post-meta` — *"Set the campaign_brief custom field on post 42"*
- `webmastery-site-toolkit-for-mcp/list-post-types` — *"Show eligible custom post types and their generated abilities"*
- `webmastery-site-toolkit-for-mcp/list-posts-no-featured-image` — *"Find published posts missing a featured image"*
- `webmastery-site-toolkit-for-mcp/list-stuck-scheduled` — *"Find scheduled posts that missed their publish time"*
- `webmastery-site-toolkit-for-mcp/get-site-info` — *"Get stable public metadata for this WordPress site"*
- `webmastery-site-toolkit-for-mcp/get-category` — *"Get category 12"*
- `webmastery-site-toolkit-for-mcp/plugin-audit` (Administrator account) — *"Audit installed plugins for inactive, outdated, or potentially abandoned plugins"*
- `webmastery-site-toolkit-for-mcp/security-audit` (Administrator account) — *"Run a security audit of my WordPress site"*
- `webmastery-site-toolkit-for-mcp/site-health-check` (Administrator account) — *"Check WordPress site health"*
- `webmastery-site-toolkit-for-mcp/database-health` (Administrator account) — *"Audit database bloat and table sizes"*

---

## Security

- All abilities enforce WordPress capability checks via `permission_callback` — an editor cannot call abilities that require admin caps.
- Custom post type abilities use each CPT's registered capability map instead of generic post or page capabilities.
- Site introspection abilities intentionally exclude filesystem paths, raw server internals, secrets, auth keys, salts, and configuration values beyond the documented fields.
- `plugin-audit` is read-only and does not call WordPress.org directly; it uses the cached update transient already maintained by WordPress core. It requires `activate_plugins` because installed plugin names, basenames, versions, and compatibility metadata expose the site's plugin attack surface.
- `delete-post`, `delete-page`, and `bulk-trash-posts` move content to trash, not permanent deletion; use `restore-post` / `restore-page` to undo individual items.
- Content hygiene abilities are read-only diagnostics and still honor WordPress ownership scoping; Author-role accounts see only content and media they can edit, while Editors can audit site-wide editorial content.
- `restore-revision` uses WordPress core revision restore APIs and requires `edit_posts` plus object-level edit access for the parent post or page.
- `patch-content-block` and `patch-post-content` support optional hash preconditions and fail safely when a target is missing, ambiguous, or stale.
- Post and page create/update meta writes are limited to REST-registered keys and supported Yoast SEO protected keys; unsupported keys fail with a structured `meta_write_failed` response. Dedicated post meta abilities require `edit_post` for the target post, reject unsafe keys and oversized values, support scalar and JSON object/array values, and deny `_`-prefixed protected keys unless the plugin explicitly allowlists them.
- Content is sanitized on write. Reads and writes go through the WordPress API except `database-health`, which uses read-only `$wpdb` queries for administrator-only database diagnostics.

📖 **[Full security model](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#security)**, including plugin-management safeguards and the complete sanitization rules.

---

## Contributing & versioning

New abilities and feature requests are tracked in [GitHub Issues](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/issues). The project follows [Semantic Versioning](https://semver.org/) — see [`CONTRIBUTING.md`](CONTRIBUTING.md#versioning-policy) for the full release policy.
