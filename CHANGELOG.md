# Changelog

All notable changes to Unlock MCP Potential are tracked here.

## Unreleased

## 1.6.0

### Added

- Added WordPress Coding Standards tooling via Composer and `phpcs.xml.dist`.
- Added contribution and PR checklist guidance for WordPress.org Detailed Plugin Guidelines review.
- Added a hardened GitHub Actions E2E QA workflow with merge-base-safe changed-file detection and manual `workflow_dispatch` support.
- Added manifest-driven E2E ability coverage via `tests/e2e/abilities-manifest.json` and `tests/e2e/ability-runner.php`.
- Added an E2E coverage gate that fails when any registered `wp-mcp/*` ability is missing from the manifest.
- Added E2E execution coverage for the current 30 registered abilities with 37 manifest test cases, including positive and negative permission cases.
- Added E2E PR comments that report ability coverage counts, tested dependency versions, commit SHA, and workflow run URL.
- Added failure artifact collection for E2E failures, including Docker Compose logs, WordPress debug logs, and E2E summary JSON.
- Added E2E documentation in `tests/e2e/README.md` describing the rule that new or changed abilities must include manifest test coverage.
- Added a pull request checklist reminder to update `tests/e2e/abilities-manifest.json` when abilities are added or changed.
- Added plugin management abilities: `list-plugins`, `activate-plugin`, and `deactivate-plugin`.
- Added guarded plugin state controls with canonical `plugin_basename` identifiers, protected-plugin deactivation safeguards (`force` override), multisite-aware `network_wide` handling, and structured `WP_Error` responses for capability/context/identifier failures.

### Changed

- Renamed the plugin to "Unlock MCP Potential" and updated release/test tooling for the `unlock-mcp-potential` plugin slug.
- Updated repository/documentation references to align with the Unlock MCP Potential package rebrand.
- Replaced generic `WP_MCP_` PHP class prefixes with `Unlock_MCP_`.
- Hardened permission callbacks for object-specific post/media operations and sensitive site-audit abilities.
- Consolidated release automation to the tag-based `release.yml` workflow.
- Hardened release validation to check tag, plugin header, `readme.txt` stable tag, changelog notes, artifact contents, and duplicate release state before publishing.
- Updated E2E Docker testing to run against WordPress 7.0 with PHP 8.2 and MySQL 8.0.36.
- Pinned GitHub Actions to immutable commit SHAs and updated `actions/checkout` to a Node.js 24-compatible release.
- Split E2E PR commenting into a separate no-checkout job with write permissions isolated from PR-controlled code execution.

### Removed

- Removed redundant `auto-close-issue.yml` automation in favor of GitHub's native `Closes #N` / `Fixes #N` / `Resolves #N` behavior.
- Removed `auto-pr-from-issue.yml` placeholder PR automation.
- Removed merge-based `auto-release.yml` automation to avoid overlapping release paths.
- Removed `.github/README.md` so GitHub shows the project root `README.md` on the repository homepage.

## 1.4.0

- Added media management abilities: `list-media`, `get-media`, `update-media`, and `delete-media`.
- Expanded to 28 abilities: posts (5), pages (5), taxonomy (6), comments (4), media (4), site health (1), security audit (1), SEO analysis (2).

## 1.3.4

- Renamed plugin to "Unlock MCP Potential" to comply with WordPress.org naming guidelines.

## 1.3.3

- Updated "Tested up to" to WordPress 7.0.
- Suppressed false positive PHPCS warning on the core `xmlrpc_enabled` filter check.
- Suppressed false positive slow query warnings on Yoast `meta_query` checks.

## 1.3.2

- Bumped minimum PHP requirement to 8.0 because `str_starts_with` is unavailable on PHP 7.4.
- Added parent field support to `create-page` and `update-page` abilities.
- Replaced PHP `date()` with `wp_date()` per WordPress coding standards.

## 1.3.1

- Added `yoast_meta_description` and `yoast_focus_keyword` fields to `update-post` and `update-page`.

## 1.3.0

- Initial public release.
- Added 24 abilities: posts (5), pages (5), taxonomy (6), comments (4), site health (1), security audit (1), SEO analysis (2).
- Added scheduled (future) post status support.
- Added Yoast SEO integration for SEO analysis abilities.
- Added security audit with fail/warn/pass buckets and remediation guidance.
