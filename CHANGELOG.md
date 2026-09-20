# Plugin Changelog

Plugin-facing changes to Webmastery Site Toolkit for MCP are tracked here. Release notes and version sections should include only changes that affect plugin users, MCP tool compatibility, WordPress behavior, or packaged plugin functionality.

Repository, CI, contributor, and GitHub platform changes are tracked separately in `.github/REPOSITORY_CHANGELOG.md`.

## Unreleased

### Added

- Added private vulnerability reporting guidance and the supported-release security policy to the plugin FAQ.
- Added read-only Google Site Kit compatibility abilities for setup and authentication status, module state, current-user permissions, and same-site PageSpeed summaries, with Site Kit-native permission enforcement and sensitive upstream fields removed.

### Changed

- Clarified agent handling of untrusted site content, comment reply/moderation access, dynamic ability counts, and the distinction between plugin results and MCP gateway responses.
- Expanded the plugin header description to include custom post types, blocks/revisions, webmaster verification, and optional Google Site Kit diagnostics.
- Shortened the Unreleased upgrade notice to fit WordPress.org's 300-character limit while retaining upload, taxonomy, and compatibility guidance.
- Updated WordPress tested compatibility to 7.1 after passing ability and MCP transport checks on PHP 8.2 and 8.4.
- Required object-specific comment edit permission as well as moderation permission for comment updates, approval, trash, and spam actions, preventing custom moderator roles from changing comments on posts they cannot edit. Existing missing-comment responses and the moderation capability floor remain unchanged; invalid/nonpositive IDs cannot target a global comment or another ID through coercion.

### Fixed

- SEO Analyze Post no longer quotes stored focus keywords in diagnostic messages. Exact stored keywords remain in the existing metric fields; provider selection, checks, severity, scores, and permissions are unchanged. This limited data/message separation is not prompt-injection prevention.

- Enforce effective WordPress key-level capabilities in the standalone post-meta read, upsert, and delete tools, including absent and unchanged values. Metadata listings omit denied keys; successful response shapes and protected-key eligibility remain unchanged. Metadata inside post/page create/update requests and separate SEO read paths are not covered by this partial fix and retain unresolved authorization risks.

- Security audit HTTPS findings now describe the configured public home URL rather than the MCP request or admin-only TLS policy. Missing/unsupported schemes or hosts, whitespace/control characters, malformed percent escapes, and invalid authority syntax are explicitly unknown; this is a local syntax guard, not full URL or DNS validation.
- Debug-log findings no longer disclose filesystem paths and warn when access is unverified instead of treating a neighboring `.htaccess` file or a location outside `wp-content` as proof of protection.
- Database health query errors retain their error code and context without raw database error details. Successful reports still return prefixed table identifiers, including matching plugin tables; identifier redaction is deferred.
- Preserved backslashes in post/page metadata and media upload/update titles, captions, and alt text without bypassing text, HTML, or metadata-provider sanitization.
- Page and custom post type create/update abilities reject invalid or unauthorized parent assignments before saving other requested changes. Valid hierarchical parents, detach-to-zero, and omitted parents remain supported; unsupported positive parents on nonhierarchical custom post types are now rejected.
- Image URL uploads now enforce the existing WordPress upload-size limit during streaming and cancel oversized responses, with cleanup on rejection. Safe HTTP redirects, TLS, Content-MD5, actual-size and MIME checks remain intact; invalid limits and incomplete basic raster headers fail explicitly.
- Image URL validation checks mixed IPv4/IPv6 DNS answers and bounded CNAME chains, including redirect targets. IPv6 documentation addresses are rejected consistently across PHP versions. DNS failures are explicit; these checks do not pin DNS or eliminate rebinding races.
- Reject malformed, missing, or too-soon scheduling dates before creating or updating posts, pages, and custom post types. Preserve valid existing schedules, legacy date parsing, and explicit-offset instants across repeated DST hours.
- Retain validated future dates when scheduling draft or pending content with a previously unset GMT date instead of letting WordPress reset the date and publish immediately.

- Category and tag writes now respect the registered taxonomy's editing/deletion capabilities and existing-term permissions, including direct execution. Default Editor/Administrator access, read behavior, and successful response shapes are unchanged; sites with custom restrictions now receive explicit denials before writes.
- Refused or failed term deletions no longer report success. WordPress's default-category protection is respected, and a zero/false deletion result remains a failure even if site permission filters allow the attempt.

