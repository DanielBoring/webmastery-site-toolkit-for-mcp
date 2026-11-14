# Adding an ability: reference

This is the detailed companion to the checklist in
[CONTRIBUTING.md](../../CONTRIBUTING.md#adding-a-new-ability). The checklist
says what to do in order; this page explains each step, the files involved and
the mistakes that cost earlier changes a review round. Run every command from
the repository root.

Contents:

- [Design rules](#design-rules)
- [Plugin code and wiring](#plugin-code-and-wiring)
- [Unit tests](#unit-tests)
- [E2E manifest](#e2e-manifest)
- [Manifest case ledger](#manifest-case-ledger)
- [Count cascade](#count-cascade)
- [Source bindings](#source-bindings)
- [Untrusted-marker records](#untrusted-marker-records)
- [Docs and changelog](#docs-and-changelog)
- [Verification commands](#verification-commands)
- [Known environment-dependent failures](#known-environment-dependent-failures)

---

## Design rules

Decide these before writing code. Each one was a review finding or a CI failure
on an earlier ability.

- **Narrowest capability.** Require the narrowest capability that fits
  (`switch_themes` for a theme inventory, not `manage_options`). Use
  `Webmastery_MCP_Permissions::cap( '<cap>' )` for a single capability. Read-only
  admin inventories do not need to be added to the security QA lists (see
  [E2E manifest](#e2e-manifest)).
- **Capability first, so existence is not revealed.** When an ability takes an
  object ID, check a base capability in the permission callback first, so a role
  that lacks it gets `forbidden` for every ID. For a missing or wrong-type
  object, return `true` from the permission callback and let the execute
  callback return `not_found`. WordPress turns *any* error returned by a
  permission callback into `ability_invalid_permissions` (code `forbidden`), so a
  permission callback can never produce a `not_found` manifest case. The pattern
  is `moderate_permission()` in `includes/class-comments.php`; the policy is
  stated in [docs/90-security-strategy.md](../90-security-strategy.md) ("Authorized
  missing objects defer to guarded execution"). Prove it with a
  `@runInSeparateProcess` test over the real `Webmastery_MCP_Input` and
  `Webmastery_MCP_Ability` wrappers; copy
  `ThemesTest::test_null_input_uses_the_root_default_for_permission_and_execution`
  and `tests/unit/fixtures/native-input-ability.php`.
- **Closed input schema.** Declare an `object` schema with `properties`, exact
  types, enums and `minimum`/`maximum`. `Webmastery_MCP_Input::register_args()`
  closes the object (`additionalProperties: false`) for you. Mark a deliberately
  open map explicitly.
- **All-optional inputs need an object-root default.** An ability whose inputs
  are all optional must declare `'default' => []` on the schema root (see
  `includes/class-themes.php` and `includes/class-cron.php`). Without it a call
  with omitted or `null` input has no declared root value for the permission and
  execute wrappers to materialize. `ThemesTest` asserts both omitted and `null`
  input produce the same permission and execution result.
- **Required inputs need valid input in denied-role manifest cases.** The
  permission check runs after schema validation, so a denied-role case for an
  ability with required inputs must still send valid input, or it fails with
  `invalid_input` instead of `forbidden`.
- **No-input abilities omit `input_schema`.** `register_args()` supplies an empty
  closed object with a `[]` default. The manifest case uses `"input": null`. A
  custom permission callback that returns
  `Webmastery_MCP_Response::local_error( 'forbidden', ... )` is fine.
- **Bounded output.** Lists take `per_page` and `page` through
  `Webmastery_MCP_Input::per_page( $input, <default> )` and `::page()` and
  return `items`, `page`, `per_page` and `next_page` (null on the last page); no
  exact totals. Scans that cannot page by number use a documented cursor such as
  `next_offset`. Cap any work that scales with site size (candidates, queries,
  time, response bytes).
- **Untrusted text.** Mark stored or user-controlled text fields with
  `Webmastery_MCP_Untrusted::mark( $record, [ fields ] )`. Mark only fields that
  are present, and keep the `untrusted_fields` list identical whatever the
  caller's capabilities are: if the field set would vary by capability, sanitize
  the field to a safe token instead of marking it, because exact marker
  assertions become fragile. Write abilities that return `previous` values mark
  `previous` too.
- **`register()` must stay side-effect free.** It may call only
  `wp_register_ability()`. `InputBoundaryTest` evaluates every
  `includes/class-*.php` with a `public static function register()` in its own
  namespace with only that function stubbed. Load files such as
  `wp-admin/includes/plugin.php` lazily inside the callback, guarded by
  `! function_exists( ... ) && is_readable( ... )`.
- **Re-index lists.** Return `array_values( ... )` for any list built by
  filtering, or it serializes as a JSON object.
- **Verify third-party and core behaviour from source, not memory.** Read the
  plugin or WordPress core code (for example where a plugin stores a value, or
  what a core function does with an omitted argument) before relying on it. A
  stub that reproduces core's behaviour plus a mutation run (drop one argument,
  see the test fail, restore it) is a cheap proof.
- **Unit tests bypass the input layer.** Unit tests call the execute and
  permission callbacks directly, so only the E2E manifest catches a schema
  mistake or a permission-order mistake. Write the manifest cases (including a
  denied role and a schema rejection) as part of the design, not as an
  afterthought.
- **Annotations.** Set `readonly`, `destructive` and `idempotent` accurately and
  `meta.mcp` to `[ 'public' => true, 'type' => 'tool' ]`.
- **PHP 8.0 floor.** Do not use PHP 8.1-only functions such as `array_is_list()`
  in plugin code or in `scripts/`. Do not name a parameter `$parent` (PHPCS
  reserved-keyword warning).

## Plugin code and wiring

1. Put the class in `includes/class-<area>.php`: `defined( 'ABSPATH' ) || exit;`,
   a class with `public static function register()`, `category`
   `webmastery-site-toolkit-for-mcp`, the schema, `permission_callback`,
   `execute_callback`, `meta.annotations` and `meta.mcp`. Return
   `Webmastery_MCP_Response::ok( $data )` and report failures through
   `Webmastery_MCP_Response`. Use the skeleton in CONTRIBUTING.md and an existing
   small class (`includes/class-themes.php`, `includes/class-cron.php`) as a model.
2. In `webmastery-site-toolkit-for-mcp.php`, `require_once` the file next to the
   others inside the `wp_abilities_api_init` callback and call
   `<Class>::register();` at the end of that list. If the ability adds a new area,
   add it to the header `Description:`.
3. The release ZIP needs no change: the packaging allowlist
   (`scripts/release-lib.php`, `release_source_files()`) includes the main file,
   `readme.txt`, `LICENSE` and every `includes/**.php` file, and nothing from
   `scripts/` or `tests/`.
4. **Shared helper classes.** A class evaluated into a test namespace does not
   fall back to global classes. A new `final class Webmastery_MCP_X` used by a
   class that tests `eval()` (for example `class-seo.php` or `class-media.php`)
   must be added to the file list in `tests/unit/fixtures/shared-helper-loader.php`
   (the loader reads `final class Webmastery_MCP_\w+` from each file), required in
   `tests/unit/bootstrap.php` for tests that load the consumer globally, and wired
   in the main file before its consumers. Fixtures that do not use the loader (for
   example `tests/unit/fixtures/media-pinning-stubs.php`) must evaluate it
   themselves.
5. **Adding to an existing class** that harnesses evaluate (`class-seo.php` is
   evaluated by several stub files): every WordPress function the new code calls
   must exist in each harness namespace or be reached only by tests that stub it.
   Run the whole `product` suite, not just your test class. `ARRAY_A` is not
   defined in unit tests; pass the string `'ARRAY_A'`.
6. **Moving code out of `class-media.php`:** `MediaUrlTest` reflects on
   `Webmastery_MCP_Media` methods and overrides resolvers through late static
   binding. Keep the old method names as thin wrappers (`protected`, not
   `private`, or PHPStan reports them unused). A removed method leaves its
   PHPStan baseline entry unmatched; delete that entry (never add one).
   `phpstan-baseline.neon` has CRLF line endings, so edit it in binary mode or
   `git diff --check` reports a stray blank line.

`php vendor/bin/phpcbf --standard=phpcs.xml.dist <file>` fixes most WPCS
alignment findings in a new class; PHPCS also requires a short description line
in every docblock that has `@param`.

## Unit tests

A new area gets `tests/unit/<Area>Test.php` plus
`tests/unit/fixtures/<area>-stubs.php`.

**Stub fixture pattern** (copy `themes-stubs.php` or `menus-stubs.php`):

1. Declare a namespace for the fixture and
   `require_once __DIR__ . '/shared-helper-loader.php';`
   `\wstm_test_load_shared_helpers( __NAMESPACE__ );`. This evaluates the
   Permissions, Untrusted, List_Query and other helpers into your namespace so
   `current_user_can()` and friends resolve to your stubs.
2. Define the WordPress functions the class calls as namespaced stubs. Never
   define WordPress functions globally: they leak into every other test in the
   one `product` process. `tests/unit/bootstrap.php` already defines some globals
   (`current_user_can`, `esc_url_raw`, `get_post_meta`, `get_post`, `get_term`);
   shadow them in your namespace. It defines no `WP_Post` or `WP_Term`; declare
   minimal stand-in classes inside the namespace.
3. `eval( 'namespace X; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) )`
   the class under test.

**Other patterns**

- *Functions that must never be called:* generate throwing stubs in a loop,
  `eval( 'namespace X; function ' . $name . '( ...$args ) { throw ...; }' )`.
- *Code that reads global constants:* do not `define()` them in a test (they
  persist for the rest of the process). Put the logic in a private helper that
  takes the raw values (`config_values( $disabled, $alternate, $lock )`) and call
  it with `ReflectionMethod`. The same approach tests a helper that takes an
  injected clock (`CronTest`, `LinkScanTest`).
- *Outbound HTTP without a network:* evaluate the ability and its helper classes
  into one namespace and stub DNS, cURL and `wp_safe_remote_request` there; see
  `tests/unit/fixtures/link-scan-stubs.php`. Never add an E2E case that needs the
  internet: the QA site has no outbound access.
- *Permission wrappers:* use a `@runInSeparateProcess` test over the real
  `Webmastery_MCP_Input` and `Webmastery_MCP_Ability` (see the design rules).

**Register the test file**

1. Add `<file>tests/unit/<Area>Test.php</file>` to the `product` (plugin code) or
   `qa-tooling` (tooling) `<testsuite>` in `phpunit.xml.dist`, in ASCII order.
2. Add the file name to `production-unit` or `qa-tooling-unit` in
   `tests/qa-suites.json`, same order.
3. `python3 -B scripts/qa-profile.py inventory` must succeed. It fails on any
   unclassified `tests/unit/*Test.php` or suite drift.
4. Update the file counts in
   [docs/02-unit-tests.md](../02-unit-tests.md#suite-classification-and-measurement).

New files under `tests/unit/fixtures/` need no registration.

**Pitfalls**

- Do not name a static helper `run()` in a test class: it clashes with
  `TestCase::run()` and is a fatal error at load.
- Run a test class with `--testsuite` and `--filter`, never by file path.
  Running `phpunit tests/unit/X.php` skips the other suite files' `require_once`
  lines, so classes such as `SchemaIntegrationLedgerTest` fail with "class not
  found" on a clean tree.
- Run tooling tests as
  `php vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite qa-tooling --filter '/^ClassName::/'`.
  Combine classes with `'/^(ClassA|ClassB)::/'`.
- A few classes are namespaced, so `'/^ClassName::/'` silently executes no tests
  (exit 1, "No tests executed"). Include the namespace, for example
  `'/^(WstmLegacyArtifacts\\LegacyArtifactsTest|Wstm110Oracle\\MetadataBatchFixtureTest)::/'`,
  or drop the `^`. Check the `namespace` line at the top of the file when a
  filter runs nothing.
- Do not blind-replace numbers in test files. See [Count cascade](#count-cascade).

## E2E manifest

The cases live in `tests/e2e/abilities-manifest.json`. It is hand-formatted
(4-space indent, short arrays such as `"assert_paths": ["a", "b"]` on one line,
escaped non-ASCII characters). Edit it as text; do not re-serialize it with a
JSON pretty-printer. Only the parsed content matters for fingerprints, but a
re-serialized file produces a huge unreviewable diff.

| What | File |
| --- | --- |
| Structural validator | `scripts/validate-e2e-manifest.php` |
| Security policy validator | `scripts/validate-security-qa.php` |
| Runner (interprets every field) | `tests/e2e/ability-runner.php` |
| Error code and reason mapping | `tests/e2e/error-contract-assertions.php` (`wstm118_expected_code()`) |
| Manual checks and rules | `tests/e2e/README.md` |

### Required fields

Every case has `ability` (full name), a `label` that is unique across the whole
manifest, `role`, `input` (object, or `null` for no input) and `expect`
(`"success"` or `"failure"`). Name labels `<ability> <scenario>`. Older `wstmNNN`
prefixes are internal and are not GitHub issue numbers.

`role` must be a key of `$allowed_roles` in `scripts/validate-e2e-manifest.php`
(and `$roles` in the runner). Read both files for the current list; it includes
`admin`, `editor`, `author`, `contributor`, `subscriber` and `no_role` plus
purpose-built roles. A new role needs a user and role in the runner and an entry
in both lists.

Every `"expect": "failure"` case carries all of `expect_error_code`,
`expect_error_reason` and `"expect_error_shape": "canonical"`, and the code must
equal `wstm118_expected_code( reason )`. Common pairs:

| Failure | `expect_error_code` | `expect_error_reason` |
| --- | --- | --- |
| Permission denied | `forbidden` | `ability_invalid_permissions` |
| Schema rejection | `invalid_input` | `ability_invalid_input` |
| Missing object | `not_found` | `not_found` |

Every ability needs at least one success case, and a permissioned ability needs a
denied-role case, usually with `"assert_permission": "forbidden"` and
`"assert_missing_paths": ["data"]`. `InputBoundaryTest` fails if an ability lacks
a success case or a `forbidden` case. A list ability also gets a paging case and
a past-the-end case.

### Placeholders, fixtures and setup

Any string value `"__name__"` in `input` or an assertion is replaced by
`$fixtures['name']` (for example `__post_id__`, `__category_id__`). The whole
case passes through `e2e_resolve_placeholders()`, so placeholders work inside
`input`, `assert_values` values and `assert_list_item.match`. An unknown
placeholder throws.

New seeded data goes in `tests/e2e/ability-runner.php` next to the other
`$fixtures[...]` assignments. Make it idempotent (delete by name, recreate): the
runner can run more than once per install. Build URLs with `get_permalink()` or
`home_url()` so cases hold for plain and pretty permalinks.

`setup` keys, applied before the call and restored afterwards
(`e2e_apply_case_setup`):

- `"active_plugins": [basenames]` replaces the `active_plugins` option. Plugin
  files need not exist because detection code reads the option. A provider whose
  plugin is not on the QA site (not in the default active set) is therefore
  deterministic for an "inactive" assertion; there is no way to seed provider
  data per case.
- `"diagnostics": {...}` builds `Webmastery_MCP_Diagnostics_Fixture`
  (`tests/e2e/diagnostics-fixture.php`) and blocks outbound HTTP.
- `"http_mocks": {...}` mocks HTTP responses.
- `"wstm125_site_kit_permission": "allow"`.

There is no generic option or transient setter. Anything else needs a runner
change, which can break tooling tests that pin runner files; avoid it unless
necessary.

The QA site runs with `DISABLE_WP_CRON` set (`scripts/e2e-test.sh`), so scheduled
events never run and overdue counts are not controlled; assert shape only for
cron-dependent data.

Cases run in file order in one WordPress install, so later cases see earlier
writes. Prefer assertions that hold regardless of order ("page 2 with per_page 1
returns exactly one item" rather than exact names).

### Assertion types

Paths are dot paths into the whole result (`{"success": ..., "data": ...}`);
numeric segments index lists (`data.0.id`, `data.items.0.untrusted_fields`).

| Key | Meaning |
| --- | --- |
| `assert_paths` | Each path exists (a `null` value counts as existing). |
| `assert_missing_paths` | Each path is absent (`data.1` proves a one-item list, `data.0` an empty list). |
| `assert_values` | Strict `===` against `{path: value}`; JSON `null`, booleans and `[]` work. |
| `assert_contains`, `assert_not_contains` | `[{"path", "value"}]`, value must be a string substring. |
| `assert_permission` | `true`, `"forbidden"` or `"invalid_input"`: the result of the permission check. |
| `assert_capabilities` | `[{"capability", "args": [], "allowed": bool}]`, nonempty. |
| `assert_unchanged` / `assert_changed` | Database snapshot comparison (posts, postmeta and term relationships, so menu items are covered). `assert_changed` needs a success case and no `assert_unchanged`. Use `assert_changed` on a successful write and `assert_unchanged` on every rejected write. |
| `assert_list_item` | `{"path": list path, "match": {field: value}, "equal_paths": {field: result path}}`, success cases only. Exactly one record in the list matches every `match` value, and each `equal_paths` field equals the value at that result path. Use it when a record's position is not deterministic (the active theme in `list-themes`). |
| `assert_diagnostic_findings` | `{check: {"bucket": pass\|warn\|fail, "label": exact}}`, `security-audit` only. Exactly one finding with that `check` exists across the buckets, with that bucket and label. Use it only where the result is deterministic on the QA site (`auth_keys` passes because the image generates salts; `wp_version` depends on a cached update transient and is not deterministic). |
| `assert_bucket_counts` | Bucket counts consistent with the findings; `site-health-check` and `security-audit` only. |
| `assert_stored_post`, `assert_post_meta`, `assert_post_meta_values`, `assert_post_thumbnail`, `assert_comment_state`, `assert_term_state` | Persisted-state checks for write abilities; read the validator for each shape. |
| `assert_metadata_boundary`, `assert_wstm114_http_calls` | Specialised checks for the metadata boundary and webmaster verification. |
| `capture_post_id` | Saves the created post or page ID under a name (create-post and create-page success cases only). |
| `expect_error_message_contains` | Substring of the error message. |

**The validator ignores unknown top-level keys**, so a typo in an assertion name
silently asserts nothing. A new assertion type needs both a runner block in
`tests/e2e/ability-runner.php` and a shape check in
`scripts/validate-e2e-manifest.php`. Put the runner block after the
`assert_not_contains` handling: `tests/unit/test_qa_execution.py` slices the
runner source between the `assert_values` and `assert_diagnostic_findings`
blocks, and new code must not fall inside that slice.

### Security QA lists

`scripts/validate-security-qa.php` has `$required_failure_cases` (abilities that
must keep at least one failure case) and `$required_permission_cases` (abilities
that need a failure case with `assert_permission: "forbidden"` and reason
`ability_invalid_permissions`). Add an ability to both when it is
security-sensitive: it exposes private data, user identity, environment or plugin
details, destructive actions, uploads or status transitions, or it reads
per-post metadata. Read-only administrator inventories such as `list-plugins`,
`backup-status` and `list-themes` are not listed. Destructive bulk and delete
abilities have extra confirm and dry-run rules in the same file. A callback that
uses `__return_true` fails the scan unless allow-listed there. Nothing pins the
sizes of these lists.

### Data you cannot control

If the QA site's data is uncontrolled (transient-driven lists may be empty),
assert presence and shape with `assert_paths` and exact values only for
deterministic fields, add no marker records (see
[Untrusted-marker records](#untrusted-marker-records)), and describe the manual
check in `tests/e2e/README.md`. No QA role has only a subset of capabilities, so
per-capability omission is covered by unit tests only.

## Manifest case ledger

Many tooling tests reconstruct historical views of a sealed 601-case manifest
(its fingerprint is `after_sha256` in
`tests/unit/fixtures/qa-assertion-transition.json`). They no longer read the live
file directly. They call `ManifestCaseLedger::sealed()`
(`tests/unit/fixtures/manifest-case-ledger.php`), which reverses every step
recorded in `tests/unit/fixtures/manifest-case-ledger.json` and refuses any
unrecorded change. That is why every manifest change must be recorded, and why
the older sealed hashes and counts must never be edited to make a test pass.

After editing the manifest:

```bash
php scripts/validate-e2e-manifest.php
php tests/unit/fixtures/manifest-case-ledger.php record <issue-number> "<one-line summary>"
php tests/unit/fixtures/manifest-case-ledger.php check
```

- `record` compares the working manifest with the one at `HEAD` (pass a git ref
  as a third argument if you already committed the manifest edit, for example
  `HEAD~1`). It needs git. It prints
  `Recorded step N: A -> B cases. Set the case count in tests/e2e/product-evidence-verifier.php to B.`
- Running `record` again after further manifest edits replaces the uncommitted
  step for the same base instead of duplicating it.
- A change that only adds assertions to existing cases still needs `record`; the
  case count (and the verifier pin) stays the same.

`ManifestCaseLedgerTest` rejects: a live manifest that does not equal the sealed
manifest plus the recorded steps; a step that removes, renames or reorders a
case; a step that changes the `ability`, `label`, `role`, `input` or `expect` of
an existing case (add a new case instead; an existing case may change only its
assertions and setup); and any drift mutation it applies to the ledger. Adding
cases anywhere is fine, and the position is recorded.

The fingerprint is `sha256( json_encode( $decoded_objects, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) )`
of the manifest decoded with `json_decode( ..., false )`, so property order, int
versus float and `{}` versus `[]` all matter.

## Count cascade

A new ability and its cases move a set of pinned counts across runtime scripts,
tests and docs. Missing one fails CI (the untrusted product stage or
`UntrustedSemanticReplayTest`) rather than your local `product` suite.

### Where to read the current values

Read the pin, not this page:

- Abilities and cases: `php scripts/validate-e2e-manifest.php` prints
  `PASS E2E manifest structure: <abilities> abilities, <cases> cases, <negatives> negative cases`.
- Every other value is a literal in the files in the table below.

Values at the time of writing (2026-11-13, `origin/main` at 435d5d5):

| Symbol | Meaning | Value |
| --- | --- | --- |
| N | Native abilities (the manifest's ability count) | 94 |
| A | Annotation observations / individual tools: N plus the 5 `wstm108_record` custom post type abilities | 99 |
| L | Untrusted ordered labels: A plus 208 (104 gateway + 104 individual-tool boundary cases, frozen) | 307 |
| C | Manifest cases | 649 |
| negatives | Failure cases | 339 |
| classes | `includes/class-*.php` files with `public static function register()` | 22 |
| marked cases / assertions | `UntrustedManifestTest` | 202 / 215 |
| unit files | `product` / `qa-tooling` | 38 / 80 |
| base abilities | Native abilities excluding the generated CPT ones, as stated in `tests/e2e/README.md` | 84 |

### How each value moves

| Value | Per new ability | Per new case | Files that hold it |
| --- | --- | --- | --- |
| N | +1 | | `tests/e2e/untrusted-content-plan.php` (native count check and its message); `tests/e2e/destructive-safety-preflight.php`; `scripts/e2e-test.sh` (the native-inventory check and a comment); `tests/unit/InputBoundaryTest.php` (`Probe::$abilities`) |
| classes | +1 only if the ability is in a new `includes/class-*.php` with `register()` | | `tests/unit/InputBoundaryTest.php` (`Probe::$classes`) |
| A | +1 | | `tests/e2e/untrusted-content-plan.php` (`ANNOTATION_COUNT`); `tests/e2e/untrusted-content-fixture.php` (two); `tests/e2e/product-evidence-verifier.php` (two catalog checks); `tests/unit/UntrustedPlanTest.php`; `tests/unit/ProductSetupBoundaryTest.php` (four); `tests/unit/UntrustedSemanticReplayTest.php` (`assertCount` on the catalog and annotations) |
| L | +1 | | `tests/e2e/product-evidence-verifier.php` (label count and the three `passed` checks); `scripts/product-summary.php` (two); `scripts/test-release-runtime.sh` and `tests/unit/fixtures/release-runtime-product.sh` (`untrusted_cases`); `tests/unit/fixtures/product-evidence-model.php` (two); `tests/unit/ProductEvidenceVerifierTest.php` (two); `tests/unit/ProductSummaryTest.php` (three); `tests/unit/UntrustedPlanTest.php` (two); `tests/unit/UntrustedSemanticReplayTest.php` (many, plus the "changed count" mutation, which must be **L - 1**) |
| C | | +1 | `tests/e2e/product-evidence-verifier.php` (`maintained manifest case inventory`); `docs/03-04-docker-qa.md` ("The native manifest has N abilities and C cases") |
| marked cases / assertions | | +1 case and +1 assertion per marked success case (per marker path) | `tests/unit/UntrustedManifestTest.php` |
| unit files | | +1 per new test file | `docs/02-unit-tests.md` |
| base abilities | +1 | | `tests/e2e/README.md` ("Current coverage is X base registered abilities ... covers N abilities"; bump both numbers and append the ability to that sentence's list) |

The `tests/e2e/README.md` and `docs/03-04-docker-qa.md` prose also states N, A and
L in several places (the normal N-ability audit, "against all N manifest
abilities", "expect N native registrations", "executes L independently planned
ordered labels", "A annotation observations (N native", "requires all L ordered
cases", "restoration of the N native registrations", "N native plus five owned
CPT abilities"). Find them with the grep recipe below.

When a change adds several abilities, apply the per-ability moves once per
ability. The case pin moves by the number of cases added.

### Do not change

These contain a number equal to a pin but are not pins:

- `scripts/adapter-candidate-assessment.py` (`len(names) != 85`) and the matching
  text in `tests/e2e/README.md`: they describe a pinned historical snapshot.
- `tests/unit/fixtures/product-main-baseline.json`,
  `docs/reference/shared-helpers.md`, `docs/reference/mcp-adapter-0.7-assessment.md`,
  the history sections of `tests/e2e/README.md` ("Current coverage is **589
  cases**", checkpoint totals) and `docs/reference/release-history.md`.
- HTTP range checks in `tests/e2e/product-evidence-verifier.php`
  (`( $event['status'] ?? 0 ) < 300`) and `tests/unit/test_rescoped_workflow_gates.py`
  (`assertEqual(300, cases)`). A bulk `300 -> 301` replacement hits them.
- Unrelated numbers: mock exit statuses in shell tests (`return 94`, `exit 90`),
  HTTP status 302 or 307 in link tests, IDs such as `99` in cleanup fixtures,
  IP addresses, `day` and second counts.
- Per-ability annotation data: none is needed. The product evidence model builds
  its registrations from the manifest, and the runtime audits derive from it.

### Grep recipe

Substitute the current values from the table above and search before editing,
then repeat with the new values afterwards:

```bash
git grep -nE "\b(94|99|307|306|649)\b" -- tests scripts | grep -v "\.json:"
git grep -nE "\b(94|99|307|649)\b" -- docs tests/e2e/README.md README.md readme.txt
git grep -nE "\b(299|91)\b" tests/unit/UntrustedSemanticReplayTest.php   # replace with the current L and A
```

Include L - 1 in the pattern for the replay test's mutation line. Go through the
hits one at a time. The safest method is a per-file map of line number to
`(old, new)`, not a regular-expression replacement over a whole file. Two traps:

- In `tests/unit/UntrustedSemanticReplayTest.php` the line
  `$verdict['passed'] = <L - 1>;` is the "changed count" mutation and must stay
  one below the total. After a bulk replacement it is wrong by one; fix it by
  hand.
- `tests/e2e/product-evidence-verifier.php` contains the HTTP `< 300` checks
  described above; change only lines that mention the case inventory, `passed`
  or `labels`.

To find pins empirically, copy the tree (without `.git`, with `vendor` as a
symlink) to a scratch directory, make the change there, run each affected tooling
class, and compare with a clean copy.

## Source bindings

`tests/unit/fixtures/product-source-bindings.json` maps ten `includes/` files to
`[sha256, git_blob_sha1]` of the LF-normalized source: `class-input.php`,
`class-response.php`, `class-ability.php`, `class-taxonomy.php`,
`class-comments.php`, `class-posts.php`, `class-custom-post-types.php`,
`class-list-query.php`, `class-untrusted.php` and `class-post-scheduling.php`.
`tests/unit/fixtures/product-source-bindings.php` seals that JSON with its own
SHA-256, and `tests/unit/fixtures/scheduling-calibration.php`
(`bounded_source_bindings()`) repeats the bindings of `class-posts.php`,
`class-custom-post-types.php`, `class-list-query.php` and `class-untrusted.php`
as literals that must stay equal.

Every other `includes/*.php` file and the main plugin file are **not** bound:
adding or editing them, or adding a new unwired class file, breaks no binding.

When you edit a bound file, tests such as `TaxonomyCalibrationTest`,
`CommentsCalibrationTest`, `UntrustedBoundedCompositionTest` and
`SchedulingCalibrationTest` fail until the bindings are regenerated. Run:

```bash
php scripts/regen-product-bindings.php
```

It recomputes the ten bindings, rewrites the JSON, reseals the PHP loader,
syncs the four literals in `scheduling-calibration.php`, prints one
`updated <path>` line per binding that changed, and exits non-zero if the seal
line or a literal cannot be found. On a tree whose bindings are current it
changes nothing. Then run
`--filter '/^(TaxonomyCalibrationTest|CommentsCalibrationTest|UntrustedBoundedCompositionTest|SchedulingCalibrationTest)::/'`
against `--testsuite qa-tooling`.

Review why the bound source changed before regenerating; do not use the script
to silence a failure. Caveats:

- `class-ability.php` and `class-comments.php`: `CommentsCalibrationTest` also
  reverses exact historical diffs out of the current source
  (`assert_ability_provenance_bridge`, `assert_comments_provenance_bridge`). A
  change near the anchored lines can require updating those bridges, not just
  the binding.
- `class-post-scheduling.php`, `class-input.php`, `class-response.php`,
  `class-ability.php` and `tests/e2e/error-contract-assertions.php` are also
  listed under `production` in `tests/unit/fixtures/scheduling-calibration-ledger.json`.
  For entries not in `bounded_source_bindings()` (`class-post-scheduling.php`,
  `error-contract-assertions.php`), `assert_sources()` requires the baseline and
  current SHA-256 to equal the source's and a matching `git_blob`, and the ledger
  is sealed by `LEDGER_SHA256` in `scheduling-calibration.php`. Editing those
  needs the scheduling ledger updated and resealed, which the script does not do;
  run `SchedulingCalibrationTest` and read its failure.
- Never bypass a seal; regenerate it.

## Untrusted-marker records

When success responses carry `untrusted_fields` markers, the manifest must prove
them and the tooling must know the expected markers:

1. Add a `case '<slug>':` to `Wstm108_Manifest_Projection::required_markers()` in
   `tests/unit/fixtures/untrusted-manifest-projection.php`. For a new ability no
   `$historical` guard is needed because the sealed history has no cases for it.
2. Put the exact `"<record path>.untrusted_fields": [...]` in `assert_values` of
   every marked success case. `UntrustedManifestTest` enforces this against the
   live manifest.
3. In `tests/unit/UntrustedManifestTest.php`, add +1 `cases` and +1 `assertions`
   to the expected totals per marked case (per marker path), and add
   `array( '<slug>', false ), array( '<slug>', true )` to `mutations()` so the
   remove and weaken controls cover the new ability.

Details:

- `required_markers( $case, $historical )` also runs against the historical
  570-case inventory with `$historical = true`. When you add markers to an
  *existing* ability that had none, return them only when `! $historical`.
- A list case whose `assert_values` has `"data.items": []` needs no markers for
  slugs that start with `list-`. Other slugs do not get that shortcut.
- Make `required_markers()` input-dependent where the input changes the field
  set: `previous` values on write abilities
  (`array_intersect( TEXT_FIELDS, array_keys( $case['input'] ) )`), a window
  smaller than the fixture (`max_links: 1` marks only `data.links.0`), or a
  result that may legitimately be empty on the QA site
  (`case 'x': return ! empty( $case['input']['overdue_only'] ) ? array() : array( ... );`).
- Assert nested paths individually (`data.previous.title`,
  `data.previous.untrusted_fields`) rather than one object value.
- If you cannot control the data, add no record at all (see
  [E2E manifest](#data-you-cannot-control)).
- Changing what `assert_bucket_counts` checks means editing
  `tests/e2e/qa-assertions.php` (`webmastery_mcp_qa_bucket_counts`); keep
  `tests/unit/test_qa_execution.py` green.

## Docs and changelog

Update these for a user-facing ability. Search for the old counts (see the grep
recipe) to find every mention.

| File | What to change |
| --- | --- |
| `docs/reference/ability-contracts.md` | Contents entry, a section (permission, inputs, result fields, what is never returned), a row in the "Untrusted result fields" table, and the Administrator-only bullet in "Security and privacy behavior" when it applies |
| `tests/e2e/README.md` | A feature section before "Rule for new abilities" (cases and manual checks) and the count mentions listed in [Count cascade](#count-cascade) |
| `docs/03-04-docker-qa.md` | The manifest, label, annotation and native-registration counts |
| `docs/02-unit-tests.md` | The `product` and `qa-tooling` file counts |
| `README.md` | Only when the quickstart, requirements, install/connect steps or ability areas change: the area table |
| `readme.txt` | The WordPress.org description and Highlights where abilities are listed; a new `== External services ==` section if the ability calls an external service |
| `webmastery-site-toolkit-for-mcp.php` | The header `Description:` area list when the ability adds an area |
| `CHANGELOG.md` | `## Unreleased` > `### Added`, in plugin-facing wording without raw internal ability namespace strings |
| `.github/REPOSITORY_CHANGELOG.md` | `## Unreleased`, for tooling, count and manifest-case changes |
| `docs/reference/3.0-migration.md` | Only when clients must change how they call existing abilities |

Leave historical sections (the history parts of `tests/e2e/README.md`,
`docs/reference/*assessment*.md`, `docs/reference/shared-helpers.md`,
`docs/reference/release-history.md`) alone. Keep the `70+` and `16 areas` style
marketing figures as they are unless the claim becomes false.

## Verification commands

Run before opening the PR (the `composer` aliases are equivalent where one
exists):

```bash
php vendor/bin/phpcs --standard=phpcs.xml.dist                      # composer phpcs
php vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=2G --no-progress
php scripts/validate-e2e-manifest.php && php scripts/validate-security-qa.php
php tests/unit/fixtures/manifest-case-ledger.php check
php scripts/test-phpstan-baseline.php
php scripts/php-lint.php > /dev/null; echo $?                       # quiet: prints hundreds of lines
php scripts/test-release-safeguards.php
python3 -B scripts/qa-profile.py inventory
git diff --check
php vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite product
```

Then run the affected tooling test classes individually (see
[Unit tests](#unit-tests) for the filter form). Grep `tests/` and `scripts/` for
every file you changed to find them. After a manifest change, at least:

```bash
php vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite qa-tooling --filter '/^(ManifestCaseLedgerTest|QaAssertionTransitionTest|BoundedListManifestTest|BoundedManifestProjectionTest|BoundedMarkerManifestTest|DestructiveBooleanLedgerTest|SchemaIntegrationLedgerTest|UntrustedBoundedCompositionTest|UntrustedSchemaIntegrationTest|UntrustedHygieneManifestTest|UntrustedManifestProjectionTest|UntrustedCompactDeleteTest|UntrustedManifestTest|CoverageManifestTest|MediaManifestTest|MetadataMigrationTest|BoundedIntegrationTest|DestructiveNativeBoundaryTest|DestructiveProofTest|UntrustedPlanTest|ProductEvidenceVerifierTest|ProductSetupBoundaryTest|WebmasterVerificationPolicyTest|ProductSummaryTest)::/'
php vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite product --filter '/^(InputBoundaryTest|DatabaseTablePrivacyTest)::/'
for t in test_qa_execution test_catalog_ordering test_rescoped_workflow_gates test_qa_profile; do python3 -B -m unittest discover -s tests/unit -p "$t.py"; done
```

Add `PermissionsLoadingTest`/`SharedHelpersTest` when you touched shared helpers,
`ProductSummaryTest` and `ProductRuntimeContextTest` when you touched the counts,
and the calibration classes from [Source bindings](#source-bindings).

Slow checks:

- `UntrustedSemanticReplayTest` takes about 30 minutes locally and runs in CI. It
  holds many of the count pins, so grep it after a cascade
  (`git grep -nE "\b(<L>|<A>)\b" tests/unit/UntrustedSemanticReplayTest.php`) and run it in the background
  with a long timeout when you can:
  `php vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite qa-tooling --filter '/^UntrustedSemanticReplayTest::/'`.
- `bash scripts/test-release-runtime.sh` (part of `composer test:release-safeguards`)
  takes a few minutes; its `ERROR` lines are its own negative controls, and it
  ends with `PASS retained main outer orchestration`.
- The full `qa-tooling` suite (`composer qa:tooling`) takes several minutes.
- Docker E2E (`composer qa:contract`, `composer qa:e2e`) needs a Docker engine.
  It is the only thing that executes the manifest against WordPress, so a case
  that passes `validate-e2e-manifest.php` is still unproven until it runs.

## Known environment-dependent failures

Two tooling tests depend on the machine that runs them. They fail on a clean
tree in some environments, so compare against a clean run before blaming your
change.

- `ProductRuntimeContextTest::testContextMatchesTheActuallyRunningInterpreterLane`
  builds a product context whose `php_floor` is the running interpreter's
  `major.minor` and validates it. `Wstm167_Product_Context::validate()`
  (`tests/e2e/product-runtime-context.php`) accepts only the lanes `8.0`, `8.1`,
  `8.2` and `8.4`, and refuses anything else with `context binding`. It therefore
  fails when the local PHP is another minor version, such as 8.3 or 8.5, and
  passes on an 8.0, 8.1, 8.2 or 8.4 CLI.
- `UntrustedUploadPrerequisiteTest::test_refusal_precedes_child_creation_and_journal_writes`
  with the data sets `unreadable-parent` and `unreadable-base` makes a directory
  unreadable with `chmod( ..., 0000 )` and expects the prerequisite check to
  refuse. A process running as root (a container default) can still read the
  directory, so both data sets fail as root and pass as an ordinary user.

CI runs the tooling suite on a supported interpreter as a normal user, where
both pass.
