# Plugin Changelog

Plugin-facing changes to Unlock MCP Potential are tracked here. Release notes and version sections should include only changes that affect plugin users, MCP tool compatibility, WordPress behavior, or packaged plugin functionality.

Repository, CI, contributor, and GitHub platform changes are tracked separately in `.github/REPOSITORY_CHANGELOG.md`.

## Unreleased

### Fixed

- Fixed v1.6.1 release-readiness items from Plugin Check by keeping package validation aligned to the canonical `unlock-mcp-potential` slug, shortening the WordPress.org short description, and documenting accepted read-only update-status and bounded Yoast meta-query warnings.

## 1.6.0

### Added

- Added featured image abilities for setting and removing featured images on posts and pages.
- Added restore abilities for restoring trashed posts and pages, with object-specific `delete_post` permission checks.
- Added plugin management abilities: `list-plugins`, `activate-plugin`, and `deactivate-plugin`.
- Added guarded plugin state controls with canonical `plugin_basename` identifiers, protected-plugin deactivation safeguards (`force` override), multisite-aware `network_wide` handling, and structured `WP_Error` responses for capability/context/identifier failures.

### Changed

- Renamed registered MCP ability names and categories to the `unlock-mcp-potential` plugin slug namespace.
- Renamed the plugin to "Unlock MCP Potential" and updated plugin references for the `unlock-mcp-potential` slug.
- Hardened permission callbacks for object-specific post/media operations and sensitive site-audit abilities.

### Fixed

- Preserved backslashes in `create-post` and `update-post` content by slashing post data before WordPress insert/update calls.

## 1.5.0

### Added

- Added user lookup abilities: `list-users` and `get-user`.
- Expanded to 30 abilities: posts (5), pages (5), taxonomy (6), comments (4), media (4), users (2), site health (1), security audit (1), SEO analysis (2).
- Added GitHub Actions workflow automation for E2E QA, issue closure, PR creation from issues, and release publishing.
- Added Docker-based E2E test support via `docker-compose.yml` and `scripts/e2e-test.sh`.
- Added automation setup and operation documentation under `.github/`.

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
