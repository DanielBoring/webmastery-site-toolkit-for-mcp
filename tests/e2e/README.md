# Ability Contract and Full MCP E2E QA

For the full repository QA posture, including static checks, unit tests, release checks, and GitHub Actions trigger policy, see [`docs/qa-strategy.md`](../../docs/qa-strategy.md).

The Docker QA suite has two layers:

1. Ability Contract QA is ability-driven. Every registered `webmastery-site-toolkit-for-mcp/*` ability must be represented in `tests/e2e/abilities-manifest.json`.
2. Full MCP E2E QA uses real MCP Adapter HTTP JSON-RPC requests against `/wp-json/mcp/mcp-adapter-default-server` to prove a remote MCP client can create, read, update, and delete content through the adapter transport.

Baseline coverage before comment authorization hardening was 75 base registered abilities plus 5 generated abilities per eligible custom post type. The E2E runner registers two fixture custom post types, yielding a baseline of 85 abilities across 205 test cases, including custom post type discovery and CRUD permission coverage for two capability maps, object/status-aware post, page, CPT, media, and SEO score list filtering, private-status and bulk-publish denial cases, sensitive identity-field absence checks, taxonomy get/update permission coverage, post and page listing response-shape assertions, expanded Yoast and SEOPress metadata write coverage, controlled Google Site Kit status/module/permission/PageSpeed route fixtures with shared-dashboard, denial, privacy, and same-site URL coverage, the bulk post abilities `webmastery-site-toolkit-for-mcp/bulk-trash-posts` and `webmastery-site-toolkit-for-mcp/bulk-publish-posts`, the revision abilities `webmastery-site-toolkit-for-mcp/list-revisions` and `webmastery-site-toolkit-for-mcp/restore-revision`, the post meta abilities `webmastery-site-toolkit-for-mcp/get-post-meta`, `webmastery-site-toolkit-for-mcp/update-post-meta`, and `webmastery-site-toolkit-for-mcp/delete-post-meta`, the block editing abilities `webmastery-site-toolkit-for-mcp/list-content-blocks`, `webmastery-site-toolkit-for-mcp/patch-content-block`, and `webmastery-site-toolkit-for-mcp/patch-post-content` with targeted post, page, CPT, permission-denial, and unsupported-type coverage, the comment interaction abilities `webmastery-site-toolkit-for-mcp/reply-comment` and `webmastery-site-toolkit-for-mcp/update-comment`, the media sideload ability `webmastery-site-toolkit-for-mcp/upload-image`, the content hygiene abilities `webmastery-site-toolkit-for-mcp/list-orphaned-media`, `webmastery-site-toolkit-for-mcp/list-posts-no-featured-image`, and `webmastery-site-toolkit-for-mcp/list-stuck-scheduled`, the site introspection abilities `webmastery-site-toolkit-for-mcp/get-site-info`, `webmastery-site-toolkit-for-mcp/get-user-info`, and `webmastery-site-toolkit-for-mcp/get-environment-info`, the user access audit ability `webmastery-site-toolkit-for-mcp/user-access-audit`, the Yoast score and metadata abilities `webmastery-site-toolkit-for-mcp/get-seo-scores`, `webmastery-site-toolkit-for-mcp/get-readability-scores`, and `webmastery-site-toolkit-for-mcp/get-yoast-metadata`, the SEOPress metadata ability `webmastery-site-toolkit-for-mcp/get-seopress-metadata`, the webmaster verification ability `webmastery-site-toolkit-for-mcp/webmaster-verification-status`, the database health ability `webmastery-site-toolkit-for-mcp/database-health`, the performance status ability `webmastery-site-toolkit-for-mcp/performance-status`, the backup status ability `webmastery-site-toolkit-for-mcp/backup-status`, plus the plugin abilities `webmastery-site-toolkit-for-mcp/list-plugins`, `webmastery-site-toolkit-for-mcp/plugin-audit`, `webmastery-site-toolkit-for-mcp/activate-plugin`, and `webmastery-site-toolkit-for-mcp/deactivate-plugin`.

## Comment moderation regression coverage

`comments-fixture.php` adds `wstm105_*` fixtures and comment-specific checks. The manifest includes 31 additional cases for comment updates, approval, trash, and spam: moderator-only denials, all four optional update statuses, author-role moderation-floor denials, CPT edit-capability denials, allowed own-draft moderation, Administrator access, orphan comments, and missing comments. Existing Editor cases remain in place. These cases supplement the baseline coverage above; runtime registrations remain the coverage authority.

`assert_comment_state` requires `comment_id`, `content`, and `status`. It reloads the comment after execution and checks both persisted fields, even when the expected result is failure or the response assertion already failed. Security QA requires this evidence for the four moderator-only denials.

The contract runner also invokes both registered callbacks directly to verify the `forbidden`/`not_found` errors, execution-time authorization, malformed input, custom capability filters, core orphan behavior, and unchanged success/error response shapes. Manifest calls use `WP_Ability::execute()`, whose permission failure is `ability_invalid_permissions`, not the callback's original error code. This includes missing comments rejected at the new permission boundary. Expected core permission notices are not suppressed; only notification emails for the deliberately orphaned comment fixture are disabled.

## Rule for new abilities

When a PR adds a new ability, it must also add at least one manifest test case for that ability. If the ability is registered but missing from the manifest, E2E QA fails.

For abilities with role or capability restrictions, include both:

1. A positive case for a role that should be allowed.
2. A negative case for a role that should be denied.

