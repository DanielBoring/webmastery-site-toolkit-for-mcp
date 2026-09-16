---
name: Bug report
about: Something isn't working correctly
title: '[Bug] README / readme.txt / plugin header / security strategy drift from registered abilities and actual behaviour'
labels: documentation, priority: low
assignees: ''
---

**Describe the bug**
- `README.md:58` lists comments "approve, **hold**, trash, or mark spam"; there is no `hold-comment` ability — `hold` exists only as `update-comment.status` (`includes/class-comments.php:66-73`).
- README Security section and readme.txt FAQ ("Are write operations safe?") say posts/pages "move to trash"; false when `EMPTY_TRASH_DAYS = 0` (issue 03).
- README "Verify" list presents `webmaster-verification-status` as a Subscriber-safe first check although it discloses plugin state (issue 09).
- Plugin header `Description:` (`webmastery-site-toolkit-for-mcp.php:5`) omits custom post types, blocks/revisions, webmaster verification and Google Site Kit.
- `docs/security-strategy.md` has no row for delegated third-party permissions (Site Kit, issue 14) and no agent / prompt-injection threat model (issue 05).
- README Comments row says "Editor"; `reply-comment` needs only `edit_posts` + `edit_post` on the post (Author, or Contributor on own posts).
- README does not describe the response envelope per transport mode (issue 16 / `ASSESSMENT.md` B-12).

**Ability name**
Documentation — `README.md`, `readme.txt`, `webmastery-site-toolkit-for-mcp.php` header, `docs/security-strategy.md`; no ability involved

**Steps to reproduce**
1. Compare `README.md` ability table and `readme.txt` Description against `grep -n wp_register_ability includes/*.php` and against the live `mcp-adapter-discover-abilities` output (75 abilities).
2. Expected: every documented ability registered, every documented guarantee true.
3. Got: the items above. No ability is documented-but-unregistered or registered-but-undocumented at the area level; the drift is in guarantees and wording.

**Relevant code**
- `README.md:58` — Comments row.
- `README.md` — Security Best Practices bullets ("Deletes for posts and pages move content to trash", "Subscriber-safe site info …", Verify list).
- `readme.txt` — FAQ "Are write operations safe?"; Description paragraph on capability checks.
- `webmastery-site-toolkit-for-mcp.php:5` — `Description:` header.
- `docs/security-strategy.md` — "Ability permission policy" table and "Threat model" section.
- `includes/class-comments.php:66-73` — `allowed_update_statuses()` (where `hold` actually lives).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Either register `hold-comment` via `register_set_status( 'hold', 'Hold Comment', 'hold' )` in `class-comments.php:11-13` (and add a manifest case) or reword the README row to "approve, trash, mark spam, or set hold via update-comment".
2. Fix the trash wording together with issue 03 and the Verify list together with issue 09.
3. Extend the plugin header description (keep it under WordPress.org's 150-character short-description limit only in readme.txt; the header may be longer).
4. Add the two security-strategy sections (issues 05, 14).
5. Add a short "Response format" section to README describing `{ success, data }`, the error contract from issue 16, and the extra wrapping in gateway mode.

**Additional context**
- `CHANGELOG.md` `## Unreleased` correctly holds only the Site Kit entry for HEAD; `readme.txt` has no `= 2.6.0 =` block yet, which `release.yml:78-82` will refuse until it exists (working as designed).
- Per `.github/copilot-instructions.md`, user-facing doc changes go in `CHANGELOG.md` under Unreleased; header/strategy edits are repository-facing.

- Assessment finding: **B-5** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- Related: `03-…`, `05-…`, `09-…`, `14-…`, `16-…`.
