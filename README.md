# WordPress MCP Abilities

**WordPress MCP Abilities** is a companion plugin for the official [MCP Adapter](https://github.com/WordPress/mcp-adapter) by Automattic. The MCP Adapter is a transport framework — it handles the MCP session, REST endpoint, and protocol routing — but ships with no content management abilities. Any tools an AI agent can actually call must come from plugins that register them. This plugin registers 24 abilities covering what an editor needs day-to-day: posts, pages, taxonomy, comments, site health, security auditing, and SEO analysis.

## Why you'd use it

If you're connecting an AI assistant (like Claude) to a WordPress site via the `@automattic/mcp-wordpress-remote` MCP server, without this plugin the AI can only see the 3 meta/discovery abilities the MCP Adapter ships with — there are no editorial tools to call. This plugin gives the AI a full working vocabulary for your site.

- You want an AI agent to draft, update, or publish posts and pages
- You want to ask an AI to audit your site's security or health posture
- You want SEO analysis integrated into your content workflow
- You're running `@automattic/mcp-wordpress-remote` and `mcp-adapter-discover-abilities` returns almost nothing

---

## Architecture

```
AI Agent (e.g. Claude Code)
        │
        │  MCP Protocol (JSON-RPC over HTTP)
        ▼
@automattic/mcp-wordpress-remote          ← MCP server (npm process, runs locally)
        │
        │  WordPress REST API
        │  POST /wp-json/mcp/mcp-adapter-default-server
        ▼
WordPress Site
  ├── MCP Adapter plugin                  ← framework: registers the REST endpoint,
  │     (WordPress/mcp-adapter)               handles sessions, routes MCP calls
  │
  └── WordPress MCP Abilities plugin             ← this plugin: registers the actual
        (this repo)                           abilities the AI can call
```

The MCP Adapter handles the transport layer. This plugin handles the *content* — it registers abilities using `wp_register_ability()` that the adapter then exposes as MCP tools.

---

## Abilities

### Posts
| Ability | Description | Required Capability |
|---|---|---|
| `wp-mcp/list-posts` | List posts with filters (status, search, author, category, pagination) | `edit_posts` |
| `wp-mcp/get-post` | Get a single post by ID | `edit_posts` |
| `wp-mcp/create-post` | Create a new post with title, content, status, categories, tags | `edit_posts` |
| `wp-mcp/update-post` | Update an existing post | `edit_posts` |
| `wp-mcp/delete-post` | Move a post to trash | `delete_posts` |

### Pages
| Ability | Description | Required Capability |
|---|---|---|
| `wp-mcp/list-pages` | List pages with filters | `edit_pages` |
| `wp-mcp/get-page` | Get a single page by ID | `edit_pages` |
| `wp-mcp/create-page` | Create a new page | `edit_pages` |
| `wp-mcp/update-page` | Update an existing page | `edit_pages` |
| `wp-mcp/delete-page` | Move a page to trash | `delete_pages` |

### Taxonomy
| Ability | Description | Required Capability |
|---|---|---|
| `wp-mcp/list-categories` | List all categories | `read` |
| `wp-mcp/list-tags` | List all tags | `read` |
| `wp-mcp/create-category` | Create a new category | `manage_categories` |
| `wp-mcp/create-tag` | Create a new tag | `manage_categories` |
| `wp-mcp/delete-category` | Permanently delete a category by ID | `manage_categories` |
| `wp-mcp/delete-tag` | Permanently delete a tag by ID | `manage_categories` |

### Comments
| Ability | Description | Required Capability |
|---|---|---|
| `wp-mcp/list-comments` | List comments with filters (post, status, search) | `edit_posts` |
| `wp-mcp/approve-comment` | Approve a comment | `moderate_comments` |
| `wp-mcp/trash-comment` | Move a comment to trash | `moderate_comments` |
| `wp-mcp/spam-comment` | Mark a comment as spam | `moderate_comments` |

### Site Health
| Ability | Description | Required Capability |
|---|---|---|
| `wp-mcp/site-health-check` | Run WordPress's built-in health tests; returns results grouped by severity (critical / recommended / good) | `read` |

### Security Audit
| Ability | Description | Required Capability |
|---|---|---|
| `wp-mcp/security-audit` | Check for common security issues: debug mode, file editor, SSL, admin username, WP/plugin version currency, XMLRPC, and auth key strength | `read` |

Returns findings in `fail` / `warn` / `pass` buckets with actionable descriptions.

### SEO Analysis
| Ability | Description | Required Capability |
|---|---|---|
| `wp-mcp/seo-analyze-post` | Analyze a single post or page: title length, word count, meta description, focus keyword placement, image alt text, internal links, slug length | `edit_posts` |
| `wp-mcp/seo-site-overview` | Site-wide SEO snapshot: sitemap and robots.txt accessibility, count of published posts missing Yoast focus keyword or meta description | `read` |

**With Yoast SEO installed:** all checks run fully, including meta description and focus keyword analysis per post, site-wide counts of unoptimized content, and Yoast sitemap verification.

**Without Yoast SEO:** structural checks work correctly (title length, word count, image alt text, internal links, slug length, robots.txt). However, `seo-analyze-post` will always warn about missing meta description and focus keyword on every post, and `seo-site-overview` will report every published post as unoptimized — because those Yoast meta fields are never populated. The sitemap check will also fail since it targets the Yoast-specific `/sitemap_index.xml` URL. Treat those specific findings as not applicable if Yoast is not installed.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.9+ |
| PHP | 7.4+ |
| [MCP Adapter plugin](https://github.com/WordPress/mcp-adapter) | Latest |
| [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) | Optional — structural SEO checks work without it; meta description, focus keyword, and sitemap checks require it |

---

## Installation

### 1. Install the MCP Adapter plugin

This plugin depends on MCP Adapter being installed and active. Install it first via **WP Admin → Plugins → Add New**, search for "MCP Adapter", and activate it.

### 2. Install WordPress MCP Abilities

**Option A — Upload zip (recommended for most sites)**

1. Download or build the zip:
   ```bash
   git clone https://github.com/DanielBoring/wordpress-mcp-abilities.git
   cd wp-mcp-abilities
   zip -r wp-mcp-abilities.zip . --exclude='.git/*'
   ```
2. In WP Admin, go to **Plugins → Add New → Upload Plugin**
3. Upload `wp-mcp-abilities.zip` and click **Install Now**
4. Click **Activate Plugin**

**Option B — Direct file copy (server access)**

```bash
cp -r wp-mcp-abilities /var/www/html/wp-content/plugins/
wp plugin activate wp-mcp-abilities
```

### 3. Create a dedicated WordPress user

It is recommended to create a dedicated user for your AI agent rather than using your personal admin account. This limits what the agent can do and makes it easy to revoke access later.

1. In WP Admin, go to **Users → Add New User**
2. Fill in a username (e.g. `ai-editor`) and email address
3. Set the **Role** to **Editor**
4. Click **Add New User**

> **Why Editor and not Administrator?** The Editor role has all the capabilities this plugin uses (`edit_posts`, `edit_pages`, `delete_posts`, `delete_pages`, `manage_categories`, `moderate_comments`, `read`). Administrator is not needed and gives the AI agent unnecessary access to site settings, user management, and plugin installation.

### 4. Create an application password

Application passwords are separate from the user's login password and can be revoked independently.

1. In WP Admin, go to **Users → All Users** and click the dedicated user you just created
2. Scroll down to the **Application Passwords** section
3. Enter a name for the password (e.g. `Claude Code`) and click **Add New Application Password**
4. Copy the generated password immediately — it is only shown once

The password will be in the format `xxxx xxxx xxxx xxxx xxxx xxxx`. Keep the spaces when using it in your MCP config.

### 5. Connect your MCP client

Configure `@automattic/mcp-wordpress-remote` to point at your WordPress site. In Claude Code (`~/.claude/settings.json`):

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_SITE_URL": "https://your-site.com",
        "WP_USERNAME": "ai-editor",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

---

## Verification

After activation and MCP client configuration, run the following from your MCP client:

```
mcp-adapter-discover-abilities
```

You should see 27 abilities: 3 from MCP Adapter itself + 24 from this plugin.

Test a few to confirm they're working:

```
# List the 5 most recent published posts
wp-mcp/list-posts  { "status": "publish", "per_page": 5 }

# Run a security audit
wp-mcp/security-audit  {}

# Check site health
wp-mcp/site-health-check  {}
```

---

## Security

- All abilities enforce WordPress capability checks via `permission_callback`. The ability list reflects what the authenticated user is actually allowed to do — an editor cannot call abilities that require admin caps.
- `delete-post` and `delete-page` move content to trash, not permanent deletion.
- Content is sanitized on write: `sanitize_text_field()` for strings, `wp_kses_post()` for HTML content, `absint()` for IDs, and enum validation for status fields.
- No direct database queries — all reads and writes go through the WordPress API.
