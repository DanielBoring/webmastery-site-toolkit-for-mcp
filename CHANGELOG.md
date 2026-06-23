# Plugin Changelog

Plugin-facing changes to Webmastery Site Toolkit for MCP are tracked here. Release notes and version sections should include only changes that affect plugin users, MCP tool compatibility, WordPress behavior, or packaged plugin functionality.

Repository, CI, contributor, and GitHub platform changes are tracked separately in `.github/REPOSITORY_CHANGELOG.md`.

## Unreleased

### Added

- Added read-only content hygiene abilities to list orphaned media, published posts or pages missing featured images, and stuck scheduled posts with capability checks and empty results when no problems are found.
- Added bulk post trash and bulk draft-publish abilities with per-ID success/failure summaries and `delete_posts` / `edit_posts` capability checks.
- Added Administrator-only database health diagnostics for revision bloat, orphaned post meta, expired transients, autoloaded option size, and per-table sizes.
- Added discoverability and CRUD abilities for eligible public custom post types, with deterministic naming, CPT-specific capability checks, and taxonomy term assignment support.
- Added site introspection abilities for stable, non-sensitive site, current-user, and runtime environment context with `read` capability checks.
- Added post meta read, update, and delete abilities with object-level `edit_post` checks, protected-key safeguards, typed responses, scalar/JSON value support, and key/value limits.
- Added revision abilities to list saved revisions for posts and pages and restore content to a specific revision with `edit_posts` and object-level edit checks.
- Added `author_name` and `author_login` to post and page responses so listings expose human-readable author details alongside the numeric author ID.
- Added category and tag get-by-ID abilities requiring `read`, plus category and tag update abilities requiring `manage_categories`.

## 2.2.0

### Added

- Added Yoast SEO score and readability score abilities with pagination, filters, deterministic newest-modified-first ordering, and explicit empty results when Yoast SEO is not active.

### Fixed

- Persist supported Yoast SEO protected meta keys from post and page create/update abilities, including focus keyphrase, meta description, and SEO title, and return structured `meta_write_failed` details for meta keys that are not writable.

## 2.1.0

### Added

- Added `list-content-blocks` and `patch-content-block` for precise Gutenberg block inspection and single-block replacement in posts and pages.
- Added `patch-post-content` for safer partial post body edits with block-aware heading targeting, exact-match fallback, ambiguous-target failures, and optional content-hash preconditions.

## 2.0.0

### Changed

- Renamed the plugin to "Webmastery Site Toolkit for MCP" for WordPress.org naming guideline compliance.
- Renamed the plugin slug, text domain, package folder, and MCP ability namespace to `webmastery-site-toolkit-for-mcp`.
- Renamed PHP class prefixes to `Webmastery_MCP_` for clearer plugin-specific namespacing.

### Fixed

- Kept package validation aligned to the canonical `webmastery-site-toolkit-for-mcp` slug, shortened the WordPress.org short description, and documented accepted read-only update-status and bounded Yoast meta-query warnings.

## 1.6.0

### Added

- Added featured image abilities for setting and removing featured images on posts and pages.
- Added restore abilities for restoring trashed posts and pages, with object-specific `delete_post` permission checks.
- Added plugin management abilities: `list-plugins`, `activate-plugin`, and `deactivate-plugin`.
- Added guarded plugin state controls with canonical `plugin_basename` identifiers, protected-plugin deactivation safeguards (`force` override), multisite-aware `network_wide` handling, and structured `WP_Error` responses for capability/context/identifier failures.

### Changed

- Renamed registered MCP ability names and categories to the `webmastery-site-toolkit-for-mcp` plugin slug namespace.
- Renamed the plugin to "Webmastery Site Toolkit for MCP" and updated plugin references for the `webmastery-site-toolkit-for-mcp` slug.
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

- Renamed plugin to "Webmastery Site Toolkit for MCP" to comply with WordPress.org naming guidelines.

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
