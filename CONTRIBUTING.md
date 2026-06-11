# Contributing to WP MCP Abilities

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
- PHP 7.4+
- The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin installed and active
- An MCP client for testing (Claude Code or Claude Desktop)

### Setup

1. Clone this repo into your local WordPress plugins directory:
   ```bash
   git clone https://github.com/DanielBoring/wordpress-mcp-abilities.git wp-mcp-abilities
   ```
2. Activate the plugin via WP Admin or WP-CLI:
   ```bash
   wp plugin activate wp-mcp-abilities
   ```
3. Create a test user with the Editor role and generate an application password (see README.md for details)
4. Point your MCP client at the local site and run `mcp-adapter-discover-abilities` to confirm the abilities load

---

## Code conventions

This plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/). Key rules enforced throughout the codebase:

- **Sanitize inputs** — use `sanitize_text_field()` for strings, `absint()` for IDs, `wp_kses_post()` for HTML content, and enum validation for fixed-value fields
- **Capability checks** — every ability must have a `permission_callback` that returns a `WP_Error` on failure, not just `false`
- **No direct database queries** — use WordPress API functions (`get_posts()`, `wp_insert_post()`, etc.) exclusively
- **No output buffering** — abilities return arrays; the MCP Adapter handles serialization

---

## Adding a new ability

Each group of abilities lives in its own file under `includes/`. Follow the existing pattern:

1. **Create or open** the relevant class file (e.g., `includes/class-media.php`)
2. **Register** the ability inside the class's `register()` method using `wp_register_ability()`
3. **Use the `wp-mcp/` prefix** for the ability name (e.g., `wp-mcp/list-media`)
4. **Require `edit_posts`** (or a more specific cap) in `permission_callback` — never skip the check
5. **Return a consistent shape** — `['success' => true, 'data' => [...]]` on success, `['success' => false, 'error' => '...']` on failure
6. **Require the class file** in `wp-mcp-abilities.php` inside the `wp_abilities_api_init` action and call `ClassName::register()`

A minimal ability skeleton:

```php
wp_register_ability( 'wp-mcp/your-ability', [
    'label'               => 'Label shown in MCP clients',
    'description'         => 'One-sentence description.',
    'category'            => 'wp-mcp',
    'input_schema'        => [
        'type'       => 'object',
        'properties' => [
            'example_id' => [ 'type' => 'integer', 'description' => 'An ID.' ],
        ],
        'required' => [ 'example_id' ],
    ],
    'execute_callback'    => [ self::class, 'execute_your_ability' ],
    'permission_callback' => function () {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'forbidden', 'Requires edit_posts capability.' );
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
3. Test manually against a real WordPress install — verify the ability appears in `mcp-adapter-discover-abilities` and returns correct output
4. Update `readme.txt` — add a changelog entry under `== Changelog ==` for the new version
5. Open a PR with a clear description of what changed and why

---

## Versioning policy

This project follows **Semantic Versioning** (`MAJOR.MINOR.PATCH`):

- **MAJOR** (`X.0.0`) — breaking changes to ability names, required inputs, output shape, or behavior that can break existing MCP clients.
- **MINOR** (`1.X.0`) — new backward-compatible abilities or features.
- **PATCH** (`1.4.X`) — backward-compatible bug fixes, security fixes, and documentation-only corrections.

Release checklist:

1. Choose the version bump based on the rules above.
2. Update the `Version` header in `wp-mcp-abilities.php`.
3. Update `Stable tag` and changelog entries in `readme.txt`.
4. Tag and push `vX.Y.Z` to trigger the release workflow.

---

## Release process (maintainers)

1. Update the `Version` header in `wp-mcp-abilities.php`
2. Add a changelog entry to `readme.txt` under `== Changelog ==`
3. Commit: `git commit -m "chore: release v1.x.x"`
4. Tag and push: `git tag v1.x.x && git push origin v1.x.x`
5. GitHub Actions builds the zip and creates the release automatically
6. Download the zip from the release and upload it to the WordPress.org SVN

---

## Questions

Open a GitHub Issue and use the `question` label.
