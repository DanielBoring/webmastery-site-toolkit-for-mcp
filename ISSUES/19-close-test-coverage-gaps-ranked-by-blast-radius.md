---
name: Feature request
about: Suggest a new ability or enhancement
title: '[Feature] Add the missing negative/edge test cases and unit tests, ranked by what the untested path can do'
labels: enhancement, priority: medium
assignees: ''
---

**Proposed ability name**
Test-only: `tests/unit/*`, `tests/e2e/abilities-manifest.json`, `tests/e2e/ability-runner.php`, `scripts/validate-security-qa.php`

**What should it do?**
Registered-vs-manifest coverage is already clean (85 = 85 on the fixture site; verified by `scripts/validate-e2e-manifest.php`, `ability-runner.php:836-880` and `e2e-artifacts/e2e-summary.json`, 205/205 passing on Jul 12). Unit tests cover four private helpers only. Close the gaps in this order:

1. `delete-media` — add a denial case (currently 1 success as `author`, 0 denials; permanent delete).
2. `delete-post` / `delete-page` / `delete-cpt-*` with `EMPTY_TRASH_DAYS = 0` — no scenario exists (issue 03); use per-case `setup`.
3. `delete-category` / `delete-tag` — no denial case; no default-category case (issue 13).
4. `trash-comment` / `spam-comment` / `approve-comment` / `update-comment` — add a `comment_moderator` role (`moderate_comments` only) and a comment on a post it cannot edit (issue 01).
5. `update-post-meta` / `delete-post-meta` / `update-post` (`meta`) — register a fixture key with a restrictive `auth_callback` (issue 06).
6. Add a **`contributor`** role user to the fixtures (`ability-runner.php:475-555` has admin/editor/author/subscriber/no_role + 5 custom roles, no contributor) — it is the canonical "own drafts, no publish, no delete-published" boundary the README advertises; `limited_editor` approximates only the publish half.
7. `get-post` / `get-page` — reading a **private** and a **trashed** post as a lower-privilege user (status-aware read claim).
8. `patch-content-block` / `patch-post-content` — an `unfiltered_html` case with an iframe block (issue 07).
9. Unit tests for pure helpers: `validate_public_image_url` (behind a resolver seam), `parse_block_path`, `replace_block_by_segments`, `patch_content_by_heading`, `patch_content_by_exact_match`, `is_same_site_url`, `normalize_post_meta_value` depth/size limits — write these **before** issue 17 moves them.
10. Success-only today, lower priority: `get-media`, `update-media`, `seo-analyze-post`, `create-category`, `create-tag`, `list-*`.

Also extend `$required_failure_cases` in `scripts/validate-security-qa.php:113-132` with every destructive ability above so coverage cannot regress.

**Required WordPress capability**
n/a

**Why does an AI agent need this?**
The manifest is the plugin's security regression net; the untested paths today are precisely the destructive and low-privilege ones.

**Relevant code**
- `tests/unit/PostsHelpersTest.php` — 5 tests / 9 assertions over 4 helpers.
- `tests/e2e/ability-runner.php:151-162` — `e2e_ensure_role()`; `:475-555` role fixtures; `:374-440` per-case `setup` (constants, HTTP mocks).
- `tests/e2e/abilities-manifest.json` — 205 cases; abilities with zero denial cases: approve-comment, create-category, create-tag, delete-category, delete-media, delete-page, delete-post, delete-tag, get-media, get-page, get-post, list-categories, list-tags, list-pages, list-posts, seo-analyze-post, spam-comment, trash-comment, update-media.
- `scripts/validate-security-qa.php:113-132` — `$required_failure_cases` (hand-maintained list).

**Additional context**
- Coverage counts come from a script over the manifest (per-ability success/denial totals) run during the assessment.
- Repository-facing change: `.github/REPOSITORY_CHANGELOG.md`; update `tests/e2e/README.md` role table.

- Assessment finding: **B-4** in `ASSESSMENT.md` (severity Medium → label `priority: medium`).
- Related: `01-…`, `03-…`, `06-…`, `07-…`, `13-…`, `17-…`.
- Environment reference:
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)
