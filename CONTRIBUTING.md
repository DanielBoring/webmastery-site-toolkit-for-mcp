# Contributing to Webmastery Site Toolkit for MCP

Thanks for your interest in contributing. This document covers how to report bugs, request features, and submit code changes.

---

## Ways to contribute

- **Bug reports** — open a GitHub Issue describing what happened, what you expected, and your WordPress/PHP versions
- **Feature requests** — open a GitHub Issue describing the ability you want and why it's useful for AI agents
- **Pull requests** — bug fixes and features from the backlog are welcome; see below for conventions

Suspected security vulnerabilities must use [private vulnerability reporting](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/security/advisories/new), not public issues. Read [SECURITY.md](SECURITY.md) before submitting a report; redact credentials and private site data.

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
   git clone https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp.git webmastery-site-toolkit-for-mcp
   ```
2. Activate the plugin via WP Admin or WP-CLI:
   ```bash
   wp plugin activate webmastery-site-toolkit-for-mcp
   ```
3. Create a test user with the Editor role and generate an application password (see README.md for details)
4. Point your MCP client at the local site and run the MCP Adapter discovery tool, `mcp-adapter-discover-abilities`, to confirm the abilities load

---

## Code conventions

This plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) and is evaluated against the [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/). Key rules enforced throughout the codebase:

- **Sanitize inputs** — use `sanitize_text_field()` for strings, `absint()` for IDs, `wp_kses_post()` for HTML content, and enum validation for fixed-value fields
- **Strict callback inputs** — declare exact types/enums and fixed property sets. The owned registration filter closes fixed objects and checks structural/type/enum/range/item constraints before raw permission and execute callbacks; it does not replace core format/combinator validation. Mark genuine extension maps explicitly open, preserve polymorphic metadata, test omitted versus null values and unknown keys with zero-work counters, and keep permission failures native `WP_Error`. Test schema/guard mutations, parent detach/omission, and direct/native/gateway/individual boundaries without weakening layer-specific errors.
- **Capability checks** — every ability must have a `permission_callback` that returns a `WP_Error` on failure, not just `false`; prefer object-specific checks such as `edit_post` / `delete_post` when an object ID is available, and make list/query abilities filter each returned object plus totals before exposing full details
- **Security-sensitive abilities** — include allowed and denied manifest cases for abilities that expose private data, user identity, environment/plugin details, destructive actions, uploads, or status transitions; use `assert_missing_paths` when lower-privilege responses must hide fields
- **Metadata boundaries** — reject metadata containers and reserved SEO aliases in general create/update before mutation, including empty/null values. Use real-object effective key capabilities for separate writes and SEO reads; prove denied calls have no writes/hooks or forbidden reads across direct, ability, gateway, and individual-tool execution. Preserve plain-content and draft/write/publish migration controls; see `docs/3.0-migration.md`.
- **Destructive interlocks** — retain exact boolean confirmation for the five guarded abilities, raw bulk bounds before normalization, and strict optional flags. Prove previews/denials leave posts, metadata, terms, files, cron, and mutation hooks unchanged; distinguish permission-callback rejection from direct/per-item denial. Media force must never bypass capability or reference-query failure. Preserve existing caller assertions when adding confirmation.
- **Delegated permissions** — require a local WordPress capability floor plus the exact upstream route permission in both permission and direct execution paths. Test missing/non-callable upstream checks, and distinguish controlled fixtures from version-specific real-provider inspection; see the Site Kit policy in `docs/security-strategy.md`.
- **Prefer WordPress APIs** — use WordPress API functions (`get_posts()`, `wp_insert_post()`, etc.) for normal reads and writes. Direct `$wpdb` reads are limited to administrator-only diagnostics such as database health checks, must be prepared where variables are present, and must surface query errors.
- **No output buffering** — abilities return arrays or `WP_Error` objects; the MCP Adapter handles serialization
- **WordPress.org readiness** — avoid trademark-confusing names, spammy readme text, undisclosed external calls, bundled duplicate libraries, and non-GPL-compatible assets

Run local QA before opening a PR:

```bash
composer install
composer qa
```

`composer qa` runs the normal fast pre-PR checks: Static QA plus Unit Tests. See [`docs/qa-strategy.md`](docs/qa-strategy.md) for the full QA posture, including when to run Ability Contract QA, Full MCP E2E QA, Release Package QA, and Compatibility QA.

PHPStan runs at level 5 with a reviewed, file/message/identifier/count-scoped baseline and a PHP 8.0 analysis target. New diagnostics must be fixed rather than added to the baseline. When fixing existing debt, lower the matching count or remove the resolved entry in the same PR; unmatched entries fail full-project analysis. Run `composer phpstan` without individual-file arguments and `composer test:phpstan-baseline` to verify the ratchet. See the [baseline review and regeneration policy](docs/qa-strategy.md#phpstan-level-5-and-baseline-ratchet).

Workflow and shell changes also need the dedicated workflow lint checks. These tools are separate from the PHP-only `composer qa` path. Docker validation must use a disposable, uniquely named Compose project; do not run cleanup commands against a shared development stack.

`composer lint:workflows` requires actionlint 1.7.12, ShellCheck 0.11.0, and zizmor 1.30.1 on `PATH`. Install the pinned upstream releases and verify their checksums as shown in `.github/workflows/workflow-lint.yml`; the command rejects missing or mismatched versions.

The repo strategy docs explain how maintainers operate the project:

- [`docs/sdlc-overview.md`](docs/sdlc-overview.md) maps the complete lifecycle from issue intake through design, implementation, QA, release, and maintenance.
- [`docs/qa-strategy.md`](docs/qa-strategy.md) explains the validation layers, what each check proves, and when each check runs.
- [`docs/ci-cd-strategy.md`](docs/ci-cd-strategy.md) covers GitHub Actions, branch protection, workflow permissions, scheduled checks, and artifact policy.
- [`docs/security-strategy.md`](docs/security-strategy.md) covers ability permissions, sensitive data, dependency and secret handling, and vulnerability response.
- [`docs/release-strategy.md`](docs/release-strategy.md) covers versioning, release readiness, GitHub releases, WordPress.org SVN publishing, hotfixes, and rollback policy.

---

## Adding a new ability

### Shared diagnostic plugin inventory

`Webmastery_MCP_Plugins::active_basenames()` supplies backup and performance diagnostics with uncached option-based inventory: local values first, followed by network option keys only on multisite, then string conversion, deduplication, and numeric reindexing. Non-array local/network options are ignored. The helper does not load the plugin API, mutate options, or check plugin-management capabilities; each diagnostic retains its existing `manage_options` permission callback.

This is only the identical diagnostic-inventory portion of #119. Do not use inventory membership as a substitute for SEO or Site Kit provider readiness: Yoast uses runtime constants/classes/functions, SEOPress combines readiness signals with its existing local-option cast, and Site Kit combines core plugin APIs with loaded-provider and installed/version information. Those policies and the other helper rows remain separate.

`PluginInventoryTest` characterizes the actual shared helper and both diagnostic forwarding methods through isolated WordPress stubs, including fresh filtered reads, malformed options, exact response shapes, and unchanged capability checks. These units complement, rather than replace, the existing diagnostic allowed/denied E2E manifest cases and real WordPress/MCP checks.

### Ability registration

Each group of abilities lives in its own file under `includes/`. Follow the existing pattern:

1. **Create or open** the relevant class file (e.g., `includes/class-media.php`)
2. **Register** the ability inside the class's `register()` method using `wp_register_ability()`
3. **Use the `webmastery-site-toolkit-for-mcp/` prefix** for the ability name (e.g., `webmastery-site-toolkit-for-mcp/list-media`)
4. **Require the narrowest relevant capability** in `permission_callback` — use object-specific checks when an input ID is available and never skip the check
5. **Return a consistent shape** — preserve successful payloads; use `Webmastery_MCP_Response::error()` for canonical failures and `local_error()` for safe native permission/helper diagnostics. Never trust provider messages/data based on a familiar code. Cover direct execution, native permission, gateway, individual-tool, and foreign-namespace isolation; see `docs/3.0-migration.md`.
6. **Require the class file** in `webmastery-site-toolkit-for-mcp.php` inside the `wp_abilities_api_init` action and call `ClassName::register()`
7. **Add E2E manifest coverage** in `tests/e2e/abilities-manifest.json`; include both allowed and denied roles when permissions differ by role or capability, plus missing-path assertions when sensitive fields should be absent
8. **Update docs and changelogs** when behavior is user-facing: `README.md`, `readme.txt`, relevant markdown files, and `CHANGELOG.md` under `## Unreleased`. Changelog entries should use plugin-facing release-note wording rather than raw internal ability namespace strings unless the exact MCP tool name is necessary. Repository, CI, contributor, GitHub platform, template, or agent workflow changes belong in `.github/REPOSITORY_CHANGELOG.md`.