- Targeted block and section patches no longer sanitize unrelated stored HTML in the rebuilt body. Exact patches now match the original raw search fragment. Replacement fragments remain sanitized, object permissions are unchanged, and WordPress's normal capability-dependent save filters still apply.
- Site Kit module, permission, and PageSpeed abilities now require WordPress `read` before any upstream work, in addition to Site Kit's route authorization. Direct execution also rejects missing or unusable upstream permission callbacks. Site Kit-authorized shared-dashboard users remain eligible; status keeps its existing administrator capability gate.
- Post, page, custom post type, and bulk post trash abilities now refuse with `trash_disabled` when WordPress trash is disabled, preventing permanent deletion behind a misleading trash-success response. Normal trash behavior, permissions, and per-ID bulk summaries are unchanged; no permanent-delete override is added.
- Webmaster verification now omits WordPress-only Site Kit installation/activation details for callers without plugin activation permission, skips private inspection, and summarizes only authorized checks. Public checks remain available with `read`; direct execution enforces the same gate before any work.
- Public webmaster verification results, including failures and unknowns, now share a site/home/schema-scoped 60-second cache to avoid repeated HTTP/DNS work on warm calls. Privileged plugin state remains fresh per request and is never stored in the shared public cache.
- Custom post type update abilities now validate requested taxonomy assignments (registration and assign-terms permission) before saving title, content, or status changes, matching the create ability's behavior. Invalid or unauthorized taxonomy requests are now rejected before any changes are persisted, instead of after the post was already updated.

## 2.5.0

### Added

- Added a readme.txt FAQ entry covering which AI clients and MCP hosts work with the plugin.

### Changed

- Updated the readme.txt short description and Description section to lead with the 70+ permission-aware ability count across 16 areas and to note supported MCP clients.
- Updated the WordPress.org plugin tags to `mcp, ai, automation, content-management, claude`.
- Expanded targeted content patching to pages and public editor-enabled custom post types while preserving object-level edit permissions and returning an explicit unsupported-type error.

## 2.4.1

### Changed

- Hardened post, page, custom post type, media, and SEO score list abilities so returned items and totals only include objects the caller can access for the requested status, including private and trashed content.
- Removed plugin auto-update status from plugin responses to avoid Plugin Check updater-detection warnings; plugin update availability remains read-only.
- Restricted runtime environment details, WordPress version, and theme version to Administrator-capable users while keeping low-privilege site basics available.
- Removed author login fields from content responses and gated user login/email fields behind user-edit permissions.
- Required publish capabilities for private, scheduled, and published status changes and for bulk publishing.
- Replaced plugin activation/deactivation public permission callbacks with their real plugin-management permission checks while retaining execute-time checks.

## 2.4.0

### Added

- Added expanded Yoast SEO free metadata coverage for canonical URLs, breadcrumb titles, Schema.org page/article types, Open Graph and Twitter metadata, primary category, robots directives, inclusive-language score inspection, generated Yoast head inspection, and deeper sitemap index diagnostics.
- Added first-class SEOPress free metadata coverage for titles, descriptions, target keywords, canonical URLs, Open Graph and Twitter/X metadata, primary category, robots directives, breadcrumb titles, read-only metadata inspection, and SEOPress-specific site overview diagnostics.

## 2.3.0

### Added

- Added a media sideload ability to upload public image URLs into the media library with URL safety checks, image MIME and upload-size enforcement, optional title/alt/caption metadata, and optional featured-image assignment.
- Added a read-only webmaster verification status ability for public Google/Bing proof, homepage verification meta tags, Bing XML verification, visible DNS TXT records, robots.txt sitemap declarations, and sitemap reachability without Google or Bing API credentials.
- Added an Administrator-only user access audit for administrator account inventory, default `admin` username detection, administrator application password reporting, warnings, and application-password collection metadata.
- Added an Administrator-only plugin audit for inactive plugins, cached updates, tested-up-to compatibility, potential abandonment, local file age, and cached security-update flags without network calls.
- Added Administrator-only performance status diagnostics for object-cache status, known page-cache plugin detection, memory limits, revision limits, autosave interval, and script concatenation.
- Added Administrator-only backup status diagnostics for known backup plugin detection, UpdraftPlus last-backup and schedule reporting, BackWPup last-backup reporting, and no-backup warnings.
- Added read-only content hygiene abilities to list orphaned media, published posts or pages missing featured images, and stuck scheduled posts with capability checks and empty results when no problems are found.
- Added bulk post trash and bulk draft-publish abilities with per-ID success/failure summaries and `delete_posts` / `edit_posts` capability checks.
- Added Administrator-only database health diagnostics for revision bloat, orphaned post meta, expired transients, autoloaded option size, and per-table sizes.
- Added discoverability and CRUD abilities for eligible public custom post types, with deterministic naming, CPT-specific capability checks, and taxonomy term assignment support.
- Added site introspection abilities for stable, non-sensitive site, current-user, and runtime environment context with `read` capability checks.
- Added post meta read, update, and delete abilities with object-level `edit_post` checks, protected-key safeguards, typed responses, scalar/JSON value support, and key/value limits.
- Added revision abilities to list saved revisions for posts and pages and restore content to a specific revision with `edit_posts` and object-level edit checks.
- Added `author_name` and `author_login` to post and page responses so listings expose human-readable author details alongside the numeric author ID.
- Added category and tag get-by-ID abilities requiring `read`, plus category and tag update abilities requiring `manage_categories`.
- Added comment reply and update abilities with threaded reply creation, comment content updates, optional moderation status changes, capability checks, and normalized comment responses.

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
