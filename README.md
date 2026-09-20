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

**Unreleased 3.0 development:** this branch changes the error contract, not the
2.6.0 stable tag. Clients must follow the [3.0 migration guide](docs/3.0-migration.md)
before deploying it. Ability names, roles, inputs, defaults, and successful
payloads are unchanged by error normalization.

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
| Post meta | Read, update, and delete individual custom fields, including supported SEO keys, with object and key-level checks | Object edit access plus the key's effective capabilities |
| Custom post types | Discover eligible public CPTs, generate list/get/create/update/delete abilities, and patch targeted content for editor-enabled types with CPT capability-map and object/status-aware filtering | CPT capability map |
| Taxonomy | List/get categories and tags; create/update/delete with taxonomy-specific and per-term write checks | Subscriber for reads; Editor by default for writes |
| Comments | List, reply, update, approve, trash, mark spam, or set hold through `update-comment` | Contributor/Author for replies on editable own posts; moderation needs `moderate_comments` plus `edit_comment` (normally Editor) |
| Media | List, inspect, update, upload public image URLs, set featured images, and delete media | Author or Editor |
| Content hygiene | Find orphaned media, posts/pages missing featured images, and stuck scheduled posts | Author or Editor |
| Site info | Return safe site basics and current-user context; runtime, WordPress version, database, and theme-version details require Administrator access | Subscriber to Administrator |
| SEO and webmaster signals | Analyze content, inspect and write supported Yoast/SEOPress metadata, read Yoast scores, inspect generated Yoast head data, and check sitemap/webmaster signals | Author to Administrator |
| Public webmaster verification | Check public Google/Bing meta tags, Bing XML, DNS TXT, robots.txt, and sitemap reachability; WordPress-only Site Kit state requires `activate_plugins` | Subscriber (`read`); privileged plugin-state addition |
| Google Site Kit | Inspect setup/authentication status, modules, effective permissions, and same-site PageSpeed summaries through Site Kit's permission-aware REST routes | Shared dashboard user to Administrator |
| Plugins, users, health, security, performance, backups, database | Audit or manage sensitive site areas with explicit admin capabilities | Administrator |

