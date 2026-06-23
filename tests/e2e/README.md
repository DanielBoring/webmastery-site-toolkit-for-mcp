# E2E Ability Coverage Contract

The E2E suite is manifest-driven. Every registered `webmastery-site-toolkit-for-mcp/*` ability must be represented in `tests/e2e/abilities-manifest.json`.

Current coverage is 58 base registered abilities plus 5 generated abilities per eligible custom post type. The E2E runner registers two fixture custom post types, so the manifest covers 68 abilities across 134 test cases, including custom post type discovery and CRUD permission coverage for two capability maps, taxonomy get/update permission coverage, post and page listing response-shape assertions, the bulk post abilities `webmastery-site-toolkit-for-mcp/bulk-trash-posts` and `webmastery-site-toolkit-for-mcp/bulk-publish-posts`, the revision abilities `webmastery-site-toolkit-for-mcp/list-revisions` and `webmastery-site-toolkit-for-mcp/restore-revision`, the post meta abilities `webmastery-site-toolkit-for-mcp/get-post-meta`, `webmastery-site-toolkit-for-mcp/update-post-meta`, and `webmastery-site-toolkit-for-mcp/delete-post-meta`, the block editing abilities `webmastery-site-toolkit-for-mcp/list-content-blocks`, `webmastery-site-toolkit-for-mcp/patch-content-block`, and `webmastery-site-toolkit-for-mcp/patch-post-content`, the site introspection abilities `webmastery-site-toolkit-for-mcp/get-site-info`, `webmastery-site-toolkit-for-mcp/get-user-info`, and `webmastery-site-toolkit-for-mcp/get-environment-info`, the Yoast score abilities `webmastery-site-toolkit-for-mcp/get-seo-scores` and `webmastery-site-toolkit-for-mcp/get-readability-scores`, the database health ability `webmastery-site-toolkit-for-mcp/database-health`, plus the plugin management abilities `webmastery-site-toolkit-for-mcp/list-plugins`, `webmastery-site-toolkit-for-mcp/activate-plugin`, and `webmastery-site-toolkit-for-mcp/deactivate-plugin`.

## Rule for new abilities

When a PR adds a new ability, it must also add at least one manifest test case for that ability. If the ability is registered but missing from the manifest, E2E QA fails.

For abilities with role or capability restrictions, include both:

1. A positive case for a role that should be allowed.
2. A negative case for a role that should be denied.

## What CI checks

`scripts/e2e-test.sh` runs `tests/e2e/ability-runner.php`, which:

1. Creates deterministic WordPress fixtures.
2. Reads `tests/e2e/abilities-manifest.json`.
3. Gets the currently registered abilities from `wp_get_abilities()`.
4. Fails if any registered `webmastery-site-toolkit-for-mcp/*` ability is missing from the manifest.
5. Fails if the manifest references an ability that is not registered.
6. Executes every manifest case through `wp_get_ability()->execute()`.
7. Writes `e2e-artifacts/e2e-summary.json` with coverage and result counts.

## PR comment coverage summary

The E2E PR comment includes:

- registered `webmastery-site-toolkit-for-mcp/*` ability count
- manifest-covered ability count
- manifest test case count
- negative permission case count

These counts make it visible when an enhancement adds new ability coverage.
