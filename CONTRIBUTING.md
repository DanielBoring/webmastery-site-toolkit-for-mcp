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

Use short `[]` array literals in `includes` and the plugin bootstrap. PHPCS
enforces this repository preference with `Generic.Arrays.DisallowLongArraySyntax`;
PHP 8.0+ supports it. Do not rewrite array type declarations, offsets or
destructuring, or normalize unrelated files as incidental formatting.

Schema runtime changes must retain the isolated three-contract/two-HTTP stage,
152 exact cases per invocation, unchanged configuration and original HTTP
state. Validate resource identities before cleanup; a retained post also retains
its actor and credential evidence. Missing or foreign cleanup proof keeps the
outer retention guard armed. Mocked stages do not replace disposable
source/original-ZIP execution.

This plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) and is evaluated against the [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/). Key rules enforced throughout the codebase:

- **Sanitize inputs** — use `sanitize_text_field()` for strings, `absint()` for IDs, `wp_kses_post()` for HTML content, and enum validation for fixed-value fields
- **Capability checks** — every ability must have a `permission_callback` that returns a `WP_Error` on failure, not just `false`; prefer object-specific checks such as `edit_post` / `delete_post` when an object ID is available, and authorize every returned list object. Bounded lists expose continuation, never unauthorized or fabricated exact totals.
- **Bounded list contracts** — retain measured candidate/query/capability, memory and response-size budgets, empty-window continuation, stable ID ties, summary omission versus exact full values, and effective object/key authorization. Document breaking pagination/projection changes and preserve existing membership/value/permission assertions. Run the direct benchmark only in an owned disposable installation; distinguish unit/static evidence from measured source/package results and independently determine expected IDs before calls.
- **Extracted owners and PHP floor** — retain current-source dispatcher/bootstrap/callback ownership and composed full/summary marker tests. Genuine PHP 8.0/8.4 interpreter checks are separate from PHPStan's PHP 8.0 analysis target and the WordPress 6.9/PHP 8.1 runtime compatibility lane; never report one as another.
- **Disposable QA lifecycle** — use the owned Compose wrapper, a fresh unique project and output directory, loopback HTTP, no database host port, generated private credentials, read-only source/package binds, separate private artifacts, and actual nonroot candidate CLI/HTTP workers. Verify ownership before lifecycle changes, preserve original failures and partial evidence, and clean up only owned resources. The trusted Docker engine and image initialization are environment assumptions, not whole-host provenance or attestation claims. Do not run candidate code as root, install host packages, mount the engine socket, use privileged containers, or reset shared/live sites.
- **Product runner evidence** — require explicit disposable opt-in and the exact versioned context before mutation. Capture original wire/stdout/stderr privately before parsing; retain each original response/case once and a complete final summary, typed case inventories, record-local markers, actual transport annotations, owned fixtures and cleanup proof. Keep journal/index overhead and terminal failure reservations inside the unchanged limits. Independently distinguish native metadata row cardinality and provider read capability; standalone writable-key access is not a provider-read oracle. Preserve partial evidence and original failures. Public CI output must be a validated safe summary, not raw payloads, credentials or host inventories. Source runtime and original-ZIP runtime results remain distinct.
- **Strict callback inputs** — declare exact types/enums and fixed property sets. The owned registration filter closes fixed objects and checks structural/type/enum/range/item constraints before raw permission and execute callbacks; it does not replace core format/combinator validation. Registered native validation must call the parent once first, preserve its error identity and empty-schema behavior, then validate strict types before permissions. Preserve normalization, lifecycle filters, actual authorization denials and direct destructive diagnostics; zero-work means original callbacks/capability/query/mutation boundaries, not all core hooks. Mark genuine extension maps explicitly open, preserve polymorphic metadata, test omitted versus null values and unknown keys with zero-work counters, and keep permission failures native `WP_Error`. Test schema/guard mutations, parent detach/omission, and direct/native/gateway/individual boundaries without weakening layer-specific errors.
- **Permission defaults** — standalone registered permission checks materialize a declared default only for null input with an exact object-root type, before the existing parent permission call once. Do not replay normalization filters, expand nullable/scalar-root handling, coerce explicit input, or relax raw callbacks. Cover Adapter empty-object normalization, allowed/denied users, nested nulls, invalid defaults, core lifecycle/reentrancy and unchanged diagnostic privacy.
- **Security-sensitive abilities** — include allowed and denied manifest cases for abilities that expose private data, user identity, environment/plugin details, destructive actions, uploads, or status transitions; use `assert_missing_paths` when lower-privilege responses must hide fields
- **Metadata boundaries** — reject metadata containers and reserved SEO aliases in general create/update before mutation, including empty/null values. Use real-object effective key capabilities for separate writes and SEO reads; prove denied calls have no writes/hooks or forbidden reads across direct, ability, gateway, and individual-tool execution. Preserve plain-content and draft/write/publish migration controls; see `docs/reference/3.0-migration.md`.
- **Untrusted result fields (3.0)** — add unique record-local `untrusted_fields` names only for present fields, including null/empty values. Preserve original values/types/HTML/block markup and container maps; never restore redacted values or put markers in canonical errors or diagnostic error subrecords. Keep SEO head output unavailable and all #110/#118 authorization/error evidence intact. See the field table in `README.md` and disposable-runtime requirements in `tests/e2e/README.md`.
- **Destructive interlocks** — retain exact boolean confirmation for the five guarded abilities, raw bulk bounds before normalization, and strict optional flags. Prove previews/denials leave posts, metadata, terms, files, cron, and mutation hooks unchanged; distinguish permission-callback rejection from direct/per-item denial. Media force must never bypass capability or reference-query failure. Preserve existing caller assertions when adding confirmation.
- **Delegated permissions** — require a local WordPress capability floor plus the exact upstream route permission in both permission and direct execution paths. Test missing/non-callable upstream checks, and distinguish controlled fixtures from version-specific real-provider inspection; see the Site Kit policy in `docs/90-security-strategy.md`.
- **Prefer WordPress APIs** — use WordPress API functions (`get_posts()`, `wp_insert_post()`, etc.) for normal reads and writes. Direct `$wpdb` reads are reserved for administrator-only diagnostics such as database health checks and the narrow attachment-reference exception below. Prepare variable values and surface query errors without exposing SQL diagnostics.
- **Bounded attachment-reference reads** — the shared orphan-list/media-deletion checker may use prepared direct SQL for non-administrator callers only after the caller's existing ability and per-object authorization checks. Check at most 100 attachment candidates, restrict thumbnail lookup to their IDs, and batch at most two escaped literal URL/GUID patterns per attachment with at most 50 patterns per content query. Preserve the existing all-post-type/status reference scope and return only per-candidate reference truth internally, never matching content, referring IDs, or sitewide counts. These bounds do not imply constant database scan time. Null, failed, or incomplete query results must fail closed, including forced deletion. Retain allowed/denied, literal-escaping, per-attachment parity, query-budget, and no-write/failure controls in `BoundedListTest` and `DestructiveSafetyTest`; real benchmark/MCP evidence remains separately required.
- **No output buffering** — abilities return arrays or `WP_Error` objects; the MCP Adapter handles serialization
- **WordPress.org readiness** — avoid trademark-confusing names, spammy readme text, undisclosed external calls, bundled duplicate libraries, and non-GPL-compatible assets

