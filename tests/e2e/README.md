# Ability Contract and Full MCP E2E QA

For the full repository QA posture, including static checks, unit tests, release checks, and GitHub Actions trigger policy, see [`docs/qa-strategy.md`](../../docs/qa-strategy.md).

The Docker QA suite has two layers:

1. Ability Contract QA is ability-driven. Every registered `webmastery-site-toolkit-for-mcp/*` ability must be represented in `tests/e2e/abilities-manifest.json`.
2. Full MCP E2E QA uses real MCP Adapter HTTP JSON-RPC requests against `/wp-json/mcp/mcp-adapter-default-server` to prove a remote MCP client can create, read, update, and delete content through the adapter transport.

## Unreleased canonical error coverage

All manifest failures require `expect_error_shape:"canonical"`, a seven-category
`expect_error_code`, and an exact `expect_error_reason`. The independent
`error-contract-assertions.php` rejects missing keys, scalar errors, non-object
details, unknown reason mappings, and false wire success. Existing state,
permission, hash, scheduled CRUD, and bulk-item assertions are retained.
Canonical core schema/permission messages are intentionally fixed and do not
echo input values. See the [3.0 migration guide](../../docs/3.0-migration.md).

Historical baseline comparisons require the pre-migration runner from 2.6.0;
the current strict canonical parser intentionally rejects those old envelopes.
Do not weaken current assertions to make a historical baseline pass.

`error-contract-runner.php` requires `WSTM118_DISPOSABLE=1` before loading
WordPress or creating credentials/fixtures. The managed harness grants it only
inside the disposable stack. A test-only MU fixture, installed after the normal
85-ability audit, exposes individual tools at `/wp-json/wstm118/tools`.
The proof discovers tools by unique advertised descriptions, retains the full
catalog and schemas, and uses the returned names (including deliberately renamed
owned/foreign probes), not guessed namespace spelling.

`error-contract.json` retains native, registered, gateway, and individual
responses; all seven categories; schema/callback and early permission failures;
known-code provider redaction; exact core hook/callback counts; foreign and
nested-success controls; mixed/all-failed bulk outcomes and stored state.
Adapter version and installed handler hash/order accompany the evidence.
Adapter 0.6.1 uses internal null structured content but **omits** that key on
the actual error wire. The parser separately accepts omitted and explicit-null
forms and rejects nonnull structured payloads; evidence records actual key
presence. Cleanup verifies owned application-password absence after revocation
and removes only owned posts. Contract, HTTP, and package workflows retain the
report for seven days, including failed runs.

The floor and pinned-core runs record actual invalid-callback registration
behavior: WordPress 6.9 rejects it with a registry diagnostic, while 7.1 accepts
registration and rejects invocation. Two synthetic probes therefore inject
non-callable callbacks via test-only reflection after successful registration;
they exercise real core execution guards on both versions, not nonexistent
tool errors. Registry rejection remains a separate exact assertion and only
those controlled diagnostic messages are suppressed from the debug log.
Execution and permission exceptions are both covered, including native 403
authorization denials and default 502 callback failures, no execution after
permission failure, and unchanged action counts.

## Destructive-operation safety coverage (3.0)

The five guarded abilities retain their original success and permission oracles,
now with `confirm:true`. Added manifest cases reject missing/false/string/number/
null confirmation, non-boolean optional flags, and 101 raw IDs (including
duplicates); 100 duplicates, mixed/all-failed previews, and known featured-image
refusal include persisted-state assertions. Unit tests separately pin callback
reasons, exact schema bounds, canonical per-ID failures, query failure with force,
and unchanged capability/trash behavior. Existing error-contract, taxonomy, and
trash runners retain all earlier assertions.

`destructive-safety-boolean-ledger.json` records the reviewed correction from
head `6a47b52` (tested synthetic merge `22e30ea`): 547 manifest cases remain
unchanged and 16 change only expected error code/reason, with all 563 typed
inputs, ordering and other assertions preserved. WordPress 6.9, 6.9.4 and
7.1.1 accept boolean-like values during schema validation; enum checks sanitize
only a local comparison value, not the input passed to the callback. Therefore
confirmation `"true"`/`1` fails the strict callback with
`precondition_failed/missing_confirmation`; optional `"true"`/`"false"`/`0`/`1`
fails there with `invalid_input/invalid_input`. Missing/false/null confirmation,
null/array optional flags and 101 IDs still fail registered schema validation.
Direct-callback expectations and independent permission-denial checks do not
change. Recalibrate explicitly if a future input wrapper changes that ordering;
do not broadly accept multiple reasons or coerce test inputs to make them pass.
Preservation fingerprints decode JSON as objects and encode with
`JSON_PRESERVE_ZERO_FRACTION`, retaining property order and sparse case indexes.
Their goldens come from exact `6a47b52`, not the corrected working manifest.
Mutation controls distinguish both `{}` from `[]` and integers from floats.
The accepted `db041ce` privacy integration adds exactly seven cases, for 570
total: 547 original unchanged cases, 16 approved error corrections, and seven
inherited privacy cases. Each imported row has an object/type-preserving hash
derived from exact `db041ce`; tests require its exact insertion position/order
before removing only those seven rows to verify the original 563-case goldens.
Unknown extra cases, altered privacy rows, and reordered baseline rows fail.

`run_destructive_safety_qa` in the shared harness runs serially in a subshell:
`contract` selects direct callbacks and registered abilities, `e2e` selects
gateway and individual HTTP, and `all` runs all four. Each selection repeats
with actual `EMPTY_TRASH_DAYS` values 30 and 0: eight invocations in full QA.
Use only an explicitly owned disposable `COMPOSE_PROJECT_NAME`, never a live
site or another worker's project. Unit and mocked stages do not constitute
real WordPress, HTTP, floor, or original-package evidence.
Both runtime entrypoints reject an absent or invalid project name before any
Compose startup or cleanup. Package CI and pre-publication QA provide distinct
job/run/attempt-scoped names. Local callers must explicitly export their own
unique disposable name; neither the runner nor `qa-compose.sh` invents a fallback.
The local-only `SKIP_PLUGIN_CHECK=1` offline package check still needs no project
name and performs no runtime work; CI cannot use that bypass.

Before test-only MU capabilities are installed, `destructive-safety-preflight.php`
audits actual `wp_get_abilities()` against all 85 manifest abilities and records
missing-opt-in CLI failure plus HTTP 403/`CLI only.` refusal. Whole core-table
snapshots (including users, credentials, options and cron) and upload hashes
must remain unchanged across those bootstrap denials.

`destructive-safety-lifecycle.php` acquires an exclusive token-owned lock outside
the document root, keeps a private exact config backup, refuses existing MU
destinations, and configures both actual CLI and HTTP boots. It supports absent
or conventional literal trash definitions and refuses ambiguous configuration.
The runner requires `WSTM116_DISPOSABLE=1`, the runtime opt-in constant, matching
stage token and expected trash days, and an authenticated-by-token read-only
HTTP boot attestation **before creating actors or credentials**. Every observed
HTTP mutation probe also attests the same configuration. An environment flag
alone is never evidence of the server's trash behavior.
After the native audit, an independently token-owned, GET-only read-only probe
captures the original runtime. Each owned config transition allows at most five
GETs, two seconds per request, four one-second intervals and a 14-second overall
budget. Every attempt is journaled. Only a recognized previous owned runtime
with the correct owner, roots and current disk digest may retry; malformed,
foreign, denied, wrong-digest or unexpected opt-in results fail immediately.
This cache-readiness barrier never retries abilities, actors or mutations and
never resets or disables global OPcache.

Each invocation exclusively creates one token-owned subdirectory beneath the
resolved existing upload root. Only that new directory may change ownership,
to the integer UID from the exact HTTP attestation; existing site/month/upload
directories keep their ownership and modes. The HTTP probe must confirm it is
writable before actors are created. The `upload_dir` filter exists only during
seeding and is removed in `finally` before ability calls. Attachment paths,
URLs/GUIDs, reference checks and post/file absence assertions remain real.
Cleanup checks ownership, deletes only tracked unchanged files, and uses
nonrecursive `rmdir`; foreign entries, symlinks or ownership changes fail closed.
Attachment references are validated before any attachment cleanup. A reference
refusal, deletion veto or retained post prevents independent upload-file deletion
and retirement of the upload directory/marker. Safe unrelated cleanup continues;
an actor still owning a retained post is not implicitly deleted.

An exclusive `destructive-<token>` artifact directory contains `stage.log`,
`preflight.json`, and uniquely named `<enabled|disabled>-<boundary>.json` reports
with append-only `.jsonl` journals. The runner exclusively reserves both files
before WordPress bootstrap, records each successful/failed case, and retains a
partial summary on fatal shutdown. Do not overwrite or relabel failed attempts.
Boot failures retain CLI identity, HTTP status, public REST error and response
digest before credentials; successful HTTP attestation additionally records
server SAPI/UID and config digest. Deletion
diagnostics retain actual HTTP unlink-path ownership/writability and fresh CLI
file existence/content evidence. No persisted-state or file-deletion assertion
is waived. On EXIT, the stage restores exact prior config bytes/mode/UID/GID and
removes the owned mutation loaders, then requires HTTP to converge to the
captured original runtime before removing the read-only probe and retiring the
private backup/lock. It preserves the parent's cleanup trap. Restoration failure
is independently nonzero and retains the private backup and necessary owned
read-only evidence; an earlier failure remains the exit status. The fixes target
the proven `4edeb17` stale-config and root-owned-upload failures; the historical
`6a47b52` first-enabled boot failure remains undiagnosed.

Before acquiring or mutating runtime configuration, the stage exclusively arms
`build/wstm116-retention-<compose-project>` on the host checkout. It contains only
the owner token, project name and source SHA, never configuration or credentials.
Retirement requires verified restoration plus an explicit, strictly boolean,
source/boundary/trash-bound `cleanup_complete` report from every invoked runner.
An ordinary case failure with proven successful cleanup still tears down normally;
missing/incomplete cleanup proof retains the runtime and its upload evidence.
Both outer wrappers and source/floor workflow cleanup honor this guard and skip
`down -v` while it exists. Any retained, foreign, invalid or symlink guard blocks
restart and artifact/package replacement, even under another project name.

A retention failure requires manual recovery of that exact owned runtime; do not
delete its guard or run Compose teardown merely to unblock another run. Preserve
private backup/probe and attachment ownership evidence, resolve the recorded
failure, and verify original configuration and owned cleanup before retirement.
Private configuration stays outside ordinary artifacts inside the retained
runtime. This protects against harness teardown, not disposal of an ephemeral
hosted runner; public reports must never be represented as a private-config backup.

The temporary individual fixture exposes the real `/wp-json/wstm118/tools`
catalog. The runner reuses `metadata-transport.php` for actual advertised names,
retains actor catalogs, and parses Adapter 0.6.1 errors as `isError:true` with
one canonical JSON text block and absent/null `structuredContent`, not a
structured error object.
For successful bulk summaries, the runner also retains the original HTTP body
and checks `details` object types before associative decoding can turn `{}` into
`[]`. Both structured and text payloads must agree when both are present; an
actual array remains a failure. State and hook evidence is captured before this
wire-shape assertion, so a parser failure cannot hide the operation's effects.

The same stage uses the mounted plugin root in source, floor, and original
development-ZIP QA. Tests are harness-only mounts for package QA; production
does not fall back to the checkout and ZIP bytes remain immutable. Contract,
HTTP and package workflows upload the complete stage directory even on failure;
compatibility lanes already retain the complete artifact tree. Archive reports
before starting a new suite, whose existing top-level wrapper clears artifacts.

The runner distinguishes callback permission outcomes from execution denial;
compares preview summaries to real writes; snapshots posts, postmeta, terms,
termmeta, taxonomy relationships, cron, and owned upload bytes; and records
mutation hooks. It probes featured-image, literal URL, GUID, unused media,
forced known-reference deletion, forced scan failures, and final object denials.
HTTP fault injection and observers are authenticated-actor/request scoped and
return a unique nonce attestation through the owned control option.
Cleanup verifies absence of owned posts/meta/scheduled events, terms/meta,
uploads, users/application passwords, and the control option, retaining failure
evidence even when cleanup fails. The stage, not a manual config edit, manages
the MU loaders and runtime opt-in. Actual eight-invocation/floor/package
acceptance must still be established on the exact source under review.

