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

[Quickstart](#quickstart) | [Abilities](#abilities) | [Requirements](#requirements) | [Connect](#connect-your-mcp-client) | [Verify](#verify) | [Security](#security) | [Full docs](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/)

</div>

**Webmastery Site Toolkit for MCP** is a WordPress plugin that adds MCP-powered abilities for AI agents and MCP clients. It works with the official [MCP Adapter](https://github.com/WordPress/mcp-adapter): the adapter provides the transport layer, and this plugin registers the WordPress abilities an agent can call.

Use it to let an agent draft or update content, manage media and comments, inspect site health, review SEO metadata, audit plugins and users, and gather safe site context without handing your personal admin account to the agent.

> This README describes the current GitHub repo. The latest stable plugin header and WordPress.org `readme.txt` stable tag are `2.4.0`; see [CHANGELOG.md](CHANGELOG.md) for release notes.

<table>
<tr><td align="center"><strong>Without this plugin</strong><br/><img src="assets/before.png" alt="Before"></td>
<td align="center"><strong>With this plugin</strong><br/><img src="assets/after.png" alt="After"></td></tr>
</table>

## Quickstart

1. Install and activate the [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin.
2. Install and activate **Webmastery Site Toolkit for MCP** from the [latest GitHub release](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/releases/latest).
3. Create a dedicated WordPress user for the agent. Use **Editor** for normal content work.
4. Create an application password for that user.
5. Configure your MCP client with `@automattic/mcp-wordpress-remote`.
6. Ask the client to call `mcp-adapter-discover-abilities`.

The complete setup guide, client-specific examples, and full ability tables live on the [project documentation page](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/).

## Abilities

Every ability uses WordPress capability checks. An Editor account can handle day-to-day editorial workflows; Administrator-only abilities are intentionally separate because they expose site configuration, installed plugin metadata, or account audit details.

| Area | What the agent can do | Typical role |
| --- | --- | --- |
| Posts and pages | Create, list, read, update, restore, trash, bulk publish, bulk trash, and patch targeted content | Author or Editor |
| Blocks and revisions | Inspect Gutenberg block paths/hashes, replace one block, list revisions, restore a revision | Author or Editor |
| Post meta | Read, update, and delete safe custom fields; write supported Yoast SEO and SEOPress metadata | Author or Editor |
| Custom post types | Discover eligible public CPTs and generate list/get/create/update/delete abilities | CPT capability map |
| Taxonomy | List, get, create, update, and delete categories and tags | Subscriber to Editor |
| Comments | List, reply, update, approve, hold, trash, or mark spam | Editor |
| Media | List, inspect, update, upload public image URLs, set featured images, and delete media | Author or Editor |
| Content hygiene | Find orphaned media, posts/pages missing featured images, and stuck scheduled posts | Author or Editor |
| Site info | Return safe public site, current-user, and environment context | Subscriber |
| SEO and webmaster signals | Analyze content, inspect Yoast/SEOPress metadata, read Yoast scores, and check public Google/Bing proof | Author to Administrator |
| Plugins, users, health, security, performance, backups, database | Audit or manage sensitive site areas with explicit admin capabilities | Administrator |

For the exact ability names, input behavior, and required capabilities, use the [full ability reference](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#available-abilities).

## Requirements

| Requirement | Version |
| --- | --- |
| WordPress | 6.9+ |
| PHP | 8.0+ |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Latest |
| [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) | Optional |
| [SEOPress](https://wordpress.org/plugins/wp-seopress/) | Optional |

Self-hosted WordPress is required. This works on WordPress installs where custom plugins can be added, including most managed hosts. It does not work on WordPress.com Free, Personal, or Premium plans.

## Install

Install the MCP Adapter first, then install this plugin.

```bash
git clone https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp.git webmastery-site-toolkit-for-mcp
zip -r webmastery-site-toolkit-for-mcp.zip webmastery-site-toolkit-for-mcp --exclude='webmastery-site-toolkit-for-mcp/.git/*'
```

Upload `webmastery-site-toolkit-for-mcp.zip` in **WP Admin > Plugins > Add New > Upload Plugin**, then activate it.

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
- `webmastery-site-toolkit-for-mcp/plugin-audit` - "Audit installed plugins." Requires an Administrator service account.

If discovery shows fewer abilities than this repo documents, the connected WordPress site is running an older deployed copy of the plugin. Update the site plugin, then run discovery again.

## Security

- Use a dedicated service account, not your personal account.
- Use **Editor** for routine content work and a separate **Administrator** account only for sensitive audits or plugin management.
- WordPress capability checks gate every ability.
- Deletes for posts and pages move content to trash; media deletion is permanent.
- Block and partial-content edits can use hash preconditions and fail when a target is missing, ambiguous, or stale.
- Site info abilities deliberately avoid secrets, filesystem paths, salts, auth keys, and raw server internals.
- `plugin-audit`, `user-access-audit`, `database-health`, `performance-status`, `backup-status`, `security-audit`, and `site-health-check` are Administrator-only.

Read the [full security model](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#security) before giving an agent Administrator credentials.

## Contributing

New abilities and feature requests are tracked in [GitHub Issues](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/issues). The project follows [Semantic Versioning](https://semver.org/); see [CONTRIBUTING.md](CONTRIBUTING.md#versioning-policy) for release and QA expectations.