For security-sensitive abilities, also include any assertions needed to prove the response does not leak data. Examples include `assert_missing_paths` for login names, emails, backend versions, protected metadata, private/trash totals, or other fields that lower-privilege users must not see. `composer validate:security-qa` enforces the current high-risk permission-hardening cases that protect against regressions like the WordPress.org review findings fixed in PR #90.

## What CI checks

`scripts/e2e-test.sh contract` runs `tests/e2e/ability-runner.php`, which:

1. Creates deterministic WordPress fixtures.
2. Installs and activates the MCP Adapter, Yoast SEO, and SEOPress plugin dependencies.
3. Reads `tests/e2e/abilities-manifest.json`.
4. Gets the currently registered abilities from `wp_get_abilities()`.
5. Fails if any registered `webmastery-site-toolkit-for-mcp/*` ability is missing from the manifest.
6. Fails if the manifest references an ability that is not registered.
7. Executes every manifest case through `wp_get_ability()->execute()`.
8. Supports positive assertions, negative assertions, missing-path assertions for sensitive fields, post meta assertions, and post thumbnail assertions.
9. Writes `e2e-artifacts/e2e-summary.json` with coverage and result counts.

`scripts/e2e-test.sh e2e` runs `tests/e2e/mcp-crud-runner.php`, which:

1. Creates temporary Application Password credentials for the existing E2E editor and subscriber users.
2. Initializes real MCP HTTP sessions against the MCP Adapter default server.
3. Verifies `tools/list` exposes the `mcp-adapter-execute-ability` gateway.
4. Discovers this plugin's post CRUD abilities through `mcp-adapter-discover-abilities`.
5. Calls `mcp-adapter-execute-ability` over HTTP JSON-RPC to create, read, update, and trash a real draft post.
6. Confirms a subscriber write attempt is denied through the MCP transport.
7. Writes `e2e-artifacts/mcp-crud-summary.json` with protocol-level CRUD result counts.

## Local QA entry points

For quick local checks that do not require WordPress, run:

```bash
composer validate:e2e-manifest
composer validate:security-qa
```

These commands validate manifest JSON structure, required fields, allowed roles, expected result values, labels, assertion shapes, required security-sensitive coverage, and risky static permission callback patterns. They cannot replace full E2E coverage because they do not bootstrap WordPress or compare the manifest to `wp_get_abilities()`.

For both Docker layers, use a disposable Compose project with automatically assigned host ports. In Bash:

```bash
export COMPOSE_PROJECT_NAME="webmastery-qa-$(date +%s)-$RANDOM"
export MYSQL_PORT=0 WORDPRESS_PORT=0
E2E_MANAGE_COMPOSE=1 bash scripts/e2e-test.sh all
```

Use `contract` or `e2e` instead of `all` for a single layer. For an already-running disposable stack, keep its `COMPOSE_PROJECT_NAME` and omit `E2E_MANAGE_COMPOSE=1`.

Set `E2E_KEEP_COMPOSE=1` when you want to leave the containers running for debugging.

Use a unique `COMPOSE_PROJECT_NAME` for disposable validation, especially on a shared machine. The runner manages only that project; do not point automatic cleanup at an existing development stack.

The E2E bootstrap installs Yoast SEO from WordPress.org with the `wordpress-seo` slug and SEOPress from WordPress.org with the `wp-seopress` slug because SEO abilities and assertions cover both providers. Set `YOAST_PLUGIN_SLUG` or `SEOPRESS_PLUGIN_SLUG` only when testing against a specific compatible package. It also installs `tests/e2e/site-kit-fixture.php` as a controlled must-use plugin so Site Kit route and response changes fail deterministically without requiring Google account credentials.

The runner fails early if either dependency is not active. Coexistence assertions seed SEOPress meta on a post that receives Yoast-backed updates and verify those Yoast paths do not mutate SEOPress meta; separate SEOPress assertions verify SEOPress-specific write and read paths.

Routine QA uses reviewed WP-CLI, MCP Adapter, SEO-plugin, and Plugin Check pins from `.github/compatibility-versions.json`. WP-CLI and adapter downloads must pass digest verification before execution/installation. Candidate overrides must supply the matching digest; do not reuse the baseline digest for a different release. The scheduled compatibility workflow explicitly opts into floating dependencies and records the versions it actually tests.

Set `MCP_CRUD_ENDPOINT` only when you need the secondary CRUD runner to target a non-default MCP Adapter endpoint. By default it uses `http://localhost/wp-json/mcp/mcp-adapter-default-server` from inside the WordPress container.

PowerShell users can run:

```powershell
$env:COMPOSE_PROJECT_NAME = "webmastery-qa-$([guid]::NewGuid().ToString('N').Substring(0, 12))"
$env:MYSQL_PORT = '0'
$env:WORDPRESS_PORT = '0'
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
```

Unix, macOS, and Git Bash users can run:

```bash
scripts/qa-local.sh --contract
scripts/qa-local.sh --e2e
```

## PR comment coverage summary

The E2E PR comment includes:

- registered `webmastery-site-toolkit-for-mcp/*` ability count
- manifest-covered ability count
- manifest test case count
- negative permission case count

These counts make it visible when an enhancement adds new ability coverage.

Full E2E artifacts also include `mcp-crud-summary.json` for the secondary protocol-level CRUD QA phase.

GitHub Actions retains available JSON summaries on successful runs as well as failures, with bounded retention. Fork/read-only bot PRs use job summaries without requiring a write token for comments. `Docker QA gate` accepts skipped Docker jobs only when successful change detection explicitly identifies a non-runtime change.
