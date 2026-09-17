<h2 align="center">
  <img  alt="Webmastery Site Toolkit for MCP" src=".wordpress-org/icon-256x256.png"><br/>
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

[Quickstart](#quickstart) | [Abilities](#abilities) | [Requirements](#requirements) | [Connect](#connect-your-mcp-client) | [Verify](#verify) | [Security](#security-best-practices) | [Full docs](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/)

</div>

**Webmastery Site Toolkit for MCP** is a WordPress plugin that adds **70+ permission-aware abilities across 16 areas** for AI agents and MCP clients. It works with the official [MCP Adapter](https://github.com/WordPress/mcp-adapter): the adapter provides the transport layer, and this plugin registers the WordPress abilities an agent can call. It works with popular MCP clients including Claude, ChatGPT, GitHub Copilot, and Gemini.

Use it to let an agent draft or update content, manage media and comments, inspect site health, review SEO metadata, audit plugins and users, and gather safe site context without having to login to wp-admin.

For release history, see [CHANGELOG.md](CHANGELOG.md).

## Who This Is For

- WordPress site owners who want safe AI-assisted content workflows.
- Developers building MCP workflows around WordPress.
- Technical editors and administrators managing content, SEO, and site operations.

## Quickstart

1. Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.
2. Install **Webmastery Site Toolkit for MCP** from **WP Admin → Plugins → Add New** (search for the plugin name), then activate it.
3. Create a dedicated WordPress user for the agent. Use **Editor** permissions for normal content work.
4. Create an application password for that user.
5. Configure your MCP client with `@automattic/mcp-wordpress-remote`.
6. Ask the client to call `mcp-adapter-discover-abilities`.

The complete setup guide, client-specific examples, and full ability tables live on the [project documentation page](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/).

## Abilities

Every ability uses WordPress capability checks. An Editor account can handle day-to-day editorial workflows; Administrator-only abilities are intentionally separate because they expose site configuration, installed plugin metadata, or account audit details.

| Area | What the agent can do | Typical role |
| --- | --- | --- |
| Posts and pages | Create, list, read, update, restore, trash, bulk publish, bulk trash, and patch targeted content with object/status-aware filtering for private, trash, draft, pending, and scheduled content | Author or Editor |
| Blocks and revisions | Inspect Gutenberg block paths/hashes, replace one block, list revisions, restore a revision | Author or Editor |
| Post meta | Read, update, and delete safe custom fields; write supported Yoast SEO and SEOPress metadata | Author or Editor |
| Custom post types | Discover eligible public CPTs, generate list/get/create/update/delete abilities, and patch targeted content for editor-enabled types with CPT capability-map and object/status-aware filtering | CPT capability map |
| Taxonomy | List, get, create, update, and delete categories and tags | Subscriber to Editor |
| Comments | List, reply, update, approve, hold, trash, or mark spam | Editor |
| Media | List, inspect, update, upload public image URLs, set featured images, and delete media | Author or Editor |
| Content hygiene | Find orphaned media, posts/pages missing featured images, and stuck scheduled posts | Author or Editor |
| Site info | Return safe site basics and current-user context; runtime, WordPress version, database, and theme-version details require Administrator access | Subscriber to Administrator |
| SEO and webmaster signals | Analyze content, inspect and write supported Yoast/SEOPress metadata, read Yoast scores, inspect generated Yoast head data, and check sitemap/webmaster signals | Author to Administrator |
| Google Site Kit | Inspect setup/authentication status, modules, effective permissions, and same-site PageSpeed summaries through Site Kit's permission-aware REST routes | Shared dashboard user to Administrator |
| Plugins, users, health, security, performance, backups, database | Audit or manage sensitive site areas with explicit admin capabilities | Administrator |

For the exact ability names, input behavior, and required capabilities, use the [full ability reference](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#available-abilities).

### Google Site Kit compatibility abilities

These optional read-only abilities use Site Kit's registered internal REST routes in the current WordPress user context. They do not read Site Kit options, instantiate Site Kit internals, expose OAuth credentials, or change Site Kit settings.

| Ability | Result | Required access |
| --- | --- | --- |
| `webmastery-site-toolkit-for-mcp/get-site-kit-status` | Plugin/version, site connection/setup, and current-user authentication/reauthentication state | `manage_options` and Site Kit setup access |
| `webmastery-site-toolkit-for-mcp/list-site-kit-modules` | Safe module activation, connection, sharing, and dependency state | Site Kit dashboard access, including allowed shared-dashboard users |
| `webmastery-site-toolkit-for-mcp/get-site-kit-permissions` | Current user's normalized Site Kit capability and per-module sharing matrix | Site Kit dashboard access; Site Kit 1.82.0+ |
| `webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed` | Curated field data, category scores, and selected audit metrics for a same-site URL | Site Kit PageSpeed datapoint access |

Site Kit does not publish these routes as a supported third-party API. The adapter checks route availability at runtime and returns `site_kit_unavailable` or `site_kit_unsupported` instead of assuming a specific Site Kit implementation.

## Requirements

| Requirement | Version |
| --- | --- |
| WordPress | 6.9+; tested through 7.1 |
| PHP | 8.0+ |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Latest |
| [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) | Optional; enables Yoast metadata, score, generated head, and sitemap diagnostics |
| [SEOPress](https://wordpress.org/plugins/wp-seopress/) | Optional; enables SEOPress metadata inspection/writes and site overview diagnostics |
| [Google Site Kit](https://wordpress.org/plugins/google-site-kit/) | Optional; enables Site Kit status, module, permission, and PageSpeed compatibility abilities |

Self-hosted WordPress is required. This works on WordPress installs where custom plugins can be added, including most managed hosts. It does not work on WordPress.com Free, Personal, or Premium plans.

## Install

Install the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) first. Then install **Webmastery Site Toolkit for MCP** from **WP Admin → Plugins → Add New**: search for the plugin name, click **Install Now**, and **Activate**.

### Manual or development install

To install a release ZIP manually, download the [latest release package](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/releases/latest) and upload it in **WP Admin → Plugins → Add New → Upload Plugin**.

For local development, clone the repository into your WordPress plugins directory, then activate it from the Plugins screen:

```bash
git clone https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp.git webmastery-site-toolkit-for-mcp
```

## Connect Your MCP Client

All local stdio clients use the same three environment variables. Set `WP_API_URL` to the full MCP Adapter endpoint for your site.

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

Codex uses TOML instead of JSON:

```toml
[mcp_servers.wordpress]
command = "npx"
args = ["-y", "@automattic/mcp-wordpress-remote@latest"]

[mcp_servers.wordpress.env]
WP_API_URL = "https://your-site.com/wp-json/mcp/mcp-adapter-default-server"
WP_API_USERNAME = "ai-editor"
WP_API_PASSWORD = "xxxx xxxx xxxx xxxx xxxx xxxx"
```

Other clients mostly differ by config file location and root key. See the [full setup guide](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#connect-your-mcp-client) for Claude Code, Claude Desktop, VS Code Copilot, Copilot CLI, Codex, Windsurf, Gemini CLI, and ChatGPT notes.

## Verify

Ask your MCP client to call `mcp-adapter-discover-abilities`. It should show the MCP Adapter discovery tools plus this plugin's `webmastery-site-toolkit-for-mcp/*` abilities.

Try a few safe checks:

- `webmastery-site-toolkit-for-mcp/list-posts` - "List the 5 most recent published posts."
- `webmastery-site-toolkit-for-mcp/get-site-info` - "Get safe public context for this WordPress site."
- `webmastery-site-toolkit-for-mcp/webmaster-verification-status` - "Check public Google and Bing webmaster verification signals."
- `webmastery-site-toolkit-for-mcp/list-site-kit-modules` - "List the Google services available to this Site Kit user."
- `webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed` - "Get a mobile PageSpeed summary for this site's home page."
- `webmastery-site-toolkit-for-mcp/plugin-audit` - "Audit installed plugins." Requires an Administrator service account.

If discovery shows fewer abilities than this repo documents, the connected WordPress site is running an older deployed copy of the plugin. Update the site plugin, then run discovery again.

## Image URL uploads

`webmastery-site-toolkit-for-mcp/upload-image` requires `upload_files` (normally Author or above), plus `edit_post` for an optional target post or page. For example, on a disposable site, upload an image you control with `{"image_url":"https://your-public-host.example/image.png","post_id":123,"set_featured":true,"title":"Example","alt_text":"Example image","caption":"Example caption"}`. Verify the returned attachment metadata and the target's featured image.

The existing `wp_max_upload_size()` limit is enforced during retrieval: the temporary file is capped at the limit plus one sentinel byte, and oversized responses are cancelled instead of drained. The actual file size and allowed image MIME are still checked before sideloading. Empty files, incomplete PNG/JPEG/GIF headers, HTTP length mismatches, and failed integrity checks are rejected; image headers/dimensions are not proof of complete image integrity. Generic or incorrect HTTP Content-Type alone does not reject valid image bytes. Zero, invalid, or overflowing upload limits return `invalid_upload_limit` rather than disabling the bound.

Downloads retain WordPress's safe HTTP API, redirect limits, TLS verification, 200-only success, and Content-MD5 verification. A/AAAA answers and CNAME targets are checked before the initial request and through the request's redirect hook; private/reserved addresses, local names, failed DNS resolution, cyclic aliases, and alias chains exceeding 16 lookups are rejected. This is defense in depth, **not DNS pinning or complete rebinding protection**: DNS and the connection can still resolve differently. IPv6 literals and AAAA-only hosts remain unsupported by the core safe-URL path. Core's same-site exception does not override the plugin's private-address checks.

The bound applies per response, not to aggregate redirect traffic, headers, or socket buffering. Native cancellation can receive buffered data beyond the limit. cURL bounds decoded file bytes, but a compressed input chunk can expand into multiple decoded callbacks before cancellation; this is not a decoded-work or CPU quota. Fsockopen retains core's streamed encoded-byte behavior and may reject compressed/chunked images that cURL accepts; the plugin does not add an unbounded decompression stage. Formats enabled through WordPress filters retain core MIME handling; the basic PNG/JPEG/GIF header check does not impose a new decoder requirement on SVG, HEIC, AVIF, or other formats.

The download captures its temporary-file identity before ordinary HTTP argument filters and removes its scoped hooks afterward, so nested requests are not limited accidentally. Installed plugins still control WordPress HTTP hooks and can override or preempt requests; this is not an isolation boundary against arbitrary trusted plugin code.

## Security Best Practices

- Use a dedicated service account, not your personal account.
- Use **Editor** for routine content work and a separate **Administrator** account only for sensitive audits or plugin management.
- WordPress capability checks gate every ability.
- List abilities for posts, pages, custom post types, media, and SEO scores filter every returned object before exposing full details; private, trashed, draft, pending, and scheduled content is only returned when WordPress grants the matching object/status capability.
- Creating or updating content as `publish`, `private`, or `future` requires the relevant publish capability, and bulk publishing requires `publish_posts`.
- Author display names remain in content responses, but login names are omitted from post, page, CPT, revision, and content-hygiene responses. User login and email fields are only returned from user lookup abilities when the caller can edit that user.
- Deletes for posts and pages move content to trash; media deletion is permanent.
- Block and partial-content edits can use hash preconditions and fail when a target is missing, ambiguous, or stale.
- Subscriber-safe site info deliberately avoids secrets, filesystem paths, salts, auth keys, raw server internals, WordPress version, and theme version. `get-environment-info` requires `manage_options`.
- Site Kit abilities defer to Site Kit's own REST permission callbacks. They omit OAuth scopes/proxy details, module owner identities, raw settings, screenshots, third-party entities, and full Lighthouse payloads. PageSpeed only accepts URLs on the current site, although Google processes those requests through Site Kit's PageSpeed service.
- `get-environment-info`, `plugin-audit`, `user-access-audit`, `database-health`, `performance-status`, `backup-status`, `security-audit`, and `site-health-check` are Administrator-only.

Read the [full security model](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#security) before giving an agent Administrator credentials.

Report suspected vulnerabilities through [private vulnerability reporting](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/security/advisories/new), not public issues. See [SECURITY.md](SECURITY.md) for supported versions and reporting guidance.

## Contributing

New abilities and feature requests are tracked in [GitHub Issues](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/issues). The project follows [Semantic Versioning](https://semver.org/); see [CONTRIBUTING.md](CONTRIBUTING.md#versioning-policy) for release and QA expectations.