A minimal ability skeleton:

```php
wp_register_ability( 'webmastery-site-toolkit-for-mcp/your-ability', [
    'label'               => 'Label shown in MCP clients',
    'description'         => 'One-sentence description.',
    'category'            => 'webmastery-site-toolkit-for-mcp',
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
            return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this object.' );
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
3. Run local QA with `composer qa` or one of the helper scripts below
4. Test manually against a real WordPress install — verify the ability appears in the MCP Adapter discovery tool, `mcp-adapter-discover-abilities`, and returns correct output
5. If the PR adds or changes abilities, update `tests/e2e/abilities-manifest.json` with matching coverage
6. Update user-facing docs and add a `CHANGELOG.md` entry under `## Unreleased`; use `.github/REPOSITORY_CHANGELOG.md` for repo/platform-only changes
7. Review the WordPress.org Detailed Plugin Guidelines when the change affects naming, readme text, privacy/external calls, licensing/assets, or release packaging
8. Open a PR with a clear description of what changed and why

Before merge, require successful `1 - Static QA`, `2 - Unit Tests`, and `Docker QA gate` checks from real PR runs. The Docker gate accepts skipped runtime jobs only after successful change detection identifies a non-runtime change. Fork and read-only bot PRs retain job summaries without requiring a writable comment token.

