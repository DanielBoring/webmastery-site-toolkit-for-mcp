# Repository Changelog

Repository, CI, contributor, and GitHub platform changes are tracked here. These entries are for maintainers and should not be promoted into plugin version notes.

Plugin-facing release notes belong in `CHANGELOG.md`.

## Unreleased

### Added

- Added a dedicated Coding Standards workflow that installs Composer dev dependencies and runs PHPCS on pushes, pull requests, and manual dispatches.

## 1.6.0

### Added

- Added WordPress Coding Standards tooling via Composer and `phpcs.xml.dist`.
- Added contribution and PR checklist guidance for WordPress.org Detailed Plugin Guidelines review.
- Added manifest-driven E2E ability coverage via `tests/e2e/abilities-manifest.json` and `tests/e2e/ability-runner.php`.
- Added an E2E coverage gate that fails when any registered `unlock-mcp-potential/*` ability is missing from the manifest.
- Added E2E execution coverage for the current 33 registered abilities with 45 manifest test cases, including positive and negative permission cases.
- Added E2E PR comments that report ability coverage counts, tested dependency versions, commit SHA, and workflow run URL.
- Added failure artifact collection for E2E failures, including Docker Compose logs, WordPress debug logs, and E2E summary JSON.
- Added E2E documentation in `tests/e2e/README.md` describing the rule that new or changed abilities must include manifest test coverage.
- Added a pull request checklist reminder to update `tests/e2e/abilities-manifest.json` when abilities are added or changed.

### Changed

- Updated README and WordPress.org readme documentation to include plugin management abilities and administrator role requirements.
- Updated repository/documentation references to align with the Unlock MCP Potential package rebrand.
- Replaced generic `WP_MCP_` PHP class prefixes with `Unlock_MCP_`.
- Clarified E2E manifest coverage documentation for the current 37 registered abilities and 57 manifest test cases.
- Updated pull request and contribution guidance so external contributors can apply WordPress.org guideline checks when relevant and keep docs, changelogs, and E2E coverage in sync.
- Added repository agent instructions for repeatable ability, documentation, changelog, contribution, and validation workflows.
- Clarified that local agent validation is a preflight check and GitHub Actions remains the authoritative PR validation gate.
- Consolidated release automation to the tag-based `release.yml` workflow.
- Hardened release validation to check tag, plugin header, `readme.txt` stable tag, plugin release notes, artifact contents, and duplicate release state before publishing.
- Updated E2E Docker testing to run against WordPress 7.0 with PHP 8.2 and MySQL 8.0.36.
- Pinned GitHub Actions to immutable commit SHAs and updated `actions/checkout` to a Node.js 24-compatible release.
- Split E2E PR commenting into a separate no-checkout job with write permissions isolated from PR-controlled code execution.

### Fixed

- Avoided Docker Compose project-name collisions between local and GitHub Actions E2E runs.

### Removed

- Removed redundant `auto-close-issue.yml` automation in favor of GitHub's native `Closes #N` / `Fixes #N` / `Resolves #N` behavior.
- Removed `auto-pr-from-issue.yml` placeholder PR automation.
- Removed merge-based `auto-release.yml` automation to avoid overlapping release paths.
- Removed `.github/README.md` so GitHub shows the project root `README.md` on the repository homepage.