For the exact ability names, input behavior, and required capabilities, use the [full ability reference](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#available-abilities).

Comment replies require `edit_posts` and `edit_post` on the post containing the parent comment. Listing requires `moderate_comments`; updating and the approve/trash/spam abilities also require `edit_comment` on the resolved comment. There is no separate `hold-comment` ability: use `update-comment` with `status: "hold"` and the required `content`.

### Standalone post metadata authorization

`get-post-meta`, `update-post-meta`, and `delete-post-meta` require `edit_post` for the actual object and preserve the existing protected-key eligibility rules. Ordinary Authors can usually operate on their own posts; pages and other authors' posts generally require an Editor, and individual keys may impose additional requirements.

| Standalone ability | Key-level policy |
| --- | --- |
| `get-post-meta` | Requires `edit_post_meta`. An explicit denied key returns `forbidden`; listings omit denied keys. This conservative read policy is specific to this plugin, not a general WordPress read-meta capability. |
| `update-post-meta` | Requires `edit_post_meta` for existing, absent, and unchanged values. Successful response fields and structured-value support are unchanged. The capability choice matches core REST upserts, but denial of unauthorized no-ops is intentionally stricter than REST's same-value shortcut. |
| `delete-post-meta` | Requires `delete_post_meta`, including when the key is absent. Authorized deletion retains the existing `deleted_count` response. |

Registered global/subtype policies and WordPress's effective `map_meta_cap` / `user_has_cap` filters remain authoritative. Supported, genuinely unregistered SEO keys with no key authorization hooks receive only a temporary protected-key default, not an exception to capability filters. Other plugins may explicitly grant primitive metadata capabilities: Yoast's effective edit policy can permit a key whose registration callback returns false, while its delete policy can differ.

**Breaking 3.0 development contract:** post, page, and dynamic CPT create/update requests reject the presence of `meta`, `meta_input`, every `yoast_`/`seopress_` alias, and raw `_yoast_wpseo_`/`_seopress_` fields before post, metadata, taxonomy, scheduling, or save-hook side effects. Empty/null values and unchanged metadata are also rejected; Administrator access does not bypass this rule. Plain content, status, parent, and taxonomy requests retain their existing behavior.

Create a draft without metadata, call `update-post-meta` separately for each exact key, verify every result, then publish with a plain update. These operations are **not atomic**: an earlier authorized write remains if a later key is denied. Keep the draft unpublished on failure; do not retry a combined request. See the [complete alias migration table](docs/3.0-migration.md#metadata-and-seo-authorization).

Separate SEO inspection, analysis, and score abilities also require effective `edit_post` plus `edit_post_meta` for each real object/key before reading it. Denied fields are omitted from raw and normalized metadata and reported in `unavailable_fields`, not advertised as visible plain text. Analysis skips checks that cannot be evaluated; scores filter permission before totals and pagination. Opaque generated Yoast head output is unavailable because extensible generated output cannot be authorized by a fixed key list. URL-only head requests return `unsupported`.

SEO overview samples at most 100 published post/page IDs in ascending ID order. Each missing-field `count` and maximum-20 `ids` list includes only authorized observations; `observed_count` is the authorized sample denominator. `observation_scope.counts_are_sitewide` is false. Zero observations mean no evidence, not a healthy site. Independent native published post/page totals remain available to Administrators.

For verification on a disposable site, register a nonprotected string key with an authorization callback requiring `manage_options`, seed a draft, and call the standalone abilities as its Author without primitive metadata grants. An explicit read, an upsert (including the same value), and a deletion must return `forbidden`; the listing must omit the key and storage must remain unchanged. An Administrator with effective object/key permission can use it. A combined create/update containing that key must instead return `invalid_input` with reason `metadata_requires_separate_call`, leaving posts, metadata, terms, and save hooks untouched.

### Backslashes in writes

Post/page metadata and media titles, captions, and alt text preserve backslashes through WordPress storage, including repeated or trailing backslashes and escaped quotes. Send decoded values normally; do not add an extra WordPress slashing layer in your MCP client. JSON still requires its usual escaping: `"C:\\path\\"` represents `C:\path\`.

Existing text/HTML sanitization and registered metadata or SEO-provider sanitizers still apply. Responses report sanitized stored values, not necessarily the original input. Use `update-post-meta`: public keys support JSON-compatible arrays and objects; supported protected SEO keys retain their scalar normalization. Allowed standalone metadata keys are unchanged. Uploads require `upload_files` plus access to any parent post.

On a disposable draft, call `update-post-meta` with `{"post_id":123,"meta_key":"_yoast_wpseo_metadesc","meta_value":"C:\\path\\"}`, then read the same explicit key using `get-post-meta`. Compare storage with `data.current_value` from the update response, allowing provider sanitization.

### Category and tag write permissions

| Abilities | Required WordPress access |
| --- | --- |
| `create-category`, `create-tag` | Registered taxonomy's `edit_terms` capability |
| `update-category`, `update-tag` | Taxonomy's `edit_terms` and `edit_term` for the existing term |
| `delete-category`, `delete-tag` | Taxonomy's `delete_terms` and `delete_term` for the existing term |

These names use the `webmastery-site-toolkit-for-mcp/` prefix. WordPress's final `current_user_can()` result applies, including capability mapping and site filters. Default mappings still allow Editors and Administrators to manage terms; custom mappings can grant tag management without `manage_categories`, or deny it despite that global capability. Creation intentionally requires term-editing access, not the more permissive nonhierarchical REST `assign_terms` policy. Granting a core alias such as `edit_post_tags` alone does not override WordPress's mapping of that alias.

Both permission callbacks and direct execution enforce write checks. Category/tag list and get permissions are unchanged. For callers with the taxonomy capability, missing IDs or IDs from the other taxonomy still return the existing `success: false` not-found envelope. Object-policy denials now fail before writes instead of bypassing the site's restrictions.

Term deletion is permanent and uses WordPress's normal relationship handling (including category reassignment). Core denies deleting the default category through `delete_term`; if site filters override that denial, an underlying `0` or `false` deletion result still returns `success: false`, never `deleted: true`. Successful deletion keeps the existing `{ "success": true, "data": { "id": 123, "deleted": true } }` shape. On a disposable site, a `delete-category` request with `{ "category_id": <default-category-ID> }` must fail and a subsequent `get-category` must still find it.

### Targeted content patching

| Ability | Target | Required access |
| --- | --- | --- |
| `webmastery-site-toolkit-for-mcp/patch-content-block` | One Gutenberg block by path or unique hash in a post or page | `edit_post` for the specific object |
| `webmastery-site-toolkit-for-mcp/patch-post-content` | A heading section or unique exact raw-content match in a post, page, or public, UI-visible, editor-enabled CPT | `edit_post` for the specific object, resolved through its capability map |

Both abilities sanitize the **replacement fragment** with `wp_kses_post()`, not the entire rebuilt body. This prevents the plugin from stripping unrelated iframe, script, style, form, SVG, or event-attribute markup already stored elsewhere. Exact-match `old_content` is compared byte-for-byte against raw stored content, without sanitizing the search needle.

WordPress's normal save pipeline still applies. Preserving markup that KSES would strip requires the caller's **effective `unfiltered_html` capability**; a role name alone is not sufficient. Multisite, `DISALLOW_UNFILTERED_HTML`, or capability policies can deny it even to an Administrator. Callers without it remain subject to core's save-time KSES filtering. New replacement markup is filtered regardless of this capability, and full-content update abilities still sanitize all supplied content.

Block-path, block-hash, and heading patches retain the existing WordPress parse/serialize behavior, which can normalize noncanonical block delimiters and omit empty freeform separators. They are not raw byte-splicing operations. Exact patches do not parse or serialize the surrounding content.

### Google Site Kit compatibility abilities

These optional read-only abilities use Site Kit's registered internal REST routes in the current WordPress user context. They do not read Site Kit options, instantiate Site Kit internals, expose OAuth credentials, or change Site Kit settings.

| Ability | Result | Required access |
| --- | --- | --- |
| `webmastery-site-toolkit-for-mcp/get-site-kit-status` | Plugin/version, site connection/setup, and current-user authentication/reauthentication state | `manage_options` and Site Kit setup access |
| `webmastery-site-toolkit-for-mcp/list-site-kit-modules` | Safe module activation, connection, sharing, and dependency state | WordPress `read` plus Site Kit's module-list route permission |
| `webmastery-site-toolkit-for-mcp/get-site-kit-permissions` | Current user's normalized Site Kit capability and per-module sharing matrix | WordPress `read` plus Site Kit's user-permissions route permission; retains the 1.82.0 minimum-version diagnostic when that route is missing |
| `webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed` | Curated field data, category scores, and selected audit metrics for a same-site URL | WordPress `read` plus Site Kit's module-list and PageSpeed datapoint route permissions |

Site Kit does not publish these routes as a supported third-party API. The adapter checks route availability at runtime and returns `site_kit_unavailable` or `site_kit_unsupported` instead of assuming a specific Site Kit implementation.

The three delegated abilities check `read` before plugin discovery, route lookup, or upstream work in both permission and direct execution callbacks. This is a minimum, not an access grant: the exact upstream GET route must expose a callable permission check, and normal WordPress REST dispatch still enforces it. Missing providers, routes, or usable permission callbacks fail closed. Status keeps its separate `manage_options` gate, without an additional `read` requirement.

**Upstream permission inspection:** the official WordPress.org Site Kit **1.187.0** package was checksum-verified and inspected on a disposable WordPress 7.1 / PHP 8.2 installation, without Google credentials or data/API calls. These are version-specific observations, not guarantees about historical or future releases:

| GET route under `/google-site-kit/v1` | Site Kit 1.187.0 permission semantics |
| --- | --- |
| `/core/modules/data/list` | `googlesitekit_view_splash` OR `googlesitekit_view_dashboard` ([source](https://plugins.svn.wordpress.org/google-site-kit/tags/1.187.0/includes/Core/Modules/REST_Modules_Controller.php), lines 209-225) |
| `/core/user/data/permissions` | `googlesitekit_view_splash` OR `googlesitekit_view_dashboard` ([source](https://plugins.svn.wordpress.org/google-site-kit/tags/1.187.0/includes/Core/Permissions/Permissions.php), lines 709-718) |
| `/modules/pagespeed-insights/data/pagespeed` | Registered through the generic module/datapoint route. The resolver honors a permission-aware datapoint's own check; this version's PageSpeed definition uses the default `googlesitekit_setup` OR `googlesitekit_view_posts_insights` ([controller](https://plugins.svn.wordpress.org/google-site-kit/tags/1.187.0/includes/Core/Modules/REST_Modules_Controller.php), lines 613-623, 655-657, 1027-1058; [datapoint](https://plugins.svn.wordpress.org/google-site-kit/tags/1.187.0/includes/Modules/PageSpeed_Insights.php), lines 77-84). |

These effective Site Kit capabilities incorporate setup, authentication, verification, sharing, and network rules; they are not fixed WordPress role checks. In 1.187.0 the default dynamic grants map dashboard/insights capabilities to `edit_posts` and setup/authentication to `manage_options`, with additional restrictions. The unconnected inspection allowed the administrator's route permission checks and denied an ordinary Subscriber's; no PageSpeed data request was executed. This plugin does **not** add an `edit_posts` or `manage_options` floor to the delegated abilities, so a Subscriber whom Site Kit legitimately authorizes is not excluded locally. The controlled shared-Subscriber fixture proves that local behavior, not that an ordinary Subscriber receives all real Site Kit routes. Unknown versions remain route-probed; reverify these semantics when supported upstream versions or the minimum version change. See [inspection and fixture instructions](tests/e2e/README.md#site-kit-permission-regressions).

## Requirements

| Requirement | Version |
| --- | --- |
| WordPress | 6.9+; tested through 7.1 |
| PHP | 8.0+ |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Latest |
| [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) | Optional; enables authorized Yoast metadata, score, and sitemap diagnostics; generated head output is unavailable in 3.0 |
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
- `webmastery-site-toolkit-for-mcp/webmaster-verification-status` - "Check public Google and Bing webmaster verification signals." Requires `read`; Subscribers and Authors receive public checks only. Site Kit installation/activation details require `activate_plugins`.
- `webmastery-site-toolkit-for-mcp/list-site-kit-modules` - "List the Google services available to this Site Kit user."
- `webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed` - "Get a mobile PageSpeed summary for this site's home page."
- `webmastery-site-toolkit-for-mcp/plugin-audit` - "Audit installed plugins." Requires an Administrator service account.

Ability counts depend on the deployed plugin and eligible custom post types. Fewer discovered abilities can reflect a different site's CPTs or an older deployed copy; check the registered names and plugin version before assuming an update is needed.

To verify patch preservation on a disposable site, use an account with effective `unfiltered_html`, save a Custom HTML block beside a paragraph, and call `list-content-blocks`. Patch only the paragraph with `patch-content-block`, supplying its path and the returned content/block hashes as preconditions. List the blocks again: the untouched Custom HTML block's hash should be unchanged. For an exact patch, use the original raw markup as `old_content`, not rendered or sanitized HTML.

## Image URL uploads

`webmastery-site-toolkit-for-mcp/upload-image` requires `upload_files` (normally Author or above), plus `edit_post` for an optional target post or page. For example, on a disposable site, upload an image you control with `{"image_url":"https://your-public-host.example/image.png","post_id":123,"set_featured":true,"title":"Example","alt_text":"Example image","caption":"Example caption"}`. Verify the returned attachment metadata and the target's featured image.

The existing `wp_max_upload_size()` limit is enforced during retrieval: the temporary file is capped at the limit plus one sentinel byte, and oversized responses are cancelled instead of drained. The actual file size and allowed image MIME are still checked before sideloading. Empty files, incomplete PNG/JPEG/GIF headers, HTTP length mismatches, and failed integrity checks are rejected; image headers/dimensions are not proof of complete image integrity. Generic or incorrect HTTP Content-Type alone does not reject valid image bytes. Zero, invalid, or overflowing upload limits return `invalid_upload_limit` rather than disabling the bound.

Downloads retain WordPress's safe HTTP API, redirect limits, TLS verification, 200-only success, and Content-MD5 verification. A/AAAA answers and CNAME targets are checked before the initial request and through the request's redirect hook; private/reserved addresses, local names, failed DNS resolution, cyclic aliases, and alias chains exceeding 16 lookups are rejected. The IPv6 documentation prefix `2001:db8::/32` is rejected explicitly because native PHP reserved-range flags differ across versions. This is defense in depth, **not DNS pinning or complete rebinding protection**: DNS and the connection can still resolve differently. IPv6 literals and AAAA-only hosts remain unsupported by the core safe-URL path. Core's same-site exception does not override the plugin's private-address checks.

The bound applies per response, not to aggregate redirect traffic, headers, or socket buffering. Native cancellation can receive buffered data beyond the limit. cURL bounds decoded file bytes, but a compressed input chunk can expand into multiple decoded callbacks before cancellation; this is not a decoded-work or CPU quota. Fsockopen retains core's streamed encoded-byte behavior and may reject compressed/chunked images that cURL accepts; the plugin does not add an unbounded decompression stage. Formats enabled through WordPress filters retain core MIME handling; the basic PNG/JPEG/GIF header check does not impose a new decoder requirement on SVG, HEIC, AVIF, or other formats.

The download captures its temporary-file identity before ordinary HTTP argument filters and removes its scoped hooks afterward, so nested requests are not limited accidentally. Installed plugins still control WordPress HTTP hooks and can override or preempt requests; this is not an isolation boundary against arbitrary trusted plugin code.

## Scheduling posts, pages, and custom post types

Create/update abilities accept `status: "future"` and `scheduled_date`. Creating a schedule or moving non-scheduled content to `future` requires a nonempty date **at least 60 seconds ahead when validated**, matching WordPress core's scheduling cutoff. Prefer an ISO 8601 value with `Z` or an explicit offset, for example `2030-12-01T09:00:00-05:00`. Use a comfortably future date: even an ordinary clock tick between validation and core's later time check can make a date exactly 60 seconds ahead publish immediately. This is preflight validation, not an atomic status guarantee against elapsed time or third-party hooks.

An ordinary edit to an already-scheduled item may omit `scheduled_date`, with `status` omitted or still `future`. Both stored date strings are retained, and the stored GMT date must still meet the cutoff. Near-now or overdue schedules must be given a new safe date, or an explicit nonfuture status. Changing the site timezone does not invalidate an otherwise valid stored schedule or silently rewrite its dates. WordPress remains responsible for cron timing; timezone changes can affect the cron event's local-date conversion.

Malformed dates, invalid calendar/time values (such as February 30), and missing or unsafe future dates fail before the ability writes content, metadata, or terms. Scheduling errors use `success:false`, `error.code:"invalid_input"` and the specific `error.reason`: `invalid_scheduled_date`, `missing_scheduled_date`, or `scheduled_date_too_soon`. An explicitly blank date is not a request to reuse an existing future schedule.

For 2.x compatibility, valid PHP `strtotime()` date families remain supported, including relative dates. Offset-less input keeps its previous PHP-default timezone interpretation, normally **UTC**, not the site's local timezone. Named-zone DST folds/gaps retain PHP's resolution, including gap normalization; explicit offsets preserve the specified instant even during a repeated local hour. Local and GMT dates are formatted from the same instant without a lossy local-to-GMT round trip.

With a nonfuture or omitted status on non-scheduled content, a valid supplied date still sets the date arguments rather than being ignored. Normal WordPress rules apply: draft/pending updates with a previously zero GMT date can reset that date to now, and `publish` with a sufficiently future date can become `future`. The scheduling fix does not change those nonfuture semantics or publish capabilities.

On a disposable test site, verify a future create with an explicit offset, an update with no new date, and a malformed-date attempt. Check the actual status and stored local/GMT dates, and confirm the rejected attempt left the item unchanged. The automated regression matrix is described in [the E2E guide](tests/e2e/README.md).

### Parent assignments

Page and hierarchical custom-post-type create/update abilities accept `parent`.
A positive parent ID must identify an editable item of the same hierarchical
type and must not create a cycle or lead into an existing cyclic hierarchy.
Invalid or unauthorized requests fail before the ability saves any content,
status, metadata, or taxonomy changes. Existing permission and other input
errors retain their precedence.

Use `parent: 0` to detach an item, or omit `parent` to leave it unchanged on
update. Assigning the same parent still checks that immediate parent's edit
permission; ancestors do not require edit permission. Pages use `edit_post`
and CPTs use their registered edit capability, including WordPress capability
filters. An ordinary Author cannot edit pages by default.

Positive `parent` values on nonhierarchical CPTs are rejected, even though
older versions persisted this unsupported extra field. Zero and omitted
parents remain accepted. Built-in post abilities continue to ignore extra
`parent` fields; their schemas are not newly closed.

On a test site, verify an allowed page-parent update, a denied update under
another user's inaccessible parent, and a detach with `parent: 0`. Include a
title change with the denied request and confirm that the title is unchanged.

## Response format

Registered Webmastery ability failures use `{"success":false,"error":{"code":"not_found","reason":"not_found","message":"Comment not found.","details":{}}}`. The fixed categories are `forbidden`, `not_found`, `invalid_input`, `precondition_failed`, `conflict`, `unsupported`, and `upstream_failed`. Parse `code` and the specific `reason`, never message substrings. Empty `details` is an object. Existing successful `success:true,data` payloads are unchanged.

The owned ability subclass retains core input/output validation, permission redaction, and execution hooks. Native permission checks still return `true` or `WP_Error`, never error arrays. Unknown provider diagnostics, including familiar-code collisions, are redacted; core schema errors cannot echo secret input values. This does not change WordPress's own logging.

For MCP Adapter **0.6.1**, owned gateway and individual-tool failures set `isError:true` with the canonical JSON envelope in one text block. Native code/data and structured error content are not retained by the Adapter, so clients must decode that text; `structuredContent` is absent/null. Success through the gateway retains its extra `success/data` wrapper. Foreign plugin results are not intercepted. Check HTTP/JSON-RPC failures separately.

Bulk operations remain non-atomic: top-level `success:true` means the batch was processed, including an all-failed batch. Inspect counts and every `{id,code,reason,message,details}` failure. See the [migration matrix, mappings, examples, and Adapter limitation](docs/3.0-migration.md).

For comment permission checks, use a disposable site: a custom account with only `read` and `moderate_comments` must be denied when updating, approving, trashing, or marking a comment as spam on a post it cannot edit. Confirm both comment content and status remain unchanged, including when an update supplies a status. An Editor with access to that post should succeed.

Missing-comment failures now uniformly use code/reason `not_found`; callers without `moderate_comments` still fail at the permission boundary. Invalid/nonpositive IDs are rejected without falling back to a global comment or coercing a negative ID into another target. On a disposable site, also try an authorized future create with `scheduled_date:"bad date"`: the wire error must be `invalid_input/invalid_scheduled_date`, with no created post.

## Security Best Practices

SEO Analyze Post uses static focus-keyword diagnostics: `Focus keyword found in title.` or `Focus keyword not found in title.` Stored values remain unchanged in `data.metrics.yoast_focus_keyword` and `data.metrics.seopress_focus_keywords`; `seo_provider_focus_source` retains Yoast-first selection with SEOPress fallback when the Yoast value is empty. Object `edit_post` access, response fields, checks, severity, scores, and missing-keyword behavior are unchanged. These metrics and the title remain untrusted data, not instructions. This partial #108 change adds no field markers, does not verify all annotations or resolve the broader issue, and is not prompt-injection prevention.

- Use a dedicated service account, not your personal account.
- Use **Editor** for routine content work and a separate **Administrator** account only for sensitive audits or plugin management.
- WordPress capability checks gate every ability.
- Updating or moderating comments requires both `moderate_comments` and WordPress's object-specific `edit_comment` capability. Custom roles cannot moderate another author's post or a custom post type without its mapped edit capabilities. This does not change comment listing or reply permissions.
- Treat retrieved site content as untrusted data, never as instructions or approval. Use the least-privileged account that fits the task, bounded selections, independent previews/diffs, and explicit client-side approval for dangerous changes or transmission to a specific destination. See the [Agent threat model](docs/security-strategy.md#agent-threat-model) for control limits and recovery guidance.
- List abilities for posts, pages, custom post types, media, and SEO scores filter every returned object before exposing full details; private, trashed, draft, pending, and scheduled content is only returned when WordPress grants the matching object/status capability.
- Creating or updating content as `publish`, `private`, or `future` requires the relevant publish capability, and bulk publishing requires `publish_posts`.
- Author display names remain in content responses, but login names are omitted from post, page, CPT, revision, and content-hygiene responses. User login and email fields are only returned from user lookup abilities when the caller can edit that user.
- Deletes for posts, pages, and custom post type items move content to trash. If `EMPTY_TRASH_DAYS` is `0` or another falsy value, these abilities refuse with `trash_disabled` before mutation instead of allowing WordPress to permanently delete the item. Bulk post trash reports this per authorized ID in `data.failures`, with no false success entries; its existing top-level summary remains successful even when every ID fails. Missing/type and permission errors take precedence. No permanent-delete override is offered.
- Comment trash and comment updates with `status: "trash"` set the comment status through `wp_set_comment_status()`, retaining the row even when site trash is disabled. Media deletion remains permanent.
- Block and partial-content edits can use hash preconditions and fail when a target is missing, ambiguous, or stale.
- Targeted patches sanitize replacements only and retain WordPress's capability-dependent save filters; they do not grant unfiltered HTML write access.
- Subscriber-safe site info deliberately avoids secrets, filesystem paths, salts, auth keys, raw server internals, WordPress version, and theme version. `get-environment-info` requires `manage_options`.
- Site Kit module, permission, and PageSpeed abilities require WordPress `read` **and** Site Kit's own REST permission callbacks, failing closed when a required route or callable permission check is absent. Status separately requires `manage_options`. Responses omit OAuth scopes/proxy details, module owner identities, raw settings, screenshots, third-party entities, and full Lighthouse payloads. PageSpeed only accepts URLs on the current site, although Google processes those requests through Site Kit's PageSpeed service.
- Webmaster verification checks require `read`, including direct execution. Callers without `activate_plugins` receive neither `data.google.site_kit` nor `data.checks.google_site_kit`; plugin inspection is skipped and the summary counts only authorized checks. Public results, including failures and unknowns, share a 60-second cache scoped to the site, home URL, and result schema. Warm calls do not repeat HTTP/DNS work; private plugin state is inspected separately on each authorized call and is never cached with public results. Concurrent cold misses or early transient eviction can repeat work, so this is not a strict rate limit.
- `get-environment-info`, `plugin-audit`, `user-access-audit`, `database-health`, `performance-status`, `backup-status`, `security-audit`, and `site-health-check` are Administrator-only.
- `security-audit`'s `ssl` finding reports only the configured public `home` option (including normal option filters and `WP_HOME`): recognized HTTPS passes and HTTP fails. Missing/unsupported schemes or hosts, whitespace/control characters, malformed percent escapes, and invalid authority syntax warn as unknown. The local syntax guard accepts local names, Unicode/IDN forms, properly escaped components, and bracketed IPv6 (including escaped zone IDs) or IPvFuture literals; it is not a complete URL, DNS-name, or internationalized-name validator and imposes no address-routability policy. It is independent of the MCP request scheme and admin-only TLS policy. It does not test certificates, reachability, redirects, or the final filtered front-end URL.
- Debug-log findings omit filesystem paths. Enabled logging warns that access is unverified: a neighboring `.htaccess` file or a location outside `wp-content` does not prove protection from web access. Disabled logging still passes; enabled logging alone is not proof of exposure or a reason to disable necessary logging.
- `database-health` query failures retain a contextual `database_health_query_failed` error without raw SQL/server error text. WordPress's own database logging behavior is unchanged. Successful `table_sizes[].table` values still include the site's prefix and matching plugin-table names for `manage_options` callers; this diagnostic output is **not fully redacted**.

Read the [full security model](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/#security) before giving an agent Administrator credentials.

Report suspected vulnerabilities through [private vulnerability reporting](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/security/advisories/new), not public issues. See [SECURITY.md](SECURITY.md) for supported versions and reporting guidance.

## Contributing

New abilities and feature requests are tracked in [GitHub Issues](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/issues). The project follows [Semantic Versioning](https://semver.org/); see [CONTRIBUTING.md](CONTRIBUTING.md#versioning-policy) for release and QA expectations.

Maintainers and contributors can start with the [Software Development Lifecycle](docs/sdlc-overview.md) for a map of how issues, implementation, QA, releases, and maintenance fit together.