## Metadata batch and SEO authorization coverage (3.0)

`metadata-batch-fixture.php` independently lists 34 removed aliases and 147
presence variants (values, null, empty arrays/strings, containers, raw keys,
unknown reserved prefixes). `metadata-batch-runner.php` exercises all eight
built-in/fixture-CPT create/update entrypoints. It requires explicit
`WSTM110_BATCH_DISPOSABLE=1` in an owned disposable runtime and selects
`direct`, `ability`, `http` (gateway), or `individual` execution with
`WSTM110_BATCH_BOUNDARY`. Never enable it on a
shared or live site. Whole posts/postmeta/terms/taxonomy/relationship/cron
snapshots and pre-write/save/status/metadata/term hook counters detect even
insert-then-delete fake rollbacks. Artifacts retain every input and observation.
Each boundary expects 1,176 rejections plus four positive draft/plain-update/
individual-metadata/publish workflows. HTTP observation requires a request-scoped
token and an explicit response header from `metadata-batch-http-fixture.php`;
a missing header is a failure, not evidence of zero hooks.

`seo-metadata-runner.php` uses the same opt-in and boundaries with the temporary
`seo-metadata-fixture.php` forbidden-read observer and existing standalone
authorization fixture. With both real SEO providers active, it expects 252 cases:
40 keys on posts/pages under map denial, final-user-cap denial, and explicit
primitive-grant controls; eight analysis cases; two score-filter cases; bounded
overview observations; and URL-only head refusal. Individual HTTP requires the
temporary `wstm118-individual` server after the normal registration audit.
These runners retain raw responses and observer evidence, revoke their own
application passwords, and remove owned objects/options. Unit transport seams
prove strict error handling, discovery ambiguity, required observer evidence,
and session-cleanup failures; they do not establish actual HTTP/provider results.

The manifest replaces ten obsolete combined successes with original-payload
no-op rejections, plain creation/update, and 54 exact-key standalone writes.
Every original normalization/backslash and unrelated-key assertion is retained.
`capture_post_id` captures only a successful created object for subsequent
calls. `assert_metadata_boundary` adds full state and zero-hook proof to
combined-input denials. Contributor publication and parent-assignment checks
keep their original permission purpose using plain payloads; scheduling keeps
metadata sentinels instead of injecting aliases into every request.

