<p align="center">
  <img src=".wordpress-org/icon-256x256.png" width="128" height="128" alt="Webmastery Site Toolkit for MCP logo">
</p>

<h1 align="center">Webmastery Site Toolkit for MCP</h1>

<p align="center"><strong>A WordPress MCP plugin that gives AI agents safe, permission-aware control of your site.</strong></p>

<p align="center">
  <a href="https://wordpress.org/plugins/webmastery-site-toolkit-for-mcp/"><img src="https://img.shields.io/wordpress/plugin/v/webmastery-site-toolkit-for-mcp?label=WordPress.org&logo=wordpress" alt="WordPress.org plugin version"></a>
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-6.9%2B-21759b?logo=wordpress&logoColor=white" alt="Requires WordPress 6.9+"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php&logoColor=white" alt="Requires PHP 8.0+"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/license-GPL--2.0%2B-blue" alt="License GPL-2.0+"></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/webmastery-site-toolkit-for-mcp/">WordPress.org</a> ·
  <a href="https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/">Plugin guide</a> ·
  <a href="docs/reference/ability-contracts.md">Ability contracts</a> ·
  <a href="docs/reference/3.0-migration.md">3.0 migration guide</a> ·
  <a href="https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/releases/latest">Latest release</a>
</p>

**Webmastery Site Toolkit for MCP** is a WordPress plugin that adds **70+
permission-aware abilities** for AI agents that speak the
[Model Context Protocol (MCP)](https://modelcontextprotocol.io), including
Claude, ChatGPT, GitHub Copilot, Gemini, Windsurf, and Codex. Agents can write
and edit posts and pages, manage media, comments, categories and tags, review
Yoast SEO and SEOPress metadata, check Google Site Kit, and audit plugins,
users, security, and site health, all without logging in to wp-admin.

It builds on the official [MCP Adapter](https://github.com/WordPress/mcp-adapter)
and the WordPress Abilities API: the adapter runs the MCP server, and this
plugin registers the abilities an agent can call. Every ability runs as a real
WordPress user and enforces that user's roles and capabilities.

> **Upgrading from 2.x?** 3.0 is a breaking release for MCP clients. Read the
> [upgrade checklist](docs/reference/3.0-migration.md#upgrade-checklist) before updating.

## What AI agents can do

| Area | What the agent can do | Typical role |
| --- | --- | --- |
| Posts and pages | Create, list, read, update, schedule, publish, restore, and trash; bulk publish and bulk trash up to 100 posts | Author or Editor |
| Blocks and revisions | Inspect Gutenberg blocks, patch one block or section, list and restore revisions | Author or Editor |
| Post meta and SEO | Read and write individual meta keys, including Yoast SEO and SEOPress fields; analyze SEO and read Yoast scores | Author to Administrator |
| Custom post types | Discover public custom post types and use generated list/get/create/update/delete abilities | The type's capabilities |
| Taxonomy | List, create, update, and delete categories and tags | Subscriber to read; Editor to write |
| Comments | List, reply, edit, approve, hold, trash, and mark spam | Editor (replies: Contributor or Author) |
| Media | List, inspect, update, upload from a public image URL, set featured images, delete | Author or Editor |
| Content hygiene | Find orphaned media, posts missing featured images, and stuck scheduled posts | Author or Editor |
| Site info and webmaster checks | Safe site and user context; public Google and Bing verification signals | Subscriber |
| Google Site Kit | Setup status, modules, permissions, and PageSpeed summaries | Site Kit dashboard users |
| Site administration | Plugins, users, environment, site health, security, performance, backups, database health | Administrator |

The [plugin guide](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#available-abilities)
lists every ability by name. The [ability contracts](docs/reference/ability-contracts.md)
cover exact inputs, permissions, paging, errors, and result fields.

## Quickstart

1. Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.
2. Install **Webmastery Site Toolkit for MCP** from **WP Admin → Plugins → Add New** (search for the plugin name), then activate it.
3. Create a dedicated WordPress user for the agent. Use **Editor** for normal content work.
4. Create an application password for that user.
5. Configure your MCP client with `@automattic/mcp-wordpress-remote` (see [Connect your MCP client](#connect-your-mcp-client)).
6. Ask the client to call `mcp-adapter-discover-abilities`.

## Requirements

| Requirement | Version |
| --- | --- |
| WordPress | 6.9+; tested through 7.1 |
| PHP | 8.0+ |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Latest; tested with 0.6.1 and 0.7.0 ([assessment](docs/reference/mcp-adapter-0.7-assessment.md)) |
| [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) | Optional; enables Yoast metadata, score, and sitemap diagnostics |
| [SEOPress](https://wordpress.org/plugins/wp-seopress/) | Optional; enables SEOPress metadata and site overview diagnostics |
| [Google Site Kit](https://wordpress.org/plugins/google-site-kit/) | Optional; enables the Site Kit status, module, permission, and PageSpeed abilities |

Self-hosted WordPress is required. This works on WordPress installs where custom plugins can be added, including most managed hosts. It does not work on WordPress.com Free, Personal, or Premium plans.

## Install

Install the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) first, then
install **Webmastery Site Toolkit for MCP** from
[WordPress.org](https://wordpress.org/plugins/webmastery-site-toolkit-for-mcp/)
through **WP Admin → Plugins → Add New**.

To install a release ZIP manually, download the [latest release package](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/releases/latest) and upload it in **WP Admin → Plugins → Add New → Upload Plugin**.

For local development, clone the repository into your WordPress plugins directory, then activate it from the Plugins screen:

```bash
git clone https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp.git webmastery-site-toolkit-for-mcp
```

## Connect your MCP client

Local stdio clients use the same three environment variables. Set `WP_API_URL` to your site's MCP Adapter endpoint, and use the dedicated user's application password, not their login password.

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

Other clients mostly differ by config file location and root key. The [plugin guide](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#connect-your-mcp-client) has notes for Claude Code, Claude Desktop, VS Code Copilot, Copilot CLI, Windsurf, Gemini CLI, and ChatGPT.

## Verify

Ask your MCP client to call `mcp-adapter-discover-abilities`. It should list this plugin's `webmastery-site-toolkit-for-mcp/*` abilities. Then try a few safe checks:

- `webmastery-site-toolkit-for-mcp/list-posts`: "List the 5 most recent published posts."
- `webmastery-site-toolkit-for-mcp/get-site-info`: "Get safe public context for this WordPress site."
- `webmastery-site-toolkit-for-mcp/plugin-audit`: "Audit installed plugins." Requires an Administrator service account.

The number of abilities depends on the plugin version and the site's eligible custom post types. If you see fewer than expected, check the installed version before assuming an update is needed.

## Use it safely

- Use a dedicated service account with an application password, not your personal account.
- Use **Editor** for routine content work. Use a separate **Administrator** account only for audits, environment details, and plugin management.
- Every ability checks WordPress capabilities and per-object access, and lists only return what the account is allowed to see.
- Treat site content as untrusted data, never as instructions or approval. See the [agent threat model](docs/90-security-strategy.md#agent-threat-model).
- Permanent media, category, and tag deletion and bulk publish/trash require `confirm: true`; preview bulk actions with `dry_run: true`. Confirmation sent by a model is not human approval.
- Deleting posts and pages moves them to trash, and is refused when site trash is disabled.

Details: [security and privacy behavior](docs/reference/ability-contracts.md#security-and-privacy-behavior) and the [security strategy](docs/90-security-strategy.md). Report suspected vulnerabilities through [private vulnerability reporting](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/security/advisories/new), not public issues; see [SECURITY.md](SECURITY.md).

## Documentation

| Document | Covers |
| --- | --- |
| [Plugin guide](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/) | Setup, roles, every ability by name, safe workflows |
| [Ability contracts](docs/reference/ability-contracts.md) | Exact inputs, permissions, paging, errors, and result fields for MCP client developers |
| [Connection troubleshooting](docs/reference/troubleshooting.md) | Host CAPTCHA blocks and expired Adapter sessions |
| [3.0 migration guide](docs/reference/3.0-migration.md) | Breaking changes from 2.x and how to update clients |
| [Security strategy](docs/90-security-strategy.md) | Threat model, permissions, privacy, vulnerability response |
| [Changelog](CHANGELOG.md) | Plugin release notes |
| [Documentation index](docs/00-sdlc-overview.md#document-map) | Every document in the repository, including QA, release, and contributor guides |

## How this plugin is tested and released

- **Three checks gate every merge:** `01 - Static QA` (syntax, coding
  standards, PHPStan, dependency audit, security policy, workflow lint),
  `02 - Unit Tests` (PHP 8.0 and 8.4) and `03-04 - Docker QA` (every ability
  through WordPress, then through the real MCP transport, with allowed and
  denied roles).
- **Checks are scoped to what changed.** Docs-only PRs finish in minutes;
  plugin changes run the full Docker suite in about 16 minutes.
- **Nightly on `main`:** `05 - Release Package QA` installs and tests the
  built ZIP, and `06 - Minimum Runtime QA` tests WordPress 6.9 on PHP 8.0.
  **Weekly:** `07 - Compatibility QA` tests new WordPress and MCP Adapter
  releases and proposes reviewed baseline updates.
- **A release promotes proven evidence.** Tag `vX.Y.Z` requires 01–06 to have
  passed on that exact `main` commit, builds the ZIP once, runs Plugin Check,
  and publishes the same ZIP to WordPress.org and GitHub only after the
  maintainer approves.
- The development baseline is **WordPress 7.1.3, PHP 8.2 and MCP Adapter
  0.7.0**; the advertised minimums stay WordPress 6.9 and PHP 8.0.
  WordPress.org approval is not a QA result.

![QA flow: pull request checks, re-runs on main, nightly and weekly checks, and the release path](docs/images/qa-flow.png)

Full details: [SDLC overview](docs/00-sdlc-overview.md), with one document per
check (`docs/01-static-qa.md` to `docs/11-ruleset-audit.md`).

## Contributing

New abilities and feature requests are tracked in [GitHub Issues](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/issues). The project follows [Semantic Versioning](https://semver.org/); see [CONTRIBUTING.md](CONTRIBUTING.md) for the development workflow, versioning policy, and QA expectations.

## License

GPL-2.0+ (GNU General Public License v2 or later). See [LICENSE](LICENSE).