GitHub Actions-created baseline PRs need a maintainer to approve their PR-event workflows. Manually dispatched QA is useful for investigation but does not satisfy required branch-ruleset checks. Do not substitute a green dispatch run for a green PR check.

---

## Local QA commands

Use the fastest command that covers your change:

Environment-specific notes for GitHub Actions, Windows PowerShell, and Windows Git Bash live in [`docs/qa-strategy.md`](docs/qa-strategy.md#environment-notes).

| Command | What it runs |
| --- | --- |
| `composer qa` | Fast local default: Static QA plus Unit Tests |
| `composer qa:static` | PHP lint, PHPCS, PHPStan level 5 plus baseline regression guards, Composer audit, manifest structure validation, security QA validation, and `git diff --check` |
| `composer qa:unit` | PHPUnit unit tests |
| `composer lint:workflows` | Actionlint, ShellCheck, and offline zizmor using the required versions already on `PATH` |
| `composer test:release-safeguards` | Package/metadata/artifact, package-runtime orchestration and checker regressions plus local Git tag/ancestry fixtures; requires PHP ZipArchive and Bash, not Docker |
| `composer test:ci-safeguards` | PHPStan baseline guards, all release safeguards, and verified-download and pinned/floating dependency tests; also runs in the PHP 8.0/8.4 CI unit jobs |
| `composer test:phpstan-baseline` | Isolated inherited-config proof that new type errors, excess counts, and stale baseline entries fail; resolved debt can be ratcheted down |
| `composer validate:security-qa` | Static security QA policy checks for risky permission callbacks and required permission-hardening manifest cases |
| `composer qa:contract` | Docker Ability Contract QA against an already-running Compose stack |
| `composer qa:e2e` | Docker Full MCP E2E QA against an already-running Compose stack |
| `composer qa:release` | Release Package QA: original-ZIP extraction as the Docker plugin root, contract/transport QA, exact package validation, and WordPress Plugin Check on a separate pristine extraction |
| `E2E_MANAGE_COMPOSE=1 composer qa:contract` | Ability Contract QA with automatic Compose startup and cleanup |
| `E2E_MANAGE_COMPOSE=1 composer qa:e2e` | Full MCP E2E QA with automatic Compose startup and cleanup |
| `scripts/qa-local.sh --contract` | Unix/Git Bash wrapper for Composer QA plus managed Ability Contract QA |
| `scripts/qa-local.sh --e2e` | Unix/Git Bash wrapper for Composer QA plus managed Full MCP E2E QA |
| `scripts/qa-local.sh --release` | Unix/Git Bash wrapper for Composer QA plus Release Package QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract` | PowerShell wrapper for Composer QA plus managed Ability Contract QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E` | PowerShell wrapper for Composer QA plus managed Full MCP E2E QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release` | PowerShell wrapper for Composer QA plus Release Package QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -PreflightOnly` | Windows prerequisite check without installing dependencies or running QA |
| `scripts/qa-local.sh --preflight-only` | Unix/Git Bash prerequisite check without installing dependencies or running QA |

For Windows, install PHP and Composer first, then restart your terminal so both commands are available on `PATH`. A winget-based PHP install is acceptable when it provides PHP 8.0+:

```powershell
winget search PHP --source winget
winget install --id PHP.PHP.8.2 --source winget
php -v
composer --version
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -PreflightOnly
```

If winget installs PHP but `php` is still unavailable, add the package directory that contains `php.exe` to your user `PATH`, then restart the terminal. Composer can be installed globally, or as a user-local wrapper that invokes `composer.phar` with the PHP binary.

If you run the Bash wrapper from WSL, install PHP and Composer inside WSL. Windows `php.exe` and `composer.bat` may appear on WSL's `PATH`, but the Bash wrapper expects Unix `php` and `composer` commands. Use the PowerShell wrapper when validating with Windows-installed PHP and Composer.

If a Windows Composer installation cannot invoke `vendor/bin/phpcs` by name, run the equivalent direct fallback:

```powershell
php vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
```

The lightweight manifest validator checks JSON structure, expected fields, roles, labels, and assertion shapes. Full ability registration coverage still requires Ability Contract QA because registered abilities are only available inside WordPress after the plugin and MCP Adapter load.

When checking GitHub Actions status from the CLI, include the PR number or branch before `--repo`:

```bash
gh pr checks 67 --repo DanielBoring/webmastery-site-toolkit-for-mcp --watch=false
gh pr checks issue-18-content-hygiene --repo DanielBoring/webmastery-site-toolkit-for-mcp --watch
```

---

## WordPress.org plugin guideline review

Before packaging a release for WordPress.org, maintainers compare the final plugin against the Detailed Plugin Guidelines. Contributors should also consider these items for public-facing or release-impacting PRs:

- Confirm the display name and slug do not begin with or imply ownership of another project or trademark.
- Keep readme tags to five or fewer, avoid keyword stuffing, and only link to directly relevant resources.
- Document any external service dependency or remote request. This plugin should not track users, load executable code from third-party services, or add public-facing credits.
- Confirm all bundled code, screenshots, and assets are GPL-compatible.
- Use WordPress-bundled libraries instead of shipping duplicate copies.
- Verify the `Version` header, `Stable tag`, changelog, release zip name, and top-level zip directory all describe the same release.

Use this quick mapping when deciding whether a PR needs guideline review:

| PR changes | Guideline concerns to review |
| --- | --- |
| Plugin name, slug, description, tags, screenshots, banners, or `readme.txt` | Trademarks/project names, readme spam, affiliate/service link disclosure, and public claims. |
| Assets, bundled libraries, generated files, or build steps | GPL compatibility, human-readable source, public build instructions, and WordPress default library usage. |
| External APIs, telemetry, remote requests, ads, credits, or SaaS integrations | Explicit consent, service documentation, privacy policy/readme disclosure, and no executable remote code. |
| Admin notices, dashboards, settings pages, or upsell copy | No dashboard hijacking, notices are scoped/dismissible/actionable, and no misleading locked-feature or trialware behavior. |
| Release metadata, package contents, tags, or SVN upload | Stable version availability, version increment, `Stable tag` alignment, descriptive SVN commits, and no rapid SVN churn. |

---

## Versioning policy

This project follows **Semantic Versioning** (`MAJOR.MINOR.PATCH`):

- **MAJOR** (`X.0.0`) — breaking changes to ability names, required inputs, output shape, or behavior that can break existing MCP clients.
- **MINOR** (`1.X.0`) — new backward-compatible abilities or features.
- **PATCH** (`1.4.X`) — backward-compatible bug fixes, security fixes, and documentation-only corrections.

Git tags use `vX.Y.Z`. WordPress.org SVN tags use `X.Y.Z`. Every user-facing code release, including patch releases, should go through the protected WordPress.org SVN deployment path after release QA passes. Cosmetic readme or Plugin Directory asset updates do not require a code version bump.

Release checklist:

1. Choose the version bump based on the rules above.
2. Update the `Version` header in `webmastery-site-toolkit-for-mcp.php`.
3. Move relevant plugin-facing `CHANGELOG.md` entries from `## Unreleased` into the new version section. Do not include `.github/REPOSITORY_CHANGELOG.md` entries in plugin release notes.
4. Update `Stable tag`, plugin changelog entries, and upgrade notice in `readme.txt`.
5. Confirm the generated zip uses the `webmastery-site-toolkit-for-mcp` directory slug.
6. Run the official WordPress Plugin Check utility against the generated `webmastery-site-toolkit-for-mcp` package and confirm there are no unexpected errors.
7. Review the latest scheduled/manual `6 - Compatibility QA` result for upstream WordPress/PHP/plugin dependency drift.
8. Confirm the protected `wordpress-org` GitHub Environment and `SVN_USERNAME` / `SVN_PASSWORD` GitHub Actions secrets are configured.
9. Tag and push `vX.Y.Z` to trigger the release workflow.
10. Review the ready-for-approval summary and approve **Publish release (GitHub approval required)** after release QA passes. The `wordpress-org` environment is a GitHub production gate, not WordPress.org staff review.

Release tags must point into reviewed `main` history. Release QA must run static/unit checks as well as package validation. Approve the source SHA and validated artifact, not a rebuilt replacement. Publishing is serialized across all release tags; if SVN succeeds but a later step fails, follow the partial-failure procedure rather than moving a tag or starting a different build.

If recovery needs workflow fixes, follow the [guarded recovery runbook](docs/release-strategy.md#recovery-when-the-frozen-workflow-itself-needs-a-fix). A separately authorized annotated `v-release-recovery-*` control tag on the reviewed merged fix satisfies the existing tag-only environment policy; it is not a plugin release and its push never triggers release QA/publication. Only dispatch on that control tag may resume the original successful QA run/artifact, without rebuilding or modifying the original release tag. Keep the `wordpress-org` environment ID, protections and scoped secrets unchanged. `composer test:release-safeguards` covers resolver provenance/authorization, control-ref entrypoints and host-SVN prerequisite failures with mocks/local Git fixtures, without Docker or production writes.

---

## Release process (maintainers)

See [`docs/release-strategy.md`](docs/release-strategy.md) for the complete release governance model and official GitHub/WordPress references.

1. Update the `Version` header in `webmastery-site-toolkit-for-mcp.php`
2. Move plugin-facing `CHANGELOG.md` notes from `## Unreleased` into the release version
3. Add release notes to `readme.txt` under `== Changelog ==` and `== Upgrade Notice ==`
4. Commit: `git commit -m "chore: release v1.x.x"`
5. Tag and push: `git tag v1.x.x && git push origin v1.x.x`
6. GitHub Actions runs contract, transport, package, and Plugin Check validation
7. Approve the protected `wordpress-org` environment when the validated tag should publish to WordPress.org
8. GitHub Actions deploys the curated build directory to WordPress.org SVN and creates the GitHub Release automatically
9. Verify the WordPress.org Plugin Directory page updates as expected

### Plugin Check notes

The official Plugin Check utility must evaluate the built package from an `webmastery-site-toolkit-for-mcp` top-level directory. Running it from a differently named folder can report a text-domain mismatch even when the plugin header and i18n calls correctly use `webmastery-site-toolkit-for-mcp`.

Known warnings that are acceptable for this plugin:

- `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` in SEO overview: the Yoast focus-keyword and meta-description checks are explicit administrator-requested diagnostics, limited to 50 published posts/pages, and return IDs only.

---

## Questions

Open a GitHub Issue and use the `question` label.
