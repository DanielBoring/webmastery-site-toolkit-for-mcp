# E2E Ability Coverage Contract

The E2E suite is manifest-driven. Every registered `unlock-mcp-potential/*` ability must be represented in `tests/e2e/abilities-manifest.json`.

Current coverage is 37 registered abilities across 57 manifest test cases, including the plugin management abilities `unlock-mcp-potential/list-plugins`, `unlock-mcp-potential/activate-plugin`, and `unlock-mcp-potential/deactivate-plugin`.

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
4. Fails if any registered `unlock-mcp-potential/*` ability is missing from the manifest.
5. Fails if the manifest references an ability that is not registered.
6. Executes every manifest case through `wp_get_ability()->execute()`.
7. Writes `e2e-artifacts/e2e-summary.json` with coverage and result counts.

## PR comment coverage summary

The E2E PR comment includes:

- registered `unlock-mcp-potential/*` ability count
- manifest-covered ability count
- manifest test case count
- negative permission case count

These counts make it visible when an enhancement adds new ability coverage.