Run local QA before opening a PR:

```bash
composer install
composer qa
```

`composer qa` runs the normal fast pre-PR checks: Static QA plus Unit Tests. See the [SDLC overview](docs/00-sdlc-overview.md#qa-at-a-glance) for the full QA posture, including when to run Ability Contract QA, Full MCP E2E QA, Release Package QA, and Compatibility QA.

Run development runtime QA where the coding session runs. Local Copilot app,
CLI and editor sessions use local Docker; cloud-agent sessions use available
cloud containers or Actions. `scripts/qa-run.sh` shares the assertions and
preflights the selected engine without dispatching cloud work or provisioning
Podman. `--engine podman --engine-host SOCKET` (PowerShell: `-Engine podman
-EngineHost SOCKET`) selects the experimental Docker-compatible local API
adapter. Podman source/package runtime verification is still outstanding.
Remote TCP/SSH engines and mismatched observed servers are refused. An owned
project cannot change its recorded engine endpoint.

Product QA is separate from directory publishing checks. Use `--package` or
`-Package` for original-ZIP plugin behavior, and `--publishing` or `-Publishing`
for Plugin Check without product acceptance. `--release`/`-Release` retains
both requirements. No result establishes WordPress.org approval or listing.
`05 - Release Package QA` reports on every PR and runs package QA when ZIP inputs
change. The minimum-runtime lane runs nightly and on dispatch rather than on
every PR. Neither is a required merge check; both must be green on the commit
you tag for release.

PHPStan runs at level 5 with a reviewed, file/message/identifier/count-scoped baseline and a PHP 8.0 analysis target. New diagnostics must be fixed rather than added to the baseline. When fixing existing debt, lower the matching count or remove the resolved entry in the same PR; unmatched entries fail full-project analysis. Run `composer phpstan` without individual-file arguments and `composer test:phpstan-baseline` to verify the ratchet. See the [baseline review and regeneration policy](docs/01-static-qa.md#phpstan-level-5-and-baseline-ratchet).

Workflow and shell changes also need the dedicated workflow lint checks. These
tools are separate from the normal `composer qa` path. Genuine PHP 8.0/8.4
unit tests and lengthy applicable safeguards run in separate required jobs;
the stable unit aggregate rejects failures, cancellations and unexpected skips.
Preserve all seven main release safeguards, baseline guards, compatibility
dependency/download controls and applicable product regressions. Local
results do not replace required exact-head PR-event checks or original-ZIP
package QA for release-bound changes.

When adding PHP/Python tests or changing safeguard commands, update
`tests/qa-suites.json`; `python3 -B scripts/qa-profile.py inventory` rejects
missing or duplicate classifications and command drift. Classification and
timing do not select or skip tests. Follow the
[measurement procedure](docs/02-unit-tests.md#suite-classification-and-measurement)
before proposing reuse, parallelization or deduplication, and retain full
main/release coverage and the original failure status.

Destructive safety orchestration must retain eight serial boundary/trash-mode invocations, an actual native registration audit before test-only MU capabilities, matching real CLI/HTTP configuration, exclusive evidence reservation before credentials, and token-owned exact restoration on every exit. Keep failed journals and original failure status; do not replace real source/floor/original-package proof with mocked orchestration. See `tests/e2e/README.md`.

When query shapes change, fault fixtures must attest an actual hit on each
intended prepared query phase, owned table and candidate. Retain original and
replacement SQL in the disposable evidence, preserve no-write/hook/file checks,
and fail a zero-hit fault claim. Never discard earlier mutations or reset their
baseline to make a subsequent control pass. Boundary-oracle migrations preserve
typed inputs, actors and direct interlocks; unrelated historical ledgers remain
unchanged.

`composer lint:workflows` requires actionlint 1.7.12, ShellCheck 0.11.0, and zizmor 1.30.1 on `PATH`. Install the pinned upstream releases and verify their checksums as shown in `.github/workflows/01-static-qa.yml`; the command rejects missing or mismatched versions.

The numbered docs explain how maintainers operate the project. Each check doc
shares its number with the GitHub Actions workflow it describes:

- [`docs/00-sdlc-overview.md`](docs/00-sdlc-overview.md): the lifecycle, a TL;DR of all QA, what runs when, budgets and gates. Start here.
- [`docs/01-static-qa.md`](docs/01-static-qa.md) to [`docs/10-adapter-assessment.md`](docs/10-adapter-assessment.md): one document per check: what it proves, when it runs, what the workflow does, how to run it locally and how to read failures.
- [`docs/08-release.md`](docs/08-release.md): versioning, release readiness, publication, recovery, hotfixes and rollback.
- [`docs/90-security-strategy.md`](docs/90-security-strategy.md): ability permissions, sensitive data, dependency and secret handling, vulnerability response.
- [`docs/91-ci-cd-platform.md`](docs/91-ci-cd-platform.md): rulesets, workflow permissions, environments, artifacts and the PR process.
- [`docs/reference/`](docs/reference/): release history, the area-specific review checklist, shared helpers, the 3.0 migration guide and the Adapter 0.7 assessment.

---

## Adding a new ability

### Shared diagnostic plugin inventory

`Webmastery_MCP_Plugins::active_basenames()` supplies backup and performance diagnostics with uncached option-based inventory: local values first, followed by network option keys only on multisite, then string conversion, deduplication, and numeric reindexing. Non-array local/network options are ignored. The helper does not load the plugin API, mutate options, or check plugin-management capabilities; each diagnostic retains its existing `manage_options` permission callback.

This is the identical diagnostic-inventory portion of #119. Do not use inventory membership as a substitute for SEO or Site Kit provider readiness: Yoast uses runtime constants/classes/functions, SEOPress combines readiness signals with its existing local-option cast, and Site Kit combines core plugin APIs with loaded-provider and installed/version information. Those provider policies remain separate; the [shared-helper map](docs/reference/shared-helpers.md) records the other consolidated rows and deliberate distinctions.

`PluginInventoryTest` characterizes the actual shared helper and both diagnostic forwarding methods through isolated WordPress stubs, including fresh filtered reads, malformed options, exact response shapes, and unchanged capability checks. These units complement, rather than replace, the existing diagnostic allowed/denied E2E manifest cases and real WordPress/MCP checks.

### Ability registration

Each group of abilities lives in its own file under `includes/`. Follow the existing pattern:

1. **Create or open** the relevant class file (e.g., `includes/class-media.php`)
2. **Register** the ability inside the class's `register()` method using `wp_register_ability()`
3. **Use the `webmastery-site-toolkit-for-mcp/` prefix** for the ability name (e.g., `webmastery-site-toolkit-for-mcp/list-media`)
4. **Require the narrowest relevant capability** in `permission_callback` — use object-specific checks when an input ID is available and never skip the check
5. **Return a consistent shape** — preserve successful values/types, allowing only documented contract changes such as additive record-local markers; use `Webmastery_MCP_Response::error()` for canonical failures and `local_error()` for safe native permission/helper diagnostics. Never trust provider messages/data based on a familiar code. Cover direct execution, native permission, gateway, individual-tool, and foreign-namespace isolation; see `docs/reference/3.0-migration.md`.
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

### Shared capability and object checks (#119)
Compare registrations with actual MCP Adapter 0.6.1 `tools/list` output on
individually exposed tools: `readOnlyHint`, `destructiveHint`, and
`idempotentHint`. The default server advertises three gateway tools, not
per-ability hints; obtain ability metadata through get-info. Source/unit
checks alone do not prove emitted hints; runtime annotation proof for #108 is
pending. Both gateway and individual result data must retain field markers.
Untrusted runtime changes must retain the complete source/package-bound case
plan, original JSON types, exclusive evidence before credentials, no-config-write
CLI/HTTP restoration, owned resource cleanup and fail-closed outer retention.
Capture original wire/stdout/stderr privately before parsing; publish only
validated projections or safe witnesses, never arbitrary redacted bodies.
Keep independent host authority outside every project bind, verify the exact
prepared generation/inventory and process outcomes before retiring evidence,
and retain failed/partial evidence even after successful resource cleanup.
Admission diagnostics use closed phase/reason witnesses and explicitly surface
open/write/flush failures, including partial or zero writes and both-unwritable
channels. Retain original refusal exit 78; never disclose raw exceptions,
paths, argv or payloads, and never start runtime after failed admission.
Keep the independently bound companion through primary clear and exact private
capture validation. Complete evidence/authorization writes before its terminal
unlink; that unlink commits release. A postcommit failed/lost acknowledgment is
not retained protection or successful QA. The one public release-authorization
receipt records precommit observations only, never completion.
Report synthetic protocol tests, real POSIX process capture with mocked Docker,
native-Windows refusal controls, and genuine Ubuntu runtime proof separately.
Native-Windows positive authority/outer paths are blocked, not skipped or passed.
Neither mock-stage tests nor predecessor CI establishes dedicated runtime proof.
Markers, hints, and the #116 confirmation interlocks still in development
are defense-in-depth, not capability checks, content filters, a security
boundary, or prompt-injection prevention. Text-only output is not implemented.

### Shared capability checks (partial #119, row 2)

`includes/class-permissions.php` provides `Webmastery_MCP_Permissions::check( string $cap )` for an immediate `true` or trusted local `WP_Error`, `cap( string $cap )` for a deferred zero-argument permission closure, and `admin()` for the `manage_options` closure. Each invocation checks the current effective WordPress capability exactly once; factories never check capabilities during registration or cache a user's authorization. Denials retain `forbidden` and the exact `Requires {$cap} capability.` diagnostic through `Webmastery_MCP_Response::local_error()`. Callback input remains ignored.

Equivalent simple checks in Posts, CPTs, Media, Comments, Content Hygiene, Taxonomy, Users, SEO and administrator diagnostics now reuse these helpers, alongside the existing Health, Security, Site Info and Webmaster Verification consumers. Public permission facades and direct execution guards remain intact. Site Kit reuses only its local capability floor, then still runs its upstream route authorization.

`object( $type, $input_key, $cap, $not_found, $forbidden )` preserves the common post/page/CPT/attachment lookup-before-capability sequence and caller-specific diagnostics. It is not a replacement for creation/publication/parent checks, revisions, featured-image targets, per-item bulk policies, comment moderation, taxonomy-term capabilities, key-level metadata authorization, network plugin management, or delegated provider checks.

Related file map:

| File | Responsibility |
| --- | --- |
| `includes/class-permissions.php` | Shared simple capability checks and deferred simple/object factories |
| `includes/class-post-access.php` | Status-aware access, authorized-ID filtering/pagination, common list/author policy, domain target lookup and deferred creation/parent/metadata/revision/featured/content authorization |
| `includes/class-post-content.php` | Common full post response fields; callers retain taxonomy and attachment-specific representations |
| `includes/class-post-meta.php` | SEO tables, metadata eligibility/effective key capabilities, value normalization, combined-input rejection and get/update/delete registrations |
| `includes/class-post-writes.php` | Exactly-once slashing of already sanitized, unslashed post fields and metadata at persistence |
| `includes/class-bulk-posts.php` | Bulk IDs/schema/confirmation/preview, per-item authorization, summaries and publish/trash registrations |
| `includes/class-post-revisions.php` | Revision normalization, list/restore registrations and core revision APIs |
| `includes/class-featured-image.php` | Post/page target and attachment-image eligibility orchestration, set/remove registrations and core thumbnail APIs |
| `includes/class-content-patch.php` | Raw hashes, block paths/traversal, heading/exact algorithms and three content registrations with localized replacement sanitization |
| `includes/class-posts.php` | Slim registration dispatcher, post/page CRUD and taxonomy-aware full responses; stable metadata/bulk forwarding facades |
| `includes/class-input.php` | Existing strict schema/raw guards plus pagination primitives preserving each caller's clamps |
| `webmastery-site-toolkit-for-mcp.php` | Loads Permissions after Response and post helpers inside the ability hook before their consumers |
| `tests/unit/bootstrap.php` | Loads the production helper for unit tests |
| `tests/unit/PermissionsCharacterizationTest.php` | Actual callback/facade characterization retained from before extraction |
| `tests/unit/PermissionsTest.php` | Helper/factory behavior and mutation controls |
| `tests/unit/PermissionsLoadingTest.php` | Actual plugin bootstrap and release package-map guards |
| `tests/unit/SharedHelpersTest.php` | Actual-source before/after permissions, normalizers and key-table comparisons; access, pagination, persistence and provenance controls |
| `tests/unit/fixtures/shared-helper-loader.php` | Loads actual helper bodies in isolated namespaces so WordPress spies remain effective |
| `tests/unit/fixtures/shared-helpers-stubs.php` | Isolated WordPress behavior spies for current shared-helper contracts |
| `tests/unit/PostsCharacterizationTest.php`, `PostsExtractionTest.php` | Patch/metadata boundaries and exact 24-registration/permission/owner/source comparisons |
| `tests/unit/fixtures/posts-extraction-stubs.php` | Isolated current-owner registration, permission and response contracts |
| `tests/unit/fixtures/permissions-stubs.php`, `tests/unit/fixtures/wstm114-verification.php` | Load actual helper/ability source together within isolated WordPress namespaces |

The existing recursive `includes/` release map includes the helper without a packaging allowlist change. This is behavior-preserving repository maintenance, not a new ability or a user-facing permission policy change.

See [the requirement matrix and completed extraction map](docs/reference/shared-helpers.md) before changing an owner or list policy. Preserve object/key authorization, bounded candidate windows, continuation and ordering, provider readiness, and caller-specific clamps. Keep full/summary values and record-local markers consistent across owners. Update current-source bindings and their regression controls when bound source changes; retain historical evidence without treating deferred reversible-transition fixtures as active QA.

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

The [PR template](.github/PULL_REQUEST_TEMPLATE.md) uses this checklist:

- [ ] Capability checks use the narrowest relevant WordPress capability, authorize every returned object, and fail with `WP_Error`
- [ ] Inputs are sanitized or validated with WordPress APIs or strict schema checks
- [ ] Response shape is unchanged, or the contract change is documented
- [ ] New or changed abilities have positive cases in `tests/e2e/abilities-manifest.json`, plus permission-denied cases where permissions apply
- [ ] User-facing docs are updated (`README.md`, `readme.txt`, `tests/e2e/README.md`)
- [ ] Changelog: plugin-facing changes in `CHANGELOG.md`, repository/CI changes in `.github/REPOSITORY_CHANGELOG.md`
- [ ] `composer phpcs`, PHPStan without new baseline debt, E2E manifest validation and `git diff --check` pass
- [ ] Relevant E2E QA passes, or failures are unrelated and explained
- [ ] Workflow or shell changes pass `composer lint:workflows`, and CI stays within the budgets in `docs/00-sdlc-overview.md#ci-budgets-and-pruning`
- [ ] Workflow, trigger or gate changes update the matching numbered doc in `docs/` and the diagrams in `docs/images/` (`.drawio` source and PNG)
- [ ] WordPress.org guideline impact was considered for naming, readme, privacy, external calls, licensing, assets or packaging
- [ ] Area-specific items in `docs/reference/review-checklist.md` were checked where they apply

For the #108 runtime probes, require `WSTM108_ALLOW_DISPOSABLE=1` and an owned
isolated test installation. The opt-in is not a Docker lease; never run
mutating fixtures on shared/live sites.

Before merge, require successful `01 - Static QA`, `02 - Unit Tests`, and `03-04 - Docker QA` checks from real PR runs. The Docker gate accepts skipped runtime jobs only after successful change detection identifies a non-runtime change. Fork and read-only bot PRs retain job summaries without requiring a writable comment token.

GitHub Actions-created baseline PRs need a maintainer to approve their PR-event workflows. Manually dispatched QA is useful for investigation but does not satisfy required branch-ruleset checks. Do not substitute a green dispatch run for a green PR check.

---

## Local QA commands

Use the fastest command that covers your change:

Environment-specific notes for GitHub Actions, Windows PowerShell, and Windows Git Bash live in the [SDLC overview](docs/00-sdlc-overview.md#environment-notes).

| Command | What it runs |
| --- | --- |
| `composer qa` | Fast local default: Static QA plus Unit Tests |
| `composer qa:static` | PHP/Python AST lint, mock-only HOST prerequisite controls, PHPCS, PHPStan level 5 plus baseline regression guards, Composer audit, manifest structure validation, security QA validation, and `git diff --check` |
| `composer test:host-prerequisites` | Three fixed mock-only prerequisite/provisioning/driver suites; never native hardening or HOST acquisition |
| `composer qa:unit` | PHPUnit `product` suite: unit tests of plugin code (seconds) |
| `composer qa:tooling` | PHPUnit `qa-tooling` suite: tests of QA, release and evidence tooling (several minutes); run when you change `scripts/` or `tests/` tooling |
| `composer lint:workflows` | Actionlint, ShellCheck, and offline zizmor using the required versions already on `PATH` |
| `composer test:release-safeguards` | Package/metadata/artifact, package-runtime orchestration and checker regressions plus local Git tag/ancestry fixtures; requires PHP ZipArchive and Bash, not Docker |
| `composer test:ci-safeguards` | Python prerequisite mocks, existing Python controls, PHPStan baseline guards, all release safeguards, and verified-download and pinned/floating dependency tests; also runs in the PHP 8.4 CI safeguards job when QA-relevant files change |
| `composer test:phpstan-baseline` | Isolated inherited-config proof that new type errors, excess counts, and stale baseline entries fail; resolved debt can be ratcheted down |
| `composer validate:security-qa` | Static security QA policy checks for risky permission callbacks and required permission-hardening manifest cases |
| `composer qa:contract` | Docker Ability Contract QA against an already-running Compose stack |
| `composer qa:e2e` | Docker Full MCP E2E QA against an already-running Compose stack |
| `composer qa:release` | Release Package QA: original-ZIP extraction as the Docker plugin root, contract/transport QA, exact package validation, and WordPress Plugin Check on a separate pristine extraction |
| `composer qa:local` | Shared launcher: fresh owned source contract and HTTP product runtime |
| `composer qa:package` | Shared launcher: original-ZIP plugin QA without WordPress.org checker requirements |
| `composer qa:publishing` | Shared launcher: pristine-package Plugin Check; no product acceptance claim |
| `bash scripts/qa-floor.sh` | Build digest-pinned WordPress 6.9 / genuine PHP 8.0.30 and execute the same source contract/HTTP suite |
| `E2E_MANAGE_COMPOSE=1 composer qa:contract` | Ability Contract QA with automatic Compose startup and cleanup |
| `E2E_MANAGE_COMPOSE=1 composer qa:e2e` | Full MCP E2E QA with automatic Compose startup and cleanup |
| `scripts/qa-local.sh --contract` | Unix/Git Bash wrapper for Composer QA plus managed Ability Contract QA |
| `scripts/qa-local.sh --e2e` | Unix/Git Bash wrapper for Composer QA plus managed Full MCP E2E QA |
| `scripts/qa-local.sh --release` | Unix/Git Bash wrapper for Composer QA plus Release Package QA |
| `scripts/qa-local.sh --contract --e2e --package` | Local static/unit, source runtime and original-ZIP product QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract` | PowerShell wrapper for Composer QA plus managed Ability Contract QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E` | PowerShell wrapper for Composer QA plus managed Full MCP E2E QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release` | PowerShell wrapper for Composer QA plus Release Package QA |
| `powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract -E2E -Package` | Local static/unit, source runtime and original-ZIP product QA |
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
7. Review the latest scheduled/manual `07 - Compatibility QA` result for upstream WordPress/PHP/plugin dependency drift.
8. Confirm the protected `wordpress-org` GitHub Environment and `SVN_USERNAME` / `SVN_PASSWORD` GitHub Actions secrets are configured.
9. Tag and push `vX.Y.Z` to trigger the release workflow.
10. Review the ready-for-approval summary and approve **08 - Release / publish (GitHub approval required)** after release QA passes. The `wordpress-org` environment is a GitHub production gate, not WordPress.org staff review.

Release tags must point into reviewed `main` history. The release requires checks 01–06 to have passed on that exact `main` commit, then builds the ZIP once and runs Plugin Check. Approve the source SHA and validated artifact, not a rebuilt replacement. Publishing is serialized across all release tags; if SVN succeeds but a later step fails, follow the partial-failure procedure rather than moving a tag or starting a different build.

If recovery needs workflow fixes, follow the [guarded recovery runbook](docs/08-release.md#recovery-when-the-frozen-workflow-itself-needs-a-fix). A separately authorized annotated `v-release-recovery-*` control tag on the reviewed merged fix satisfies the existing tag-only environment policy; it is not a plugin release and its push never triggers release QA/publication. Only dispatch on that control tag may resume the original successful QA run/artifact, without rebuilding or modifying the original release tag. Keep the `wordpress-org` environment ID, protections and scoped secrets unchanged. `composer test:release-safeguards` covers resolver provenance/authorization, control-ref entrypoints and host-SVN prerequisite failures with mocks/local Git fixtures, without Docker or production writes.

---

## Release process (maintainers)

See [`docs/08-release.md`](docs/08-release.md) for the complete release governance model and official GitHub/WordPress references.

1. Update the `Version` header in `webmastery-site-toolkit-for-mcp.php`
2. Move plugin-facing `CHANGELOG.md` notes from `## Unreleased` into the release version
3. Add release notes to `readme.txt` under `== Changelog ==` and `== Upgrade Notice ==`
4. Commit: `git commit -m "chore: release v1.x.x"`
5. Tag and push: `git tag v1.x.x && git push origin v1.x.x`
6. `08 - Release` confirms checks 01–06 passed on the tagged `main` commit (dispatch 05 and 06 on `main` first if the nightly run has not covered it), builds the ZIP and runs Plugin Check
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
