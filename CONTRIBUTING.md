# Contributing to Unlock MCP Potential

Thanks for your interest in contributing. This document covers how to report bugs, request features, and submit code changes.

---

## Ways to contribute

- **Bug reports** — open a GitHub Issue describing what happened, what you expected, and your WordPress/PHP versions
- **Feature requests** — open a GitHub Issue describing the ability you want and why it's useful for AI agents
- **Pull requests** — bug fixes and features from the backlog are welcome; see below for conventions

---

## Development setup

### Prerequisites

- WordPress 6.9+ (local install — [LocalWP](https://localwp.com/) is recommended)
- PHP 8.0+
- Composer for WordPress Coding Standards checks
- The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin installed and active
- An MCP client for testing (Claude Code or Claude Desktop)

### Setup

1. Clone this repo into your local WordPress plugins directory:
   ```bash
   git clone https://github.com/DanielBoring/unlock-mcp-potential.git unlock-mcp-potential
   ```
2. Activate the plugin via WP Admin or WP-CLI:
   ```bash
   wp plugin activate unlock-mcp-potential
   ```
3. Create a test user with the Editor role and generate an application password (see README.md for details)
4. Point your MCP client at the local site and run `mcp-adapter-discover-abilities` to confirm the abilities load

---

## Code conventions

This plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) and is evaluated against the [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/). Key rules enforced throughout the codebase:

- **Sanitize inputs** — use `sanitize_text_field()` for strings, `absint()` for IDs, `wp_kses_post()` for HTML content, and enum validation for fixed-value fields
- **Capability checks** — every ability must have a `permission_callback` that returns a `WP_Error` on failure, not just `false`; prefer object-specific checks such as `edit_post` / `delete_post` when an object ID is available
- **No direct database queries** — use WordPress API functions (`get_posts()`, `wp_insert_post()`, etc.) exclusively
- **No output buffering** — abilities return arrays or `WP_Error` objects; the MCP Adapter handles serialization
- **WordPress.org readiness** — avoid trademark-confusing names, spammy readme text, undisclosed external calls, bundled duplicate libraries, and non-GPL-compatible assets

Run standards checks before opening a PR:

```bash
composer install
composer phpcs
```

---

## Adding a new ability

Each group of abilities lives in its own file under `includes/`. Follow the existing pattern:

1. **Create or open** the relevant class file (e.g., `includes/class-media.php`)
2. **Register** the ability inside the class's `register()` method using `wp_register_ability()`
3. **Use the `unlock-mcp-potential/` prefix** for the ability name (e.g., `unlock-mcp-potential/list-media`)
4. **Require the narrowest relevant capability** in `permission_callback` — use object-specific checks when an input ID is available and never skip the check
5. **Return a consistent shape** — match the affected ability group's existing success arrays and `WP_Error`/structured error responses
6. **Require the class file** in `unlock-mcp-potential.php` inside the `wp_abilities_api_init` action and call `ClassName::register()`
7. **Add E2E manifest coverage** in `tests/e2e/abilities-manifest.json`; include both allowed and denied roles when permissions differ by role or capability
8. **Update docs and changelogs** when behavior is user-facing: `README.md`, `readme.txt`, relevant markdown files, and `CHANGELOG.md` under `## Unreleased`. Changelog entries should use plugin-facing release-note wording rather than raw internal ability namespace strings unless the exact MCP tool name is necessary. Repository, CI, contributor, GitHub platform, template, or agent workflow changes belong in `.github/REPOSITORY_CHANGELOG.md`.

A minimal ability skeleton:

```php
wp_register_ability( 'unlock-mcp-potential/your-ability', [
    'label'               => 'Label shown in MCP clients',
    'description'         => 'One-sentence description.',
    'category'            => 'unlock-mcp-potential',
    'input_schema'        => [
        'type'       => 'object',
        'properties' => [
            'example_id' => [ 'type' => 'integer', 'description' => 'An ID.' ],
        ],
        'required' => [ 'example_id' ],
    ],
    'execute_callback'    => [ self::class, 'execute_your_ability' ],
    'permission_callback' => function ( $input ) {
        if ( ! current_user_can( 'edit_post', absint( $input['example_id'] ?? 0 ) ) ) {
            return new WP_Error( 'forbidden', 'Requires edit_post capability for this object.' );
        }
        return true;
    },
    'meta' => [
        'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
        'mcp'         => [ 'public' => true, 'type' => 'tool' ],
    ],
] );
```

Set `annotations` accurately — `readonly: true` for read-only abilities, `destructive: true` for deletes, `idempotent: true` if calling it twice produces the same result.

---

## Submitting a pull request

1. Fork the repo and create a branch from `main` (`feature/your-feature` or `fix/your-bug`)
2. Make your changes following the conventions above
3. Run `composer phpcs`
4. Test manually against a real WordPress install — verify the ability appears in `mcp-adapter-discover-abilities` and returns correct output
5. If the PR adds or changes abilities, update `tests/e2e/abilities-manifest.json` with matching coverage
6. Update user-facing docs and add a `CHANGELOG.md` entry under `## Unreleased`; use `.github/REPOSITORY_CHANGELOG.md` for repo/platform-only changes
7. Review the WordPress.org Detailed Plugin Guidelines when the change affects naming, readme text, privacy/external calls, licensing/assets, or release packaging
8. Open a PR with a clear description of what changed and why

---

## WordPress.org plugin guideline review

Before packaging a release for WordPress.org, maintainers compare the final plugin against the Detailed Plugin Guidelines. Contributors should also consider these items for public-facing or release-impacting PRs:

- Confirm the display name and slug do not begin with or imply ownership of another project or trademark.
- Keep readme tags to five or fewer, avoid keyword stuffing, and only link to directly relevant resources.
- Document any external service dependency or remote request. This plugin should not track users, load executable code from third-party services, or add public-facing credits.
- Confirm all bundled code, screenshots, and assets are GPL-compatible.
- Use WordPress-bundled libraries instead of shipping duplicate copies.
- Verify the `Version` header, `Stable tag`, changelog, release zip name, and top-level zip directory all describe the same release.

---

## Versioning policy

This project follows **Semantic Versioning** (`MAJOR.MINOR.PATCH`):

- **MAJOR** (`X.0.0`) — breaking changes to ability names, required inputs, output shape, or behavior that can break existing MCP clients.
- **MINOR** (`1.X.0`) — new backward-compatible abilities or features.
- **PATCH** (`1.4.X`) — backward-compatible bug fixes, security fixes, and documentation-only corrections.

Release checklist:

1. Choose the version bump based on the rules above.
2. Update the `Version` header in `unlock-mcp-potential.php`.
3. Move relevant plugin-facing `CHANGELOG.md` entries from `## Unreleased` into the new version section. Do not include `.github/REPOSITORY_CHANGELOG.md` entries in plugin release notes.
4. Update `Stable tag`, plugin changelog entries, and upgrade notice in `readme.txt`.
5. Confirm the generated zip uses the `unlock-mcp-potential` directory slug.
6. Tag and push `vX.Y.Z` to trigger the release workflow.

---

## Release process (maintainers)

1. Update the `Version` header in `unlock-mcp-potential.php`
2. Move plugin-facing `CHANGELOG.md` notes from `## Unreleased` into the release version
3. Add release notes to `readme.txt` under `== Changelog ==` and `== Upgrade Notice ==`
4. Commit: `git commit -m "chore: release v1.x.x"`
5. Tag and push: `git tag v1.x.x && git push origin v1.x.x`
6. GitHub Actions builds the zip and creates the release automatically
7. Download the zip from the release and upload it to the WordPress.org SVN

---

## Questions

Open a GitHub Issue and use the `question` label.