SEO unit probes reject reads of each of 40 denied keys before they occur,
check authorized score pagination and total counts, prohibit opaque head
requests, and enforce the overview's single 100-ID query / at most 400 reads.
Unit observations are not substitutes for actual WordPress/provider/HTTP QA.
The [migration guide](../../docs/3.0-migration.md#metadata-and-seo-authorization)
documents all changed user-facing contracts.

## Strict input-schema coverage and acceptance ledger (3.0)

### Native validation phase and frozen safety integration

The schema candidate normally integrates frozen safety source
`f93b7d11bec24620f5dd51202db3e6cb1df020d9` (tree
`c402e4a33a166c02d45f3ca831dfa11504ea55c2`). Its source acceptance is not
runtime acceptance of this integrated candidate. Shared startup, lifecycle,
retention, cleanup, and workflow orchestration are inherited unchanged.

The public owned `validate_input($input = null)` override delegates to core
once, returns core errors unchanged, preserves empty-schema behavior, and
then runs the existing strict helper before permissions. WordPress 6.9,
6.9.4 and 7.1.1 expose this public signature and ordering. Coordinator's
unchanged-core 18-row probes on each version established the earlier masking:
core accepts ten boolean-like invalid inputs, then the raw wrapper rejects
them, and core execution masks that rejection as a permission failure.
Those probes are the historical red evidence, not a new runtime pass.
Official core hashes remain in `destructive-safety-boolean-ledger.json`.

`NativeInputValidationTest` covers the new phase using an explicit lifecycle
double, not a substitute for core or HTTP acceptance. Parent error identity,
ordering, default normalization, validation filters, formats/combinators,
empty schemas, success/denial/output/exception behavior and a strict-check
mutation are separately tested. The source proof runner now hashes
`class-ability.php` as well as the input helper. Its numeric-string ID input
is identical, but native expected reason changes from
`ability_invalid_permissions` to `ability_invalid_input`, and observed
permission-wrapper calls change from one to zero. The added destructive
typed matrix rejects before resolving target IDs. Direct preflight and raw
metadata presence precedence are unchanged. Zero-work assertions concern
original callbacks/capability/query/mutation boundaries, **not all core
hooks**; normal core invocation, normalization, validation filters and
pre-execution short circuits retain their semantics.

### Standalone permission defaults

Adapter 0.6.1 converts an empty object to null for a declared empty root default,
then invokes target permission checks without native execution's normalization.
The owned permission boundary now materializes a declared default only when
input is null and the schema's type is exactly `object`, before calling the
parent once. It does not call `normalize_input()` or `validate_input()`, broaden
scalar/nullable/untyped schemas, or change raw callback validation.

The immutable `436191f` source reproduced eight default-preflight failures and
14 unchanged controls on each of official WordPress 6.9, 6.9.4 and 7.1.1 with
actual Adapter 0.6.1 normalizer/gateway/individual preflight methods. Distinct
candidate snapshots pass the same 22-case contract on all three versions.
These are PHP 8.4 isolated-library observations, not PHP-floor, bootstrapped
WordPress, database or HTTP acceptance: inert platform functions provide the
outer environment, real database registration/permission code runs, and a
counter replaces database execution. Exact upstream Git blobs, file hashes,
baseline/candidate hashes and raw RED/GREEN output are retained with that proof.

`PermissionDefaultTest` separately uses the explicitly labeled lifecycle double
and real plugin registration/wrappers/permission/database callbacks against a
controlled database. It covers unchanged redaction and raw-name opt-in, genuine
denials, malformed flags/defaults, required fields, raw null rejection, foreign
namespaces, metadata precedence, no normalization replay, native errors,
filter order, reentrancy and actual-source mutations. The double accepts the
schema `description` annotation but still throws for unmodeled constraints.
Neither evidence layer changes privacy-runner oracles or the 78 canonical-error
cases, and neither claims new real runtime acceptance.

### Preserved typed manifest

The independently derived `input-schema-integration-ledger.json` pins all
**589 ordered typed cases**, including full before/after content for exactly
52 changed parent cases and the 19 previously reviewed additions. Its source
is frozen `1bf2eb2` plus the exact seven privacy rows from `f93b7d1`, not a
regeneration of expectations from the working manifest. It reconstructs all
570 parent cases exactly before applying the unchanged historical
563-case/547-unchanged and seven-privacy-row goldens. Empty objects, integers
versus floats, case/property order, roles, unchanged-state evidence and
metadata sentinels are preserved. Unexpected extras, omissions, reordering,
type drift, altered reasons, changed no-write fields or modified additions
fail projection controls.

Current coverage is **589 cases / 85 abilities / 307 negatives**. The earlier
34 migrations include four already-approved nonhierarchical-parent zero
success-to-failure corrections and their replacement state oracles; they are
not represented as new reason-only edits. Only the 18 newly approved safety
and privacy cases are limited to the indicated native error fields.

| Calibration relative to frozen f93 | Cases | Direct result retained | Native/raw-permission/gateway/individual strict result |
| --- | ---: | --- | --- |
| Confirmation string `"true"` / integer `1` across five destructive abilities | 10 | `precondition_failed` / `missing_confirmation` | `invalid_input` / `ability_invalid_input` |
| Optional dry-run/force string `"true"` / integer `1` | 6 | `invalid_input` / `invalid_input` | `invalid_input` / `ability_invalid_input` |
| Database table-name flag string `"true"` / integer `1` | 2 | Direct method `invalid_input` / `invalid_input` | `invalid_input` / `ability_invalid_input` |

The privacy null row is unchanged. The original privacy hash/index goldens
are unchanged; the ledger explicitly reverses only the two authorized reason
changes before checking those hashes. The prior 34 native migrations, four
overlapping raw-permission migrations, six combined-metadata corrections and
three plain authorization controls from `1bf2eb2` remain intact. No new
manifest cases are added beyond its 19 and the parent's seven privacy cases.
All 45 original safety inputs persist; only the exact 16 native error fields
above change relative to f93.

The safety runtime still has 124 invocations per boundary per trash boot.
Exactly **86 independent raw permission observations** now require native
`WP_Error`, code `invalid_input`, reason `ability_invalid_input`: 60 invalid
confirmations (50 actor checks plus ten previews), 18 optional flags, and
eight 101-ID calls. Previously these raw permissions returned true. Direct
operation diagnostics are unchanged; native/gateway/individual result reasons
change for 24 string/number confirmation calls and 12 string/number flag calls.
The remaining 50 strict-invalid result oracles are unchanged. Valid-input actor
denials remain distinct native `forbidden` permission errors. Snapshots,
mutation hooks, cron, references, files, deletion truthfulness and ownership
checks are not relaxed. Full old/new boundary mappings are in the JSON ledger.

No local Docker, WordPress boot, HTTP, floor or package execution is claimed
for this integration. All 78 canonical error cases, actual core lifecycle,
five schema boundaries, metadata/parent/safety suites and original-package
identity/cleanup still require the coordinator's later runtime acceptance.

`InputBoundaryTest` registers the production abilities in an isolated namespace,
compares all 85 registered names against the manifest, and tests raw permission
and execute callbacks with malformed types/enums/nulls/unknown properties.
Capability/query/write sentinels require rejection before operation work.
Controls preserve omitted defaults, valid/denied authorization, arbitrary
metadata maps, dynamic taxonomy maps, and hierarchical parent semantics.
`InputProofTest` disables the actual type, enum, and closed-key checks in three
source mutants and requires them to reach the sentinel callback. State,
mutation-hook, query/capability, and missing-evidence negative controls validate
the no-work oracle separately.

The corrected schema manifest retains all 563 preexisting labels and inputs.
Checkpoint `1c460f1` did not fully preserve input identity: associative JSON
decoding hid two unintended empty-object-to-array changes. The correction below
restores the original objects from approved parent `ad26b21`; it changes no
expected error, label, role, or other assertion.

| Original case label | Field | Parent `ad26b21` | Source `1c460f1` | Corrected |
| --- | --- | --- | --- | --- |
| `wstm110 update-post rejects empty metadata presence` | `input.meta` | `{}` | `[]` | `{}` |
| `wstm110 create-cpt-mcp-case-study rejects metadata input` | `input.meta_input` | `{}` | `[]` | `{}` |

Both cases retain `invalid_input` / `ability_invalid_input`. The focused
`MetadataMigrationTest` regression uses non-associative JSON decoding, asserts
empty `stdClass` values and the complete original inputs, and distinguishes
an empty-array mutation. It failed on both source inputs before correction.
The earlier unqualified input-preservation claim and associative-decoded
migration artifact did not detect these container changes; retain that failed
evidence rather than presenting the source checkpoint as already corrected.
The authorized schema expectation changes and additions remain:

| Acceptance change | Cases | Exact new expectation |
| --- | ---: | --- |
| Combined metadata cases | 23 | Native `invalid_input` / `ability_invalid_input`; all existing state/metadata assertions retained |
| Nonhierarchical CPT parent presence | 8 | Native `invalid_input` / `ability_invalid_input`, including zero and Administrator cases; no persisted change |
| New enum/type/unknown-key and denied-role controls | 16 | Exact canonical errors, never sanitizer-coerced success |
| Total at this checkpoint | 579 | 85 abilities; 300 negatives; every registered ability represented |

The frozen-parent integration subsequently audited all raw permission assertions
and all 26 combined-metadata inputs. Six cases had stale layer-specific
expectations. These corrections are additional to the historical ledger above;
they do not change any original input or remove its state/metadata assertions.
`assert_permission` compares `WP_Error::get_error_code()`, **not** the envelope
reason: the raw code is `invalid_input`, the raw envelope reason is
`metadata_requires_separate_call`, and native schema rejection is
`invalid_input` / `ability_invalid_input`.

| Original label | Raw permission correction | Additional native correction |
| --- | --- | --- |
| `wstm120 contributor cannot transition draft to publish rejects original combined payload` | `true` to `invalid_input` | None; original 31 already include this |
| `wstm120 contributor cannot transition draft to private rejects original combined payload` | `true` to `invalid_input` | None; original 31 already include this |
| `wstm120 contributor cannot transition draft to future rejects original combined payload` | `true` to `invalid_input` | None; original 31 already include this |
| `wstm120 contributor cannot update unrelated draft` | `forbidden` to `invalid_input` | `forbidden` / `ability_invalid_permissions` to `invalid_input` / `ability_invalid_input` |
| `wstm122 denied post write preserves metadata` | No existing raw assertion | Same native correction |
| `wstm122 denied page write preserves metadata` | No existing raw assertion | Same native correction |

The original three plain Contributor transition cases retain raw `true`,
the exact publication-denial message, `forbidden`, and unchanged-state checks.
Three additional plain-input authorization controls retain raw `forbidden`,
native `forbidden` / `ability_invalid_permissions`, unchanged-state evidence,
and actual `edit_post` denial facts: `wstm120 contributor cannot update
unrelated draft with plain input`, `wstm122 denied post write with plain input
preserves metadata`, and `wstm122 denied page write with plain input preserves
metadata`. The unrelated-draft pair also retains `edit_posts:true`; the two
subscriber pairs preserve their original exact backslash/JSON metadata
sentinels. Combined cases retain the original payload, including `Denied\meta`,
and add full metadata-boundary/no-write evidence rather than claiming to reach
object authorization.

At the historical `1bf2eb2` freeze, totals were **582 cases / 85 abilities / 303 negatives**: original
563 inputs preserved, original 31 native migrations plus three additional
native migrations, four overlapping raw-permission migrations, and original
16 additions plus three plain controls. Isolated production-wrapper probes
require zero original permission-callback invocations and zero capability,
read, query or write events for combined inputs, and exercise the original
object-permission callback after removing metadata. The validator admits only
`true`, `forbidden`, or canonical `invalid_input`; mutations reject a reason
used as a code, an unknown code, and an array value. These are local proofs,
not completed native WordPress/runtime acceptance.

Historical labels mentioning allowed zero-parent behavior intentionally remain
unchanged for provenance; their assertions now reject that closed property.
The existing 151-case parent matrix is retained. Metadata runtime coverage
retains 1,176 rejects and four positive workflows per boundary; native reason
is now exactly `ability_invalid_input`, while direct/gateway/individual
presence rejection stays exactly `metadata_requires_separate_call`.
The manifest validator and its mutation test enforce that distinction.

`input-schema-runner.php` and `input-schema-fixture.php` have an isolated,
opt-in stage, but **actual integrated runtime execution remains pending**;
stage mocks are not completed WordPress or wire acceptance.
Do not start Docker or local WordPress without the coordinator's explicit
exclusive runtime lease. The accepted safety lifecycle and error-contract runner
are untouched; schema orchestration is a separate serial call after metadata
QA and before the final debug-log check. Final integration must preserve the newer error
floor, metadata authorization, destructive guards, and performance projections.
The synthetic missing-schema error fixture clears its schema after registration
and restores only that probe's original permission callback: clearing the
property alone leaves the raw wrapper's captured empty schema active.
An isolated reflection/registration regression verifies both changes, the
schema-only negative control, untouched execute wrapping, and continued
rejection by ordinary input-free abilities. The error runner's 78 oracles are
unchanged; their integrated real-runtime rerun remains pending.

The stage exclusively owns a temporary MU **loader requiring the original**
`input-schema-fixture.php` and individual-tool fixture; copying the schema
fixture into another directory would break its relative includes. The loader
defines the schema opt-in for CLI and HTTP. **No `wp-config.php` writes** are
needed or permitted. Configuration bytes, permissions and ownership must remain
original, and an authenticated GET-only probe attests original, active and
restored HTTP/CLI state. The normal harness supplies the CPT/Site Kit fixtures.

Runner identity is mandatory before bootstrap: exact `WSTM126_DISPOSABLE=1`,
the selected `WSTM126_BOUNDARY`, stage token, project, exact source SHA, and a
new boundary artifact path. The stage proves CLI opt-in refusal and HTTP 403
before installing its opt-in or creating actors/credentials. Do not manually
invoke the runner outside its stage and then claim cleanup/retention acceptance.
Contract QA selects `direct`, `permission`, `ability`; transport QA selects
`http`, `individual`; `all` runs all five serially with no additional trash
boots. Every invocation has exactly **152 ordered cases**, or **760** for `all`.

The fixture counts only SQL and capability hooks inside registered callbacks,
not bootstrap/HTTP authentication work. Malformed cases also require unchanged
posts/meta/terms/relationships/cron snapshots, zero mutation hooks, and the
exact callback observation count for that boundary; missing evidence fails.
Valid denied-role and hierarchical detach/omission controls calibrate real
authorization and writes. The runner uses unique owned actors/posts, refuses
an existing observation option, retains raw inputs/results/wire/state/counters,
records cleanup failures, and journals only its own actors, credentials,
sessions, objects and observation option. Cleanup first validates ownership.
The stage supplies the exclusive private container journal directory
`/tmp/wstm126-stage/invocations` through `WSTM126_JOURNAL_DIR`, never a public
artifact directory. The stage refuses finalization while any journal remains.
Foreign replacements or deletion vetoes retain the affected objects and their
actor/credential evidence rather than allowing implicit `wp_delete_user()`
deletion. Independent absence and original scoped-state/cron checks, not
deletion return values, determine cleanup success. Keep failed artifacts and
private recovery journals as well as later passes.

`input-schema-proof.php` validates complete owner/project/source/boundary-bound
evidence, all 152 labels/outcomes, exact callback/error/no-work controls and
cleanup attestations. Production/package hashes are distinct from the read-only
test harness overlay. A failed test with complete proven cleanup retains its
nonzero exit but may retire the retention marker; missing, partial, malformed
or foreign proof may not. The unchanged project retention functions block
outer source/package/workflow teardown until cleanup and original actual HTTP
restoration are proven. The read-only probe/private stage lock retire last.
HTTP convergence is bounded and retries only recognized owned stale state,
never mutations or global OPcache/configuration changes.

Source and original-ZIP workflows upload `e2e-artifacts/input-schema-*/`
including failed stage/boot/boundary evidence. Configuration backups,
credentials, Authorization headers and session secrets do not belong in public
artifacts. Package QA uses the existing verified original ZIP production mount
and read-only test overlay, without a checkout-production fallback.
Malformed or denied HTTP bytes are captured privately before decoding; public
diagnostics expose only safe evidence and byte-count/hash references. Retained
private failure evidence keeps an invocation ownership marker, so successful
database cleanup alone cannot authorize container teardown that would destroy
that evidence. Preserve it through coordinator-controlled recovery; never copy
private response archives into the public artifact upload directories.

Journal fault-injection tests use a coherent in-memory model of only
`state.json` and `next.json`; other paths retain their normal filesystem
behavior. Successful model replacement transfers the complete node and removes
the source, while false/throw controls preserve both nodes. Acquire and
finalize-proof-save failures must retain ownership and pending state; finalize
failures must also retain every private wire file and the probe. A separate
no-adapter control exercises native replacement. The default remains exactly
one native atomic `rename()`, with no retry, suppression, destination deletion,
copy fallback, runtime flag or platform skip.

This is deterministic testability coverage, not a native Windows repair.
Full Windows QA at `8061e62` and `3f6e8e0` reported access-denied journal
replacements in acquire and finalize, respectively. Their cause is unproven;
both failed logs remain evidence. A focused pass, a later full pass or the
in-memory model does not explain or waive those failures. The owner ran the
second QA without parallel safeguards, but parent comments-only tests overlapped
part of its interval; neither host-wide isolation nor interference is established.

Local unit/static results do not establish the source-derived HTTP expectations.
Actual direct/native/raw-permission/gateway/individual proof, runtime coverage
audit, and integration with concurrent changes remain pending. Optional output
schemas are explicitly deferred; the [migration guide](../../docs/3.0-migration.md#strict-input-schemas-and-raw-permissions)
documents open maps, validation scope, and exact failure-layer differences.

### Historical frozen-parent integration and remaining runtime gates

The schema candidate integrates the existing destructive-safety parent
`1a8e76dae6183d99a48ff4c0a7ae34c1cdd17e18` (tree
`0b7806574d279e40351d7675386e70266ca0a2bf`), which includes accepted main
`90a2740` and the metadata, error, plugin-inventory, coverage, and release
safeguards. This is source integration, **not** acceptance of the parent's
destructive-operation runtime proof or permission to merge/publish. The
45 `wstm116` manifest cases, raw 100-ID bound, exact boolean confirmation,
force/preview semantics, and original metadata object corrections are retained.
No list-performance or other pending PR implementation is imported.

That source freeze deliberately had no shared schema wiring. The later
`5b39f6a` integration accepted exact safety parent `f93b7d1`, and the separately
authorized schema stage above adds only its isolated callsite, new helpers and
artifact uploads without redesigning the accepted safety lifecycle.
`InputSchemaProofTest`, lifecycle/cleanup/boot units and
`tests/input-schema-stage-test.sh` exercise local fake filesystem/HTTP/clock and
outer orchestration failures. They do not grant a runtime lease or replace
source/floor/original-package evidence. Retain failed artifacts rather than
replacing a failed run with a later pass.
The integrated 78 error oracles, metadata/SEO suites, parent matrix and
destructive proof must also be rerun on the supported WordPress floor and
pinned version. These are pending runtime/package gates, not claims made by
local Composer QA, safeguard mocks, or workflow linters.

## Comment moderation regression coverage

`comments-fixture.php` adds `wstm105_*` fixtures and comment-specific checks. Its `wstm105_moderator` actor has the actual `comment_moderator` role with only `read` and `moderate_comments`. Cases cover all four writes, optional update statuses, Author moderation-floor denials, mapped-CPT allowed/denied controls, own-draft moderation, Administrator access, orphan comments, and missing/nonpositive IDs. Existing Editor cases and every landed main manifest case remain unchanged; runtime registrations remain the coverage authority.

`assert_comment_state` requires `comment_id`, `content`, and `status`. It reloads the comment after execution and checks both persisted fields, even when the expected result is failure or the response assertion already failed. Security QA requires this evidence for the four moderator-only denials.

The contract runner also invokes both registered callbacks directly for authorization, malformed input, capability filters, and core orphan behavior. Missing objects retain their execute-callback errors rather than becoming permission failures. Expected core permission notices are not suppressed; only notification emails for deliberately orphaned fixtures are disabled.

The CLI-only `comments-runner.php` adds 308 cases across direct execution (104), the actual WordPress ability wrapper (104), and authenticated MCP HTTP (100). It retains raw HTTP tool results, exact error messages/codes, effective capabilities, per-comment content/status, and before/after hashes of all comment and commentmeta rows. Failed calls must leave both tables unchanged. Global-comment controls cover both direct execution and the ability wrapper; the contract fixture also calls the permission callback with a populated global comment and a zero ID. Negative-existing-ID controls cover all three boundaries. HTTP fixture application passwords are revoked. The runner refuses web access before WordPress bootstrap; artifact write failures are fatal.

Contract and HTTP lanes run their respective boundaries via `WSTM105_BOUNDARY` and retain `comments-direct.json`, `comments-ability.json`, and `comments-http.json` for seven days, including failed runs. For baseline comparison, use this identical runner with `WSTM105_MODE=baseline` against the old registered callbacks on a disposable site, and a separate `WSTM105_ARTIFACT` path. Baseline mode records the old authorization/global-comment bugs rather than asserting the fix; malformed direct calls without a stable historical contract are fixed-only. The calibrated fixed mode marks 136 cases as `compatibility` (24 direct, 64 ability, 48 HTTP); unchanged baseline mode retains 276 cases and its historical 152 compatibility flags. Compare only the shared compatibility-marked cases without normalizing away raw envelopes or error codes. Never install old callbacks on a shared/live site, and preserve baseline/failed calibration artifacts outside `e2e-artifacts` before a fresh suite clears it.

The strict-input integration calibrates exactly 16 HTTP Subscriber malformed-ID
cases and 32 direct Editor/Subscriber malformed-ID cases across update, approve,
trash and spam. They now require `invalid_input` / `ability_invalid_input`, the
exact schema message, and object details, before original callbacks or
capability/query work. The 16 HTTP failures were observed in genuine frozen
`5b39f6a` CI; the 32 direct changes are source-derived and verified against the
actual registered production wrappers in isolated tests. The corresponding 32
native-ability cases already expected schema rejection and are unchanged.
Changed cases are not legacy compatibility claims. All typed runner inputs,
ordering, capability facts and no-write snapshots remain intact; the integrated
real-runtime rerun is still pending.

Raw fixture coverage grows from 102 to 141 checks without dropping inputs:
all 44 malformed payloads remain (41 schema rejections and three
negative-integer-ID permission deferrals), and all 39 original approve/trash/spam
payloads containing irrelevant content/status remain explicit schema-negative
controls before 39 additive minimal-ID counterparts. The counterparts preserve
the original role, CPT, orphan, state and content assertions. Valid authorization,
missing-object, zero/global-comment, capability-filter and six update
content/status controls remain; no minimum-ID restriction or blanket schema
error mapping is added. Private unwrapped moderation-helper deferral remains
correct and unchanged. The typed
[calibration ledger](../unit/fixtures/comments-calibration-ledger.json) pins
`2feed8d` and `5b39f6a` before/after cases; mutation tests reject error-envelope,
input, original-payload, authorization and no-write regressions.

The sealed historical ledger is not regenerated for the later permission-default
fix. Its current-production check has one explicit test-local transition:
verify the LF-normalized current Ability SHA-256 and Git blob, reverse exactly
one anchored four-line permission-default addition, and require both the
historical source hash and unchanged ledger head pin. All other production
hash checks stay unchanged. Missing, reordered, modified or duplicated guards,
unrelated source drift and forged historical bindings must fail; this is not
an alternate-hash allowlist or a skipped provenance check.

The separate callback-only legacy double exposes its faithful empty schema.
`AbilityCallbackTest` still checks once-only `RuntimeException`/`Error`
handling, exact safe permission errors and unchanged native return values.
It is not a production fallback or a replacement for actual-core evidence.

HTTP session-close and application-password revocation failures are recorded individually under `cleanup_errors`, increment the failed count, and do not prevent subsequent cleanup or evidence writing. Existing case failures remain intact and the runner exits nonzero. Unit regressions inject both transport-close and credential-revocation failures to verify this behavior.

## Shared runtime and coverage

### SEO keyword data/message separation (partial #108)

`tests/fixtures/seo-analysis.php` supplies five inert-marker scenarios to the unit tests, ability manifest fixtures, and existing MCP HTTP CRUD runner: Yoast found/missing with a competing SEOPress value, SEOPress found/missing after empty-Yoast fallback, and no keyword. Contract cases compare the complete `good` and `issues` arrays (including check IDs, severity, and every diagnostic message), exact keyword/title metrics, provider source, and score through existing `assert_values` placeholders. All earlier cases, including permission negatives, remain intact.

The HTTP runner creates a separate owned SEO post, updates plain content and each authorized metadata key in separate calls, confirms stored metadata, executes SEO analysis through the actual MCP gateway, and retains each response in `mcp-crud-summary.json` under `seo_analysis`. It also rejects inert markers in every diagnostic message. The original CRUD post remains scheduled for its existing future-post deletion scenario; an extra read verifies its state before deletion. Dedicated SEO cleanup runs even after a case failure, records its response, and fails the summary for unsuccessful cleanup, wrong IDs/statuses, or exceptions. Unit tests additionally cover exact markup, quote, and backslash retention for both providers and both branches, plus unchanged response keys for fully authorized analysis. Run `composer qa:unit -- --filter SeoAnalysisTest`, then managed `scripts/e2e-test.sh all` for actual WordPress and transport evidence.

This only covers separating focus-keyword data from diagnostics. It adds no field markers, does not verify all annotations or resolve #108, and is not a prompt-injection prevention test.

The harness disables request-triggered WordPress cron before installation and fixture setup in its disposable QA installation. Otherwise, HTTP health checks can start background tasks such as enclosure cleanup while a regression compares whole-database snapshots. Scheduled events and explicit calls to core's future-publication guard remain enabled and asserted; no production plugin setting or no-write predicate is changed. Use a fresh owned runtime, since setting `DISABLE_WP_CRON` does not stop a cron process that is already running.

Compatibility lane artifacts retain both runtime metadata and the detailed `e2e-artifacts/` reports for 30 days, including failed scheduling cases. A failed or unavailable lane still blocks promotion.

Release QA opts into extracted-package execution with `E2E_PACKAGE_ROOT` and `E2E_PACKAGE_ZIP`. Both must be supplied together, with managed Compose and the standard `e2e-artifacts` output directory. Before touching Docker, the harness requires the root to match the original ZIP exactly and the ZIP to match the source allowlist. `scripts/release-qa.sh` creates this fresh runtime extraction automatically, selects `docker-compose.release.yml` in addition to the base file, and keeps Plugin Check's extraction separate. The override exposes only the extracted production root, read-only `tests/`, compatibility helper files and baseline JSON, plus writable report output. No `vendor/` or whole-checkout bind is present. Default source E2E does not opt in and is unchanged. Use a unique disposable Compose project; do not point package mode at an existing development stack.

Current coverage is 75 base registered abilities plus 5 generated abilities per eligible custom post type. The E2E runner registers two fixture custom post types, so the manifest covers 85 abilities (see the manifest validation script's runtime summary for the current test case count), including custom post type discovery and CRUD permission coverage for two capability maps, object/status-aware post, page, CPT, media, and SEO score list filtering, private-status and bulk-publish denial cases, sensitive identity-field absence checks, taxonomy get/update permission coverage, post and page listing response-shape assertions, expanded Yoast and SEOPress metadata write coverage, controlled Google Site Kit status/module/permission/PageSpeed route fixtures with shared-dashboard, denial, privacy, and same-site URL coverage, the bulk post abilities `webmastery-site-toolkit-for-mcp/bulk-trash-posts` and `webmastery-site-toolkit-for-mcp/bulk-publish-posts`, the revision abilities `webmastery-site-toolkit-for-mcp/list-revisions` and `webmastery-site-toolkit-for-mcp/restore-revision`, the post meta abilities `webmastery-site-toolkit-for-mcp/get-post-meta`, `webmastery-site-toolkit-for-mcp/update-post-meta`, and `webmastery-site-toolkit-for-mcp/delete-post-meta`, the block editing abilities `webmastery-site-toolkit-for-mcp/list-content-blocks`, `webmastery-site-toolkit-for-mcp/patch-content-block`, and `webmastery-site-toolkit-for-mcp/patch-post-content` with targeted post, page, CPT, permission-denial, and unsupported-type coverage, the comment interaction abilities `webmastery-site-toolkit-for-mcp/reply-comment` and `webmastery-site-toolkit-for-mcp/update-comment`, the media sideload ability `webmastery-site-toolkit-for-mcp/upload-image`, the content hygiene abilities `webmastery-site-toolkit-for-mcp/list-orphaned-media`, `webmastery-site-toolkit-for-mcp/list-posts-no-featured-image`, and `webmastery-site-toolkit-for-mcp/list-stuck-scheduled`, the site introspection abilities `webmastery-site-toolkit-for-mcp/get-site-info`, `webmastery-site-toolkit-for-mcp/get-user-info`, and `webmastery-site-toolkit-for-mcp/get-environment-info`, the user access audit ability `webmastery-site-toolkit-for-mcp/user-access-audit`, the Yoast score and metadata abilities `webmastery-site-toolkit-for-mcp/get-seo-scores`, `webmastery-site-toolkit-for-mcp/get-readability-scores`, and `webmastery-site-toolkit-for-mcp/get-yoast-metadata`, the SEOPress metadata ability `webmastery-site-toolkit-for-mcp/get-seopress-metadata`, the webmaster verification ability `webmastery-site-toolkit-for-mcp/webmaster-verification-status`, the database health ability `webmastery-site-toolkit-for-mcp/database-health`, the performance status ability `webmastery-site-toolkit-for-mcp/performance-status`, the backup status ability `webmastery-site-toolkit-for-mcp/backup-status`, plus the plugin abilities `webmastery-site-toolkit-for-mcp/list-plugins`, `webmastery-site-toolkit-for-mcp/plugin-audit`, `webmastery-site-toolkit-for-mcp/activate-plugin`, and `webmastery-site-toolkit-for-mcp/deactivate-plugin`.

`update-cpt-mcp-book` regression coverage (issue #107) proves taxonomy assignment is pre-validated before `wp_update_post()` writes anything: each denial case (a nonexistent taxonomy, a registered taxonomy the actor lacks `assign_terms` capability for, and a mixed payload combining one allowed and one forbidden *registered* taxonomy alongside the existing allowed-plus-nonexistent case) submits a full `title`/`content`/`status`/`slug`/`taxonomy_terms` payload, and the paired read confirms the fixture's title, content, status, slug, and taxonomy terms are all unchanged. A second fixture taxonomy, `wstm107_restricted_shelf`, is registered only for `mcp_book` and granted to no role, so it is always forbidden and exercises the mixed allowed/forbidden registered-taxonomy path independently of the nonexistent-taxonomy case. The fixture post used for these cases (`wstm107_book_id`) is created with an explicit, deterministic slug so the unchanged-slug assertions are stable.

## Ranked coverage reconciliation (#120)

This ledger reconciles all ten groups in
[`ISSUES/19-close-test-coverage-gaps-ranked-by-blast-radius.md`](../../ISSUES/19-close-test-coverage-gaps-ranked-by-blast-radius.md).
It is a requirement/evidence map, not a test-count-based closure claim. Groups
1/6/7/9/10 originated in the partial #160 follow-up; groups 2/3/8 have dedicated
real-core suites, group 4 is supplied by #105/#157, and group 5 combines #159
with the breaking metadata contract in #164. **The accepted #164 baseline is
integrated at `822f056cfaf2c61acfa1beddd0ddd7f2becabbee`.** The historical issue's
combined metadata success behavior is intentionally not restored.

Here, **manifest** means `abilities-manifest.json` executed by `ability-runner.php`
against actual WordPress; **direct** means a registered execute callback invoked
without the outer ability wrapper; **HTTP** means authenticated MCP Adapter
requests, not a mocked transport. PHPUnit helper/observer seams are identified
separately and do not establish WordPress authorization or persistence.

| Group / disposition | Permanent tests and execution boundary | Allowed/denied controls and state purpose | Retained runtime evidence |
| --- | --- | --- | --- |
| 1: permanent media deletion - covered | Manifest `wstm120 delete-media`, `get-media`; `coverage-fixture.php`. | Subscriber denial plus an Author who has `upload_files` but lacks `delete_post` on an Editor-owned attachment; original Author delete success retained. Snapshot denial checks include attachment/postmeta rows, parent thumbnail link and actual upload hashes; the owner subsequently reads the retained attachment. These snapshots do not observe transient hooks. | Manifest `coverage_evidence`: effective capabilities, callback denial, nonempty identical before/after state; owner read success. |
| 2: disabled trash - covered | `trash-safety-runner.php`, **fresh PHP processes** with `EMPTY_TRASH_DAYS=0` and `30`; real ability execution for posts, pages, both fixture CPTs and bulk trash. A per-case setup cannot redefine a boot constant. | Author-owned posts, Editor pages and a mapped-CPT actor have enabled-trash/restore controls. Subscriber, missing-ID and wrong-type failures retain their precedence. Disabled trash rejects authorized requests with `trash_disabled`; stored posts, revisions/descendants, postmeta, terms, comments/commentmeta remain. Permanent-deletion API/filter/action observations stay empty; mixed bulk results retain exact per-ID outcomes. | `trash-safety-disabled.json`, `trash-safety-enabled.json`: boot constant/version, row counts/hashes, deletion observations and restore checks. |
| 3: category/tag deletion - covered | Manifest Subscriber denials/default-category case; `taxonomy-write-runner.php` direct and wrapped execution; `TaxonomyWriteTest` for forced core return values. | Administrator/Editor and remapped delete-only successes contrast with Subscriber, manage-only, wrong remapped capability, per-term mapping and final `user_has_cap` denials. Raw terms/taxonomy/meta/relationship snapshots and write hooks remain unchanged on denial. Real default-category deletion returns zero; even a final meta-cap override must not turn it into success. Units separately force zero/false/`WP_Error` and true; they are not real storage failures. | `taxonomy-write-summary.json`: permission scenarios, unchanged hashes/empty hooks, successful persisted fields/deletion, default-category core result. |
| 4: comment moderation - covered by #105/#157 | `comments-fixture.php`, manifest `wstm105` cases, `comments-runner.php` direct/ability/HTTP; `CommentsProofTest` covers failed cleanup retention. | `comment_moderator` has only `read` and `moderate_comments`, not object edit rights. All four writes require both the moderation floor and effective `edit_comment`; Author, other-object and unmapped-CPT denials contrast with own-draft, Editor/Administrator and mapped-CPT successes. Content/status readback and all comment/commentmeta hashes survive denials, including malformed/global-comment controls. | `comments-direct.json`, `comments-ability.json`, `comments-http.json`: effective capabilities, canonical results/raw HTTP, stored content/status and table hashes. |
| 5: registered and combined metadata - covered with accepted #164 | `post-meta-authorization-fixture.php` / `post-meta-authorization-runner.php` direct/ability/HTTP plus manifest `wstm110`; #164 `metadata-batch-runner.php` and `seo-metadata-runner.php` add direct/ability/gateway/individual-tool proof. | Restricted registered keys on posts/pages deny reads, upserts/no-ops and deletes; Administrator/custom/primitive grants and distinct edit/delete-cap controls remain. Global/subtype policy and effective map/user-cap denials are exercised with real providers. Combined `meta`/raw keys/34 SEO aliases are **rejected**, including empty/null presence, before writes. Allowed draft, plain update, separately authorized exact-key metadata, then publish calls calibrate observers; this workflow is **non-atomic**. Standalone denials preserve post/meta state and mutation hooks; combined denials preserve posts/revisions, metadata, term tables/relationships and cron with no write hooks. SEO denied-key read observers contrast with allowed reads. | `post-meta-authorization-{direct,ability,http}.json`, `metadata-batch-{direct,ability,http,individual}.json`, `seo-metadata-{direct,ability,http,individual}.json`; raw wire, effective permissions, source hashes, snapshots and calibrated hook/read observers. |
| 6: Contributor boundaries - covered | Manifest `wstm120 contributor`, real core `contributor_test` role, dedicated objects in `coverage-fixture.php`. | Own-draft create/update/trash successes assert stored fields; publish/private/future creation and transitions, own-published deletion and unrelated read/update/delete fail with unchanged state. The successful observer-calibration write must change the snapshot. Under #164 the original combined transition inputs remain separate metadata-rejection cases; plain transition cases still test publishing permission rather than failing early for metadata. | Manifest role/capability evidence, stored-post assertions, before/after snapshots and independent follow-up reads; `MetadataMigrationTest` protects the distinct permission purpose. |
| 7: private/trashed getters - covered | Manifest `wstm120 get-post` / `get-page` with real `edit_post` mapping. | Allowed owners/Editors, denied Subscribers/other-object Contributors, page ownership without `edit_pages`, and limited page/other-object editors distinguish editing from reading/deleting. `_wp_trash_meta_status` differentiates former draft versus published trash. Success checks assert ID/status/content/author; denials omit `data`, and lower-privilege responses omit `author_login` where specified. | Manifest `coverage_evidence.capabilities` and callback result, persisted trash-origin metadata and response assertions. This is the direct-getter policy, not list filtering. |
| 8: patch HTML - covered | `patch-html-runner.php` invokes actual WordPress abilities for block-path/hash, heading/exact and nested block patches; manifest `wstm115`. | Effective `unfiltered_html` Editor controls retain untouched iframe/script/etc. bytes while sanitizing supplied replacements. Author and explicitly filtered Editor controls retain core save filtering; Subscriber/foreign-object and stale/missing/ambiguous target failures leave raw content unchanged. Full-content update and mapped-CPT controls preserve their separate contracts. | `patch-html-summary.json`: actual capability/save-filter state, raw persisted before/after/expected content, response hashes and untouched-block hashes. It is content-state proof, not whole-database/zero-hook proof. |
| 9: pure helper boundaries - covered by units | `MediaUrlTest`: `validate_public_image_url` through overridden DNS resolver methods; `PostsCharacterizationTest`: `parse_block_path`, `replace_block_by_segments`, `patch_content_by_heading`, metadata normalization/validation; `PostsHelpersTest`: raw `patch_content_by_exact_match`; `SiteKitUrlTest`: `is_same_site_url`. | Allowed/denied DNS/literal/alias cases, resolver failures/bounded cycles; valid/invalid paths, nested replacement/no partial mutation, heading boundaries/ambiguity, exact raw needles/duplicates; metadata depth 10/11 and encoded JSON 100000/100001-byte boundaries plus encoding failures; host/port/scheme/userinfo/fragment cases. These are deterministic helper seams, not WordPress parsing, DNS/network integration or capability evidence. | PHPUnit results; real-core patch evidence above and `media-download-fixed.json` are complementary integration evidence, not replacements for threshold units. |
| 10: formerly success-only paths - covered | Manifest `wstm120` media-read/SEO/list cases; `wstm122` media-update denial/readback; original category/tag create cases plus the taxonomy suite. | `get-media` object/floor denials retain owner success. Author media-update success seeds alt/title/caption; Subscriber denial retains alt metadata and a subsequent Author getter verifies title/caption/alt. SEO Contributor own-draft success contrasts with Subscriber/other-object failure. Post/page lists require editing capabilities; category/tag lists allow Subscriber `read` but deny `no_role`. Original allowed controls, response shapes, filtered totals and hidden identity fields remain. Editor category/tag creation contrasts with Subscriber and remapped taxonomy denials. | Manifest case outcomes/capability assertions and selected state snapshots/readbacks; taxonomy direct/wrapped state evidence. Not every readonly manifest case claims a full snapshot or HTTP execution. |

Direct post/page getters currently require **`edit_post`**, not the list helper's
`read_post` (private) or `delete_post` (trash) checks. An Author or Contributor
can edit their own private post through `edit_posts`; other-owned private posts
add `edit_others_posts` and `edit_private_posts`. Owning a page does not grant
`edit_pages`. A limited page editor can read their own private/trashed draft page,
and a limited editor can read another user's trashed draft despite lacking delete
permission. A Contributor's own formerly published trash still requires
`edit_published_posts`. These are characterization assertions, not a new policy.

| Fixture actor | Relevant coverage boundary |
| --- | --- |
| Contributor | Core `contributor` role: owns drafts, lacks publishing, editing/deleting published posts, other-object editing/deletion, and page editing. |
| Author | Own private/trashed draft post reads; `upload_files` does not authorize another user's attachment. |
| `wstm106_page_editor` | Existing page-limited role reused with dedicated owned private/trashed page fixtures; no private-other-page or deletion grant is added. |
| Subscriber / `no_role` | Category/tag lists require `read`: Subscriber allowed, no-role denied. Post/page lists instead require `edit_posts`/`edit_pages`: both are denied. |
| Editor / `limited_editor` | Allowed direct-get counterparts and the distinction between edit-capable direct reads and delete-filtered trash lists. |

`assert_unchanged` compares raw persisted posts (including revisions), all
postmeta, term relationships, the cron option, and every upload file's path and
SHA-256 before permission checking and after execution. Media fixtures start
with a real file, attachment metadata, alt text and a parent thumbnail link.
Evidence is retained per case in `e2e-summary.json`; failed comparisons are not
skipped just because the response was denied. `assert_stored_post` independently
reads persisted fields; a successful Contributor write with `assert_changed`
calibrates the snapshot observer. These checks prove persisted-state equality,
not absence of transient/no-op write-hook calls.

`assert_permission` distinguishes callback `forbidden` from WordPress's outer
`ability_invalid_permissions`. On Contributor status updates, object permission
succeeds, then execution returns canonical `forbidden` with the exact publication
denial message; it is **not** a legacy string-error exception. Required capability
denials cannot be replaced with not-found, invalid-input or missing-confirmation
failures.

Heading units load the unchanged Posts class into a test namespace, supplying
explicit block arrays and recording serialization input. They do **not** emulate
a WordPress parser or prove core sanitization/authorization. Metadata units use
the existing narrow sanitizer stubs and a native JSON encoder boundary (not
WordPress's invalid-UTF8 repair); `INF`/`NAN` exercise encoding failure.
Depth is zero-based: a scalar at depth 10 is accepted, at 11 rejected; null
children bypass recursive normalization, so an array at depth 10 can contain
null. Size cases encode to exactly 100000 and 100001 JSON bytes, not raw string
length. Same-site URL characterization retains the existing effective-port
comparison, including different HTTP(S) schemes with the same explicit port.
The existing 45-check real-core patch HTML runner remains the integration
counterpart; unit seams do not replace it.

Registered metadata assertions follow WordPress's **effective key capability**:
object editing plus `edit_post_meta` (reads/upserts) or `delete_post_meta`
(deletions), including global/subtype registrations and final capability filters.
A false `auth_callback` is not asserted to be an unconditional veto over an
explicit primitive grant. The unregistered allowlisted SEO compatibility default
does not bypass object editing or effective site policy, and temporary filters
must be restored on denial and exceptions.

### Permanent regression guards

`scripts/validate-security-qa.php` requires the destructive negative abilities,
exact callback/wrapper permission cases, Contributor success/failure and all
publication statuses, media-delete state evidence, moderator-only comment state,
restricted metadata allow/deny cases, and list/privacy controls. The separate
manifest validator checks actor names and assertion shapes. Neither static
validator proves actual runtime registration, effective permissions or no writes.

`CoverageManifestTest` retains its validator mutations and additionally guards
the existing Subscriber `assert_unchanged` cases for bulk trash, post/page/both-CPT
deletion and standalone metadata deletion, with their actual Editor or mapped-CPT
positive-write counterparts: bulk trash needs a positive success count and a
trash success tied to a submitted ID; metadata deletion needs a positive deleted
count; individual deletion needs the submitted ID and trash status. Dry-run
previews and zero-write results cannot substitute. It also requires media-update denial/alt-state evidence
between the Author's successful write and unchanged title/caption/alt readback.
Mutations of isolated decoded copies remove each denial, state assertion or
allowed control, substitute non-permission failures or preview/zero-write results,
break success target/state evidence, and corrupt media readback;
the same assertions used on the real manifest must reject them. No manifest
inputs, object/array shapes or scenario counts are rewritten or pinned by these
guards.

The metadata suite's `MetadataMigrationTest`, `MetadataBatchFixtureTest` and
`MetadataTransportTest` separately protect the migration's permission purpose,
snapshot/hook observation, read failures, required HTTP observer evidence and
error/cleanup handling. Their stubs validate test machinery, not real WordPress.

### Reviewed runtime provenance and accepted baseline

The reconciliation reviewed genuine [contract/HTTP CI run 35563863522](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35563863522)
and [original-ZIP package CI run 35563863520](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35563863520)
at metadata head `7fb2c04b0123ca9070b396b68828c49bdd7a2f38`,
on WordPress **7.1.1 / PHP 8.2.33**. The contract summary reports actual
`wp_get_abilities()` coverage of 85 registered and 85 manifest abilities,
518 passing cases (242 expected failures), not a static namespace count.
Detailed retained reports include enabled/disabled trash, taxonomy, patch HTML,
comments, standalone metadata, combined metadata and SEO observations.

Per-case manifest capabilities/snapshots were also reviewed in the retained
`primary-e57b9dc-accepted/e2e-summary.json` from the earlier local primary proof,
at `e57b9dc791d1e5891fe6d28e81c9d957c0362ff6`. This is **not** relabelled as
the later CI report: the only source difference to `7fb2c04` is the batch
runner's stricter exact-reason assertion; the manifest, ability runner, fixtures
and production files are identical. Its missing/unknown ability lists are empty,
effective capability assertions match, denial snapshots are nonempty and equal,
and the positive Contributor calibration changes state. The later CI logs
independently retain the manifest's named pass/fail outcomes.

For the accepted metadata head, all four combined-metadata boundaries retain the exact
`invalid_input` / `metadata_requires_separate_call` rejection, unchanged
nonempty snapshots and zero mutation hooks; successful migration steps exercise
the same observers. SEO evidence retains effective denials, zero denied-key
reads and allowed-read controls. Original-package evidence is separate from
checkout execution. These are reviewed observations, not permanent fixed-count
test thresholds or proof for an untested future SHA.

Final-head raw reports were subsequently reviewed from the metadata worker's
`floor-7fb2c04-accepted` (WordPress **6.9.4 / PHP 8.2.31**) and
`package-7fb2c04-accepted` (WordPress **7.1.1 / PHP 8.2.33**) evidence directories.
Each contains 28 green runtime reports, including the raw manifest summary:
85 registered/covered abilities, 518 passing cases, matching effective capability
observations, nonempty unchanged denial snapshots and a positive state calibration.
Each combined-metadata boundary passes 1180 checks; across four boundaries the
raw records retain 4704 distinct strict rejections and 16 successful migration
sequences. SEO retains 252 cases per boundary, 1016 observed calls overall and
zero denied-key reads. These are historical results, not minimum test counts.

The source audit matches Posts/CPT/SEO production bytes to the accepted head.
Three runner/fixture files have Windows CRLF bytes; normalizing only line endings
matches Git exactly, rather than treating unequal raw hashes as identical.
The local original ZIP has SHA-256
`9effb8a08167e2f31e13171028e49c9b8d9b71d67c7f3e318f16d694f5ee9123`;
its complete runtime reports, pinned/latest Plugin Check (both actually 2.1.0,
zero errors/warnings), and unchanged original-package production-tree check
were independently accepted. This is not proof for PHP 8.0 or exact WordPress
6.9.0, nor a release/publication claim.

#164 was accepted on `main` as `822f056cfaf2c61acfa1beddd0ddd7f2becabbee`,
whose tree `263654ade4e2f1863f91d99ff71e682ad6841250` matches the frozen metadata
head. This reconciliation integrates that baseline without importing unmerged
production branches. No local WordPress/Docker execution is claimed by this
reconciliation worker: the source-bound runtime artifacts above are separate
from its local unit/static QA. Retain final reconciliation QA and exact PR-head
CI results in the handoff; this ledger does not close #120 or supersede
independent coordinator review.

## Diagnostic configuration and privacy regressions

The manifest retains existing diagnostic success/role cases and adds both request/configuration scheme mismatches, admin-only TLS, all six database query error contexts, and explicit lower-privilege field absence. `setup.diagnostics` installs scoped option/request/query fixtures and restores them in `finally`. `assert_diagnostic_findings` matches a check's bucket and label without relying on its array index or unrelated findings. Intentionally failed queries suppress database errors only within the disposable fixture; production logging is unchanged.

For focused baseline/fixed evidence, run `wp eval-file tests/e2e/diagnostics-runner.php` from the plugin directory in an isolated, disposable WordPress installation with the plugin active, users `admin`, `editor_test`, and `subscriber_test`, and a fixture table named with the current prefix plus `wstm111_plugin_data` (`id int PRIMARY KEY, value varchar(20)`, one fixture row). The runner returns JSON with individual predicates, responses, source/runner hashes, SQL read traces, outbound-call counts, and before/after hashes of options, posts, postmeta, users, and usermeta. It exits nonzero if any predicate fails. Run the **same runner** against baseline and fixed production sources.

The default mode exercises direct callbacks and the real ability wrapper, malformed/case-varied home options, permissions, all six real failed-query contexts, successful counters, default-private table identifiers, and explicit raw-name opt-in with exact metric/order parity. It preserves the original raw core/plugin presence checks in the opt-in cases. Database health also checks `manage_options` in direct execution before querying. Registered denials use the canonical `forbidden` category with the `ability_invalid_permissions` reason; direct local errors remain native `WP_Error`.

For authority-syntax regressions, run `wp eval-file tests/e2e/diagnostics-authority-runner.php` with the same prerequisites. It retains the unchanged original direct-runner report and adds the shared `tests/fixtures/diagnostics-authority.json` cases through direct callbacks and real wrappers, including read-only snapshots, response shape, and zero-outbound checks. Use the same runner against both production revisions. The syntax guard checks percent triplets and authority grammar, including bracketed IP literals; it deliberately does not validate DNS/IDN normalization, routability, or every path/query character. Valid local, Unicode/IDN, and percent-escaped configurations must retain their configured HTTP/HTTPS classification.

Use argument `debug` in separate PHP processes with `WP_DEBUG_LOG` disabled, `true`, custom inside/outside-content paths containing JSON/HTML punctuation, and a neighboring `.htaccess` fixture. Enabled logging must warn that access is unverified; disabled logging must still pass. Use argument `home` with an HTTPS `WP_HOME` constant to exercise WordPress's normal option filter. Do not put fixture configuration or tables on a real site.

HTTPS request variables in these tests are simulations, not actual TLS, certificate, reachability, or redirect tests. Run authenticated MCP HTTP checks separately when changing these findings. WordPress 6.9/7.1 on PHP 8.2 covers the WordPress floor, not PHP 8.0; the PHP floor requires its own runtime.

### Database table privacy (3.0 development)

`DatabaseTablePrivacyTest` covers custom prefixes, current-blog versus global mappings, custom user-table mappings, core-looking custom names, plugin-extended mapping labels, unchanged SQL scope/order, response-local label resets, exact metric parity, and query-free direct denials/malformed-input failures. These controlled unit mappings do not replace real WordPress evidence.

Seven additional manifest cases preserve the earlier diagnostic cases and add default/false/private and true/raw modes, Subscriber raw-opt-in denial, and string/numeric/null rejection. `setup.diagnostics.table_privacy` replaces only the table-size metadata SELECT with deterministic core/custom/core-lookalike rows. Assertions compare every projected field; the opt-in case uses actual-prefix fixture placeholders. The direct method rejects boolean-like strings/numbers with reason `invalid_input`. In this strict-input candidate, registered validation rejects them before permissions with `ability_invalid_input`, as it already does for null. The exact two native reason corrections are separately recorded in the integration ledger above.

Run `WSTM111_DISPOSABLE_SITE=1 php tests/e2e/database-table-privacy-runner.php` inside the owned disposable installation. The explicit environment opt-in is mandatory: the runner fails closed before WordPress bootstrap, loading fixtures, writing tables, or creating credentials when absent or not exactly `1`. It creates unique owned Administrator/Subscriber actors and plugin/core-lookalike tables, refuses preexisting table names, and verifies each DROP and actor deletion in `finally`. It checks omitted input, default/explicit-false/raw responses, exact logical/opaque labels and actual `wpdb` classifications per row, full-payload physical-name/custom-fingerprint absence, exact counts/bytes/order/other-field parity, and direct/registered denial/query evidence. JSON is retained in `e2e-artifacts/database-table-privacy.json`, including failures and cleanup predicates. Set `WSTM111_PRIVACY_ARTIFACT` to retain separate runs. Use a custom-prefix installation beginning `wstm111_private_` for an additional full-payload sentinel-prefix assertion and repeat on a real multisite subsite; the scope must not expand to out-of-prefix global tables. Arbitrary prefix substrings are not privacy evidence: `wp_` occurs in unchanged `wp_post_revisions_*` keys, and short prefixes or `custom_` can coincide with public labels.

For actual MCP checks, install the test-only `error-contract-fixture.php` MU fixture **after** the normal ability-registration audit, then set `WSTM111_PRIVACY_HTTP=1`. This selects real authenticated gateway and individual-tool calls instead of native calls, with empty-object/default handling, opt-in/schema/denial checks, raw tool results, complete default-wire identifier absence, and checked session/application-password cleanup. It reuses the error-contract wire parser rather than treating `isError` alone as proof and discovers the individual tool by its advertised description. No credentials are written to the report. This fixture must never be installed on a real site. Run against immutable input sources under an exclusively owned disposable Compose project and retain the source/runner hashes with the report.

The contract lane runs native proof after the normal manifest audit; the HTTP lane runs transport proof while the shared individual-tool MU fixture is installed. Original-ZIP QA runs both. Each stage checks non-CLI HTTP 403 before execution. Separate `database-table-privacy-native.json` and `database-table-privacy-http.json` reports are retained for seven days, including failed runs; mocked package/bootstrap tests require the disposable opt-in and preserve all metadata/error stages.

For a real subdirectory multisite, set `WSTM111_PRIVACY_SITE_PATH=/privacy/` to the owned subsite path before bootstrap. Native and HTTP calls then address that same blog. The registered Subscriber negatives capture and assert the exact core permission diagnostic; only that deliberate diagnostic is suppressed from the debug log, and all unrelated diagnostics remain enabled.

## Backslash persistence regressions

The `wstm122` manifest cases cover plain post/page creation and updates followed by separate SEO metadata calls, media upload/update title/caption/alt text, and the already-correct direct structured metadata path. Assertions include repeated/trailing backslashes, escaped quotes, regex-style JSON text, sanitized HTML/text, and denied updates with unchanged stored metadata. Uploads use the existing in-process HTTP image fixture; they do not download a live image or relax production URL checks. The HTTP CRUD runner also asserts metadata backslashes through separate calls after both post creation and update. Existing ordinary content/backslash and allowed/denied cases remain in place.

## Parent-assignment regression proof (#106)

Normal manifest QA retains its 85 registered abilities and all existing cases.
Parent cases add a page-limited actor, allowed and denied assignments, ordinary
draft create/update controls without scheduling, and nonhierarchical CPT
parent-presence rejection, including zero. Hierarchical types retain zero-parent
detach and omitted-parent compatibility.

After normal manifest/transport QA, `scripts/e2e-test.sh` temporarily installs
`parent-assignment-fixture.php` and invokes the CLI-only
`parent-assignment-runner.php`. Four additional hierarchical fixture CPTs
exercise default, generated, explicit, and unmapped primitive capability maps.
The dedicated runtime therefore enumerates 105 abilities; it does not replace
or inflate the normal 85-ability manifest audit.

Each WordPress version has 453 dedicated cases: 151 direct registered execute
callbacks, 151 `WP_Ability::execute()` calls, and 151 real authenticated MCP
HTTP calls. Contract QA runs the first two boundaries; Full MCP E2E QA runs
HTTP. Reports retain raw responses, exact requested capabilities, mixed
payloads, before/after post and revision rows, metadata, term relationships,
relevant publish cron events, and pre-write/write hooks. Positive controls
verify both persistence and observation. HTTP hooks are observed in the actual
server request rather than inferred from an in-process mock.

The matrix covers inaccessible/missing/wrong-type parents, self/descendant
requests, pre-existing loops, 260-level valid ancestry, inaccessible ancestors,
same-parent, omitted-parent, detach-zero, final capability filters, negative-ID
`absint()` compatibility, and existing error precedence. Ordinary Authors are
page-denial controls, not assumed page editors. The page check uses `edit_post`,
not the page object's alias; unmapped CPT capability behavior is tested as-is.

Detailed update fixtures start published and request a future status/date, to
exercise mixed writes without depending on the separate draft scheduling
behavior tracked in #113. Draft positives remain in the normal manifest.
Calibration attempts must be kept separate from accepted baseline/fixed proof.

The sole direct database mutation seeds a pre-existing corrupt parent loop
inside the disposable fixture database. It is not evidence that a normal API
creates a stored cycle. WordPress 6.9 and 7.1 normally reset self/descendant
assignments to zero while saving other fields, and can repair an unrelated
ancestor loop during an update. Fixed rejections instead leave every observed
graph member unchanged and never enter those core write/repair hooks.

Run the identical runner against base and fixed source with `WSTM106_MODE`
set to `baseline` or `fixed`; use `WSTM106_BOUNDARY=all` for all three boundaries,
or `direct`, `ability`, or `http` individually. Set `WSTM106_ARTIFACT` to a
separate absolute container path for each run and copy the resulting JSON
outside the disposable runtime before cleanup. Baseline mode records unsafe
outcomes and checks positive/existing-denial controls; it does not assert the
fix. Preserve runner/fixture hashes and production revision alongside results.

Both standalone HTTP access rejection and required evidence-write failures
are explicit. CI retains `parent-assignment-direct.json`,
`parent-assignment-ability.json`, and `parent-assignment-http.json` as dedicated
seven-day artifacts on successful or failed runs. Do not use these mutating
fixtures on a shared/live site. Use an owned `wstm-issue106-*` Compose project,
ephemeral ports, and inventory its named/anonymous volumes before removing only
those resources. Fixture application passwords are revoked; destroying the
owned runtime removes its remaining test sessions and data.

## Scheduling regressions

The contract phase also runs the CLI-only `scheduling-runner.php`. It exercises post/page create and update plus both fixture CPT capability maps, including malformed/missing/past/near-now dates, calendar overflow, valid offsets and relative dates, draft/pending zero-GMT scheduling, existing schedules, explicit status changes, DST folds/gaps, and site timezone changes. The manifest separately includes allowed and denied scheduling cases for all eight affected abilities.

For rejected calls, the runner compares post/revision, metadata, term-relationship, and cron snapshots and asserts that relevant pre-write/save/publication/term/meta/cron hooks did not run. Successful controls exercise those observers. It checks stored local/GMT dates and actual future status, and calls core's future-publication guard early to verify that ambiguous local cron conversions do not publish before authoritative GMT. Existing stored local/GMT strings survive a site timezone change without a new pair-equality restriction or cron override.

Exact -1/0/+1/+59/+60/+61-second boundaries use a fixed clock in unit tests. Real WordPress success cases are comfortably in the future: core's later clock sample can cross the cutoff even after an exactly +60-second preflight. These tests establish preflight behavior, not an atomic guarantee against clock ticks, process delays, or arbitrary third-party hooks.

The dedicated `e2e-artifacts/scheduling-regression.json` includes the runner hash, runtime versions, results, state hashes, observed hooks, stored dates, and cron timestamps. Artifact creation or incomplete writes fail explicitly. CI uploads this JSON on success or failure, requires its presence, and retains it for **7 days**. Full MCP E2E separately preserves actual tool-result envelopes for the three new scheduling errors in `mcp-crud-summary.json`, including the successful gateway wrapping a failed ability response.

For baseline/fixed comparison, run the same runner on owned disposable WordPress before and after the production change, and copy both artifacts outside the disposable Docker resources and outside `e2e-artifacts` before the next wrapper invocation clears that directory. Capture owned named and anonymous volume IDs before teardown and remove only those resources. Never target a live or shared site.

## Taxonomy write regressions (issue #117)

The contract runner also executes `taxonomy-write-runner.php` on the disposable WordPress database and writes `e2e-artifacts/taxonomy-write-summary.json`. Contract CI always attempts to upload this synthetic-fixture evidence as the `taxonomy-write-summary` artifact, retained for seven days, including failed runs when the file exists. Its CLI-only guard prevents HTTP invocation. The unchanged original manifest cases remain, with added create/delete denials and a default-category failure/read-back pair. All six writes have positive and negative manifest coverage.

The supplemental runner exercises wrapped abilities and their direct execute callbacks for default Administrators, Editors, and Subscribers; remapped edit-only/delete-only/manage-only capabilities; a global capability without the remapped grant; WordPress core aliases; final `user_has_cap` denial; and per-object `map_meta_cap` denials with otherwise sufficient capabilities. Denied calls must leave the persisted terms, taxonomy rows (including parents/counts), metadata, and relationships identical, with no watched write hooks. Successful calls verify persisted names/slugs/descriptions/parents or deleted-term absence and the legacy success envelope. Missing/wrong-taxonomy IDs retain their authorized not-found envelopes.

The 3.0 strict-schema calibration expands this separate runner from **156 to
164 cases**, not the 589-case manifest. Eight original delete payloads (category
or tag, missing or wrong-taxonomy ID, wrapped or direct) retain their identical
integer IDs, `name: "Must not write"` and `confirm: true`. Their unadvertised
`name` field now has the exact `invalid_input` / `ability_invalid_input` schema
expectation, empty object details and unchanged-state/write-hook assertions.
Each original precedes a new counterpart removing only `name`; that counterpart
retains the original authorized `not_found` purpose, message, ID and state
checks. The other 148 original cases are unchanged.

`TaxonomyCalibrationTest` and the sealed `taxonomy-calibration-ledger.json`
derive the actual runner's typed construction from frozen `3f6e8e0`/`436191f`
source. They verify the retained originals, additive counterparts, order and
unrelated rows, and reject provenance, input-type, error, authorization and
no-write mutations. Isolated real production callbacks reproduce eight old
schema mismatches and pass the 16 calibrated paths with original-work counters;
that source proof does not replace execution on a disposable WordPress site.

Core's `delete_term` mapping denies the default category with `do_not_allow`. Separately, real `wp_delete_term()` returns integer `0` and leaves that category stored. The runner tests both, then temporarily overrides the meta-cap denial to reach the real zero-return guard. This last case is a **forced permission policy on real core**, not a normal configuration. Unit `TaxonomyWriteTest` uses **stubbed** zero, false, and `WP_Error` returns to cover otherwise rare deletion branches; these are not evidence of a real database failure.

Permission callbacks return `WP_Error( 'forbidden', ... )`; direct execute denials retain the taxonomy `success: false`/`error` array. WordPress's outer ability wrapper deliberately replaces permission details with its generic permission error, so manifest cases do not require the callback's internal error code. The supplemental runner checks that code via `check_permissions()` separately.

To reproduce before/after on an owned disposable stack, first bootstrap it with `scripts/e2e-test.sh all`, then run (Bash):

```bash
docker compose -p "$COMPOSE_PROJECT_NAME" exec -T wordpress wp --allow-root eval \
  'require WP_PLUGIN_DIR . "/webmastery-site-toolkit-for-mcp/tests/e2e/taxonomy-write-runner.php"; $summary = wstm117_run_taxonomy_tests(); exit($summary["failed"] ? 1 : 0);'
```

Keep the new regression file while comparing the old versus fixed taxonomy class in an isolated checkout, and copy each summary outside the containers before teardown. Do not run this fixture against a live site. Pre-fix code wrongly permits remapped/per-term-denied writes and reports default-category success; fixed code rejects these without persisted changes. Creation uses taxonomy `edit_terms` to preserve management-level default access, not REST's more permissive nonhierarchical `assign_terms` policy.

## Targeted patch HTML regression coverage

The contract runner also executes `patch-html-runner.php` against real WordPress, after the manifest cases. It writes `e2e-artifacts/patch-html-summary.json` with case counts, effective `unfiltered_html` and core KSES-hook state, inputs/results, persisted raw content before/after, expected bytes, whole-content hashes, untouched-block hashes, PHP/WordPress versions, and the tested Posts source SHA-256. Any regression fails the contract command; its counts are separate from the manifest summary.

The disposable single-site fixture requires an Editor with effective `unfiltered_html` to seed unsanitized content through `wp_insert_post(wp_slash(...))`. It does not disable save filters or elevate the patch actor. Author, custom-capability CPT manager, and an Editor with an explicit `unfiltered_html` denial prove WordPress's normal save filtering remains in effect. These are effective-capability tests, not a claim that every Editor or Administrator has unfiltered HTML access, and not a full multisite test matrix.

Coverage includes both abilities and all four target modes; posts/pages and eligible CPT section/exact paths; iframe/script/style/form/SVG/event-attribute needles; still-filtered replacements and full-content updates; nested blocks; missing/duplicate/ambiguous targets; stale content/block hashes and denied object writes with no mutation; quotes, backslashes, and JSON block attributes. Canonical untouched blocks are byte-identical. Separate noncanonical fixtures document existing core parse/serialize normalization rather than requiring a new raw-splicing algorithm.

Run `bash scripts/e2e-test.sh all` on a fresh, uniquely named disposable Compose project as described below. Before a second run, copy the summaries and command logs outside `e2e-artifacts`, which the wrapper clears. To demonstrate the original bug, run these regression tests against the pre-fix Posts source in a separate disposable checkout and retain the failing raw-content evidence before testing the fixed source.

## Webmaster verification regression fixtures (issue #114)

The `wstm114` manifest cases preserve public Subscriber/Author access, retain both privileged Site Kit projections, and check both caller cache-warming orders and visible HTTP failures. `wstm114-verification-fixture.php` counts actual WordPress HTTP API invocations, checks the transient's public-only shape, and recomputes caller-visible summaries. Successful cold fixtures make four HTTP calls (three for the failure fixture with no sitemap); warm and denied cases make zero. HTTP responses are deterministic mocks in these cases; WordPress users, capabilities, transient storage, and ability execution are real. DNS in the WordPress contract runner is not mocked or presented as deterministic DNS evidence.

`tests/unit/WebmasterVerificationTest.php` loads the unmodified verification class body in the isolated `Wstm114` namespace. Test-only namespaced PHP/WordPress boundary functions count HTTP, DNS, plugin inspection, cache, and home access, with a controlled clock and transient store. These tests prove direct no-read denial does zero work on cold/warm caches, both caller orders never cache private state or summaries, authorized plugin state stays fresh, warm repeats do zero HTTP/DNS work through 59 seconds, expiry at 60 seconds refreshes, home/blog changes refresh, and HTTP/DNS failures stay visible and cached. This is fixture evidence, not live DNS or production WordPress testing.

The 60-second cache is not a strict rate limit: concurrent cold misses or transient eviction can repeat work. Full MCP E2E separately calls webmaster verification as a Subscriber over real HTTP JSON-RPC and checks both private paths are missing and the summary matches public checks. Transport success does not turn mocked contract responses into live Google/Bing verification evidence.

## Standalone metadata authorization

`post-meta-authorization-fixture.php` supplies restricted-key manifest cases and is installed temporarily as an MU plugin for `post-meta-authorization-runner.php`. The dedicated runner covers post/page metadata through direct execute callbacks, `WP_Ability::execute`, and authenticated MCP HTTP. It checks allowed/denied reads, filtered listings, absent/existing/unchanged upserts, absent/existing deletes, capability-specific policies, global/subtype precedence, actual SEO providers, effective `map_meta_cap` / `user_has_cap` denials, primitive/custom grants, and unregistered SEO fallback cleanup including exceptions.

Denied operations must retain the persisted object/metadata snapshot and never reach metadata mutation hooks. Success checks compare stored data and existing response fields. Provider cases compare effective core permission rather than assuming a false registration callback overrides a primitive grant; synthetic policy and real-provider evidence remain distinct.

The shell runs direct/ability boundaries in contract mode and HTTP in E2E mode, with `post-meta-authorization-<boundary>.json` evidence. Contract, HTTP, and package workflows retain these reports on success or failure. Cleanup attempts every session/password/post independently; return failures or exceptions fail the run and are recorded without suppressing its summary. The runner rejects web access before bootstrapping. The temporary MU plugin and application passwords are removed after the proof. Existing provider-create manifest cases retain their success expectations and values.

This coverage is deliberately limited to the three standalone post-meta tools. It does not validate key authorization in post/page create/update batches or separate SEO reads, does not claim atomic batch behavior, and does not close the creation-policy/release gate.

## Rule for new abilities

When a PR adds a new ability, it must also add at least one manifest test case for that ability. If the ability is registered but missing from the manifest, E2E QA fails.

For abilities with role or capability restrictions, include both:

1. A positive case for a role that should be allowed.
2. A negative case for a role that should be denied.

For security-sensitive abilities, also include any assertions needed to prove the response does not leak data. Examples include `assert_missing_paths` for login names, emails, backend versions, protected metadata, private/trash totals, or other fields that lower-privilege users must not see. `composer validate:security-qa` enforces the current high-risk permission-hardening cases that protect against regressions like the WordPress.org review findings fixed in PR #90.

## What CI checks

`scripts/e2e-test.sh contract` runs `tests/e2e/ability-runner.php`, which:

1. Creates deterministic WordPress fixtures.
2. Installs and activates the MCP Adapter, Yoast SEO, and SEOPress plugin dependencies.
3. Reads `tests/e2e/abilities-manifest.json`.
4. Gets the currently registered abilities from `wp_get_abilities()`.
5. Fails if any registered `webmastery-site-toolkit-for-mcp/*` ability is missing from the manifest.
6. Fails if the manifest references an ability that is not registered.
7. Executes every manifest case through `wp_get_ability()->execute()`.
8. Supports positive assertions, negative assertions, missing-path assertions for sensitive fields, post meta assertions, and post thumbnail assertions.
9. Writes `e2e-artifacts/e2e-summary.json` with coverage and result counts.

`scripts/e2e-test.sh e2e` runs `tests/e2e/mcp-crud-runner.php`, which:

1. Creates temporary Application Password credentials for the existing E2E editor and subscriber users.
2. Initializes real MCP HTTP sessions against the MCP Adapter default server.
3. Verifies `tools/list` exposes the `mcp-adapter-execute-ability` gateway.
4. Discovers this plugin's post CRUD abilities through `mcp-adapter-discover-abilities`.
5. Calls `mcp-adapter-execute-ability` over HTTP JSON-RPC to create, read, update, and trash a real draft post.
6. Confirms a subscriber write attempt is denied through the MCP transport.
7. Confirms Subscriber webmaster verification succeeds without private Site Kit fields and summarizes only public checks.
8. Writes `e2e-artifacts/mcp-crud-summary.json` with protocol-level result counts.

## Local QA entry points

### Bounded image-download evidence

The contract phase also runs `media-download-runner.php` directly with PHP (not `wp eval-file`). Both this runner and `media-http-server.php` reject non-CLI access before arguments or WordPress bootstrap. They start an owned ephemeral loopback HTTP fixture and produce `media-download-fixed.json` and `media-server-fixed.jsonl`; contract CI retains these alongside its summary for seven days. Required evidence-write failures fail the runner.

The runner routes a syntactically public IPv4 URL to its own local fixture **only in test hooks**, preserving production safe URL checks, redirects, core write callbacks, and actual cURL/Fsockopen transports. This proves bounded storage and early cancellation, not public DNS/network isolation. The manifest's `pre_http_request` mocks instead prove permission/metadata/error contracts and bypass core network validation; its media URLs use a mocked public literal so an unresolvable test hostname is not silently accepted. Resolver unit tests use subclass-only seams, never remotely controllable filters.

Cases cover oversized, missing/dishonest Content-Length, chunked and compressed responses, exact/under-limit images, generic/wrong Content-Type, featured metadata, redirects, non-200 responses, MD5 failures, incomplete HTTP bodies, short PNG headers, timeout, tempfile/sideload failures, object/capability denials, invalid limits, subsequent requests on reused transports, nested unrelated requests, and pre-existing same-path stream preservation. Rejection checks compare attachment counts, featured-image state, temporary paths, and open output streams.

Client progress counts and peak file bytes are separate from the server's successful socket writes (not a claim of client receipt). Native cancellation permits chunk/socket buffering overshoot. Compressed cURL input may expand into several decoded callbacks before cancellation, but file storage is still clamped to `max + 1`; Fsockopen stores encoded bytes without a new decompression stage. Do not infer a CPU quota or complete image integrity from these tests.

For local baseline reproduction, activate an isolated copy of the baseline plugin in the disposable stack and run this **same runner** with `baseline`; reactivate the working plugin and run with `fixed`. The baseline mode records behavior and requires an observed over-limit download; only fixed mode enforces the corrected assertions. Preserve both JSON/log sets and their source/runner hashes outside the disposable container before cleanup. Baseline mode is not a passing security regression gate.

Each row retains `policy_passed` under the same assertions in both modes. Baseline `passed` reports cleanup/reproduction harness status separately; `policy_failed` reports the old behavior's failures, and `skipped` explicitly lists invalid-limit scenarios not exercised on the baseline. `peak_temp_bytes` measures the root download; `all_temp_peak_bytes` can include an intentionally unbounded, unrelated nested `download_url()` call. An earlier, same-URL streamed nested request is injected from an ordinary HTTP argument filter to verify root identification before nesting.

Small chunked PNGs succeed through cURL at their exact decoded limit. Fsockopen already cannot sideload the chunk-framed bytes; at an exact decoded-image limit it now reports `file_too_large` because framing counts toward that transport's stream limit, and with spare room it still reports `unsupported_mime_type`. This is not a newly rejected previously successful Fsockopen upload. `WSTM_MEDIA_WP_ROOT` can select another verified disposable core tree for CLI compatibility evidence; it is not used by production.

The labeled `limit-error-after-success` case is cleanup-only fault injection, not cancellation evidence: a trusted test hook deliberately disables cURL cancellation and replaces its completed file with a valid small PNG. It verifies that an already-observed overflow still returns an error and deletes the successful core return path, including a filename changed by core.

That test hook selects `CURLOPT_XFERINFOFUNCTION` when available and otherwise `CURLOPT_PROGRESSFUNCTION`, matching the production transport's API selection. PHP 8.1 can expose only the latter even with a recent libcurl; the same scenarios and assertions apply on both paths.

### Isolated trash safety regressions

`scripts/e2e-test.sh contract` (and `all`, including CI and release/compatibility callers) also runs `trash-safety-runner.php enabled` and `trash-safety-runner.php disabled` with container PHP. Each is a fresh process that defines `EMPTY_TRASH_DAYS` before `wp-load.php` and asserts the actual value (`30` or `0`). Do not use `wp eval-file` or per-case setup to try to redefine this constant after boot. Neither runner edits `wp-config.php` or changes the HTTP server's configuration.

The runner rejects non-CLI requests with HTTP 403 before reading arguments or bootstrapping WordPress, including when PHP exposes query arguments through `register_argc_argv`. Its safety does not depend on distribution packaging exclusions.

The same assertions fail against the unfixed post-trash implementation with trash disabled and pass after refusal guards are installed. Coverage includes posts, pages, both fixture CPT capability maps, allowed/denied roles, missing/wrong-type precedence, and a mixed bulk request with authorized, forbidden, missing, and wrong-type IDs. Disabled attempts compare raw persisted post rows, content/status, metadata, taxonomy relationships, child/revision rows, comments/replies, and comment metadata before/after; they observe `pre_delete_post`/`pre_delete_comment` API filters and permanent-delete hooks and require zero calls/events. Bulk responses must have exact totals, per-ID errors, and no false successes.

Enabled boots assert existing trash success shapes and restoration: post/page restore uses registered restore abilities, while CPT restoration uses core `wp_untrash_post()` because no CPT restore ability exists. Core's default restored status is `draft`; content, metadata, taxonomy relationships, descendants, and comments must survive. Comment trash and update-with-trash are characterized in both boots: `wp_set_comment_status()` retains the row, unlike `wp_trash_post()`'s disabled-trash fallback. No production comment behavior is changed. Core wraps permission callback errors as `ability_invalid_permissions`; tests inspect both the callback error precedence through `check_permissions()` and the wrapped `execute()` result, suppressing only the exact expected core permission notice.

Fixtures/users/role names are scoped to `wstm109` and live only in the disposable stack. The runner writes `e2e-artifacts/trash-safety-enabled.json` and `e2e-artifacts/trash-safety-disabled.json`; the PR contract job retains both for seven days. Their counts are separate from `e2e-summary.json`. These are real-core **direct ability** configuration checks, not disabled-trash HTTP MCP tests. Ordinary HTTP MCP CRUD and subscriber denial remain in the separate transport runner with its normal server configuration.

For an already-bootstrapped disposable Compose project, rerun just these processes:

```bash
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/trash-safety-runner.php enabled
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/trash-safety-runner.php disabled
```

### Full suites

For quick local checks that do not require WordPress, run:

```bash
composer validate:e2e-manifest
composer validate:security-qa
```

These commands validate manifest JSON structure, required fields, allowed roles, expected result values, labels, assertion shapes, required security-sensitive coverage, and risky static permission callback patterns. They cannot replace full E2E coverage because they do not bootstrap WordPress or compare the manifest to `wp_get_abilities()`.

For both Docker layers, use a disposable Compose project with automatically assigned host ports. In Bash:

```bash
export COMPOSE_PROJECT_NAME="webmastery-qa-$(date +%s)-$RANDOM"
export MYSQL_PORT=0 WORDPRESS_PORT=0
E2E_MANAGE_COMPOSE=1 bash scripts/e2e-test.sh all
```

Use `contract` or `e2e` instead of `all` for a single layer. For an already-running disposable stack, keep its `COMPOSE_PROJECT_NAME` and omit `E2E_MANAGE_COMPOSE=1`.

Set `E2E_KEEP_COMPOSE=1` when you want to leave the containers running for debugging.

Use a unique `COMPOSE_PROJECT_NAME` for disposable validation, especially on a shared machine. The runner manages only that project; do not point automatic cleanup at an existing development stack.

The E2E bootstrap installs Yoast SEO from WordPress.org with the `wordpress-seo` slug and SEOPress from WordPress.org with the `wp-seopress` slug because SEO abilities and assertions cover both providers. Set `YOAST_PLUGIN_SLUG` or `SEOPRESS_PLUGIN_SLUG` only when testing against a specific compatible package. It also installs `tests/e2e/site-kit-fixture.php` as a controlled must-use plugin so Site Kit route and response changes fail deterministically without requiring Google account credentials.

### Site Kit permission regressions

The Site Kit fixture is **not the real Google plugin**. Its dashboard grant models an allowed upstream route, including the distinct `wstm125_read` and `wstm125_no_read` custom roles and a shared Subscriber. The manifest retains ordinary Subscriber upstream denial, adds local no-read denial despite an upstream grant, and preserves response projection and same-site URL assertions. `validate:security-qa` requires these cases for all three delegated abilities.

Ability Contract QA also runs `site-kit-permissions-runner.php`: 126 permission/direct-execution cases cover all three abilities, local read/no-read and Subscriber users, upstream allow/false/null/WP_Error, and missing/non-callable permission callbacks or routes. Instrumented route lookup, permission, data, and HTTP counters must remain zero for no-read callers. Read users must still pass upstream authorization; a denied or unsupported target must not execute its controller. Unit tests additionally cover provider discovery counts, missing providers, version diagnostics, input validation, and the unchanged status `manage_options` gate (including a custom user without `read`).

Full MCP E2E also runs `site-kit-mcp-runner.php` through real HTTP JSON-RPC and temporary Application Passwords. It verifies allowed read-only custom users, allowed fixture-shared Subscribers, denied ordinary Subscribers, and safe response projections for all three abilities. MCP Adapter 0.5.0 rejects no-read users at its default HTTP endpoint with 403 **before ability execution**; that boundary result is recorded separately and is not evidence of the ability-level floor. The direct WordPress and unit cases establish that floor. Results are written under `e2e-artifacts/issue125-permission-summary.json` and `e2e-artifacts/issue125-mcp-summary.json`.

For a separate real-provider inspection, use a disposable installation with the official Site Kit package active and the Site Kit MU-plugin fixture absent. Verify the package using `wp plugin verify-checksums google-site-kit`, then run `wp --user=<test-user> eval-file wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/inspect-site-kit-permissions.php`. The inspector calls only registered GET permission callbacks, records their source file/lines and effective capability checks, and blocks/records any attempted external HTTP. It never dispatches a data callback or needs Google credentials. Do not install the real provider into a running manifest fixture: the manifest creates a placeholder plugin file at the same slug.

Confirmed inspection: WordPress.org Site Kit **1.187.0**, WordPress **7.1**, PHP **8.2**, unconnected single-site setup. Administrator callbacks allowed; ordinary Subscriber callbacks denied; zero data callbacks or HTTP attempts. The [README capability table](../../README.md#google-site-kit-compatibility-abilities) records exact route semantics and tagged sources. This is not a real connected/shared-dashboard or Google API integration test, nor proof of compatibility across historical versions. Reinspect when supported upstream versions or the minimum version change; no dependency pin or support minimum was changed by these tests.

The runner fails early if either dependency is not active. Coexistence assertions seed SEOPress meta on a post that receives Yoast-backed updates and verify those Yoast paths do not mutate SEOPress meta; separate SEOPress assertions verify SEOPress-specific write and read paths.

Routine QA uses reviewed WP-CLI, MCP Adapter, SEO-plugin, and Plugin Check pins from `.github/compatibility-versions.json`. WP-CLI and adapter downloads must pass digest verification before execution/installation. Candidate overrides must supply the matching digest; do not reuse the baseline digest for a different release. The scheduled compatibility workflow explicitly opts into floating dependencies and records the versions it actually tests.

Set `MCP_CRUD_ENDPOINT` only when you need the secondary CRUD runner to target a non-default MCP Adapter endpoint. By default it uses `http://localhost/wp-json/mcp/mcp-adapter-default-server` from inside the WordPress container.

PowerShell users can run:

```powershell
$env:COMPOSE_PROJECT_NAME = "webmastery-qa-$([guid]::NewGuid().ToString('N').Substring(0, 12))"
$env:MYSQL_PORT = '0'
$env:WORDPRESS_PORT = '0'
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
```

Unix, macOS, and Git Bash users can run:

```bash
scripts/qa-local.sh --contract
scripts/qa-local.sh --e2e
```

## PR comment coverage summary

The E2E PR comment includes:

- registered `webmastery-site-toolkit-for-mcp/*` ability count
- manifest-covered ability count
- manifest test case count
- negative permission case count

These counts make it visible when an enhancement adds new ability coverage.

Full E2E artifacts also include `mcp-crud-summary.json` for the secondary protocol-level CRUD QA phase.

GitHub Actions retains available JSON summaries on successful runs as well as failures, with bounded retention. Fork/read-only bot PRs use job summaries without requiring a write token for comments. `Docker QA gate` accepts skipped Docker jobs only when successful change detection explicitly identifies a non-runtime change.
