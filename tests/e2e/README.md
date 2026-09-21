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

## Destructive-operation safety coverage (3.0)

The five guarded abilities retain their original success and permission oracles,
now with `confirm:true`. Added manifest cases reject missing/false/string/number/
null confirmation, non-boolean optional flags, and 101 raw IDs (including
duplicates); 100 duplicates, mixed/all-failed previews, and known featured-image
refusal include persisted-state assertions. Unit tests separately pin callback
reasons, exact schema bounds, canonical per-ID failures, query failure with force,
and unchanged capability/trash behavior. Existing error-contract, taxonomy, and
trash runners retain all earlier assertions.

`destructive-safety-runner.php` is an **opt-in standalone proof**, not yet a
replacement for the shared orchestration. Run it only after obtaining ownership
of a disposable WordPress runtime. No Docker execution is implied by unit/static
results. Before any fixture mutation or application-password creation it requires
both `WSTM116_DISPOSABLE=1` in the CLI environment and
`define('WSTM116_DISPOSABLE_RUNTIME', true);` in that runtime's configuration.
An existing `wstm116_control` option is a collision, never something to overwrite.

Install `destructive-safety-http-fixture.php` as an MU loader for HTTP evidence.
For individual tools, install the existing disposable `error-contract-fixture.php`
server fixture too; it exposes the real `/wp-json/wstm118/tools` catalog. The
runner reuses `metadata-transport.php` to discover actual advertised tool names
and parse the real Adapter 0.6.1 wire shape. Error results require `isError:true`
and one canonical JSON text block; absent/null `structuredContent` means no
payload, not a structured error object.

Inside the already-owned disposable container, set `WSTM116_BOUNDARY` to each
of `direct`, `ability`, `http`, and `individual`, set `WSTM116_SOURCE_SHA` to the
exact tested commit, and set `WSTM116_ARTIFACT` to a **new** JSON filename in an
existing writable artifact directory. Invoke:

```bash
php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/destructive-safety-runner.php
```

Repeat in fresh enabled/disabled-trash PHP runtimes; a changed environment
variable cannot override an already-defined `EMPTY_TRASH_DAYS`. Both the CLI
and HTTP runtime must have the same trash configuration. Keep raw successful
and failed artifacts; do not replace a failed invocation with a later pass.

The runner distinguishes callback permission outcomes from execution denial;
compares preview summaries to real writes; snapshots posts, postmeta, terms,
termmeta, taxonomy relationships, cron, and owned upload bytes; and records
mutation hooks. It probes featured-image, literal URL, GUID, unused media,
forced known-reference deletion, forced scan failures, and final object denials.
HTTP fault injection and observers are authenticated-actor/request scoped and
return a unique nonce attestation through the owned control option.
Cleanup verifies absence of owned posts/meta/scheduled events, terms/meta,
uploads, users/application passwords, and the control option, retaining failure
evidence even when cleanup fails. Remove the installed MU loaders and runtime
opt-in configuration when the leased disposable runtime is retired.

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

This schema checkpoint retains all 563 preexisting manifest labels and inputs:

| Acceptance change | Cases | Exact new expectation |
| --- | ---: | --- |
| Combined metadata cases | 23 | Native `invalid_input` / `ability_invalid_input`; all existing state/metadata assertions retained |
| Nonhierarchical CPT parent presence | 8 | Native `invalid_input` / `ability_invalid_input`, including zero and Administrator cases; no persisted change |
| New enum/type/unknown-key and denied-role controls | 16 | Exact canonical errors, never sanitizer-coerced success |
| Total at this checkpoint | 579 | 85 abilities; 300 negatives; every registered ability represented |

Historical labels mentioning allowed zero-parent behavior intentionally remain
unchanged for provenance; their assertions now reject that closed property.
The existing 151-case parent matrix is retained. Metadata runtime coverage
retains 1,176 rejects and four positive workflows per boundary; native reason
is now exactly `ability_invalid_input`, while direct/gateway/individual
presence rejection stays exactly `metadata_requires_separate_call`.
The manifest validator and its mutation test enforce that distinction.

`input-schema-runner.php` and `input-schema-fixture.php` are **unexecuted,
opt-in runtime scaffolding**, not completed WordPress or wire acceptance.
Do not start Docker or local WordPress without the coordinator's explicit
exclusive runtime lease. Shared orchestration and the error-contract runner
are intentionally untouched. Final integration must preserve the newer error
floor, metadata authorization, destructive guards, and performance projections.
The synthetic missing-schema error fixture needs post-registration fault
injection because legitimate input-free abilities now receive an empty schema.

Inside that later owned disposable runtime, install an MU **loader requiring
the repository's** `input-schema-fixture.php` (do not copy it away from its
relative includes), enable `WSTM126_DISPOSABLE_RUNTIME` with literal `true`
in both CLI/HTTP configuration, and load the normal CPT fixture plus the
existing individual-tool server fixture. Before bootstrap the runner requires
exact `WSTM126_DISPOSABLE=1`, `WSTM126_BOUNDARY` set to each of `direct`,
`permission`, `ability`, `http`, and `individual`, and `WSTM126_ARTIFACT`
pointing to a new file in an existing writable artifact directory. Set
`WSTM126_SOURCE_SHA` to the exact tested commit. Invoke the standalone runner:

```bash
php /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/input-schema-runner.php
```

The fixture counts only SQL and capability hooks inside registered callbacks,
not bootstrap/HTTP authentication work. Malformed cases also require unchanged
posts/meta/terms/relationships/cron snapshots, zero mutation hooks, and the
exact callback observation count for that boundary; missing evidence fails.
Valid denied-role and hierarchical detach/omission controls calibrate real
authorization and writes. The runner uses unique owned actors/posts, refuses
an existing observation option, retains raw inputs/results/wire/state/counters,
records cleanup failures, and removes its own actors, credentials, objects,
and option. Keep failed artifacts as well as later passes. Remove the MU
loader and runtime opt-in when retiring the leased runtime.

Local unit/static results do not establish the source-derived HTTP expectations.
Actual direct/native/raw-permission/gateway/individual proof, runtime coverage
audit, and integration with concurrent changes remain pending. Optional output
schemas are explicitly deferred; the [migration guide](../../docs/3.0-migration.md#strict-input-schemas-and-raw-permissions)
documents open maps, validation scope, and exact failure-layer differences.

## Comment moderation regression coverage

`comments-fixture.php` adds `wstm105_*` fixtures and comment-specific checks. Its `wstm105_moderator` actor has the actual `comment_moderator` role with only `read` and `moderate_comments`. Cases cover all four writes, optional update statuses, Author moderation-floor denials, mapped-CPT allowed/denied controls, own-draft moderation, Administrator access, orphan comments, and missing/nonpositive IDs. Existing Editor cases and every landed main manifest case remain unchanged; runtime registrations remain the coverage authority.

`assert_comment_state` requires `comment_id`, `content`, and `status`. It reloads the comment after execution and checks both persisted fields, even when the expected result is failure or the response assertion already failed. Security QA requires this evidence for the four moderator-only denials.

The contract runner also invokes both registered callbacks directly for authorization, malformed input, capability filters, and core orphan behavior. Missing objects retain their execute-callback errors rather than becoming permission failures. Expected core permission notices are not suppressed; only notification emails for deliberately orphaned fixtures are disabled.

The CLI-only `comments-runner.php` adds 308 cases across direct execution (104), the actual WordPress ability wrapper (104), and authenticated MCP HTTP (100). It retains raw HTTP tool results, exact error messages/codes, effective capabilities, per-comment content/status, and before/after hashes of all comment and commentmeta rows. Failed calls must leave both tables unchanged. Global-comment controls cover both direct execution and the ability wrapper; the contract fixture also calls the permission callback with a populated global comment and a zero ID. Negative-existing-ID controls cover all three boundaries. HTTP fixture application passwords are revoked. The runner refuses web access before WordPress bootstrap; artifact write failures are fatal.

Contract and HTTP lanes run their respective boundaries via `WSTM105_BOUNDARY` and retain `comments-direct.json`, `comments-ability.json`, and `comments-http.json` for seven days, including failed runs. For baseline comparison, use this identical runner with `WSTM105_MODE=baseline` against the old registered callbacks on a disposable site, and a separate `WSTM105_ARTIFACT` path. Baseline mode records the old authorization/global-comment bugs rather than asserting the fix; malformed direct calls without a stable historical contract are fixed-only. Compare the 152 cases marked `compatibility` without normalizing away raw envelopes or error codes. Never install old callbacks on a shared/live site, and preserve baseline/failed calibration artifacts outside `e2e-artifacts` before a fresh suite clears it.

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

## Ranked coverage follow-up (#120)

This is a **partial, test-only** follow-up, not closure of the coverage umbrella.
The manifest keeps every existing success and status/no-write assertion and adds
the following requirement-to-test mapping:

| Group | Permanent coverage |
| --- | --- |
| 1: permanent media deletion | `wstm120 delete-media` Subscriber and Author object denials; the Author has `upload_files` but cannot `delete_post` on the Editor-owned attachment. Original Author deletion success remains. The owner reads the retained attachment afterward. |
| 6: Contributor boundaries | A real `contributor_test` user, role/ID placeholders and validator allowlist; own-draft create/update/trash with persisted-state assertions, all three publish/private/future create and transition denials, own published-delete denial, and unrelated draft read/update/delete denials. Dedicated fixtures do not reuse earlier write-positive targets. |
| 7: private/trashed direct getters | `wstm120 get-post` / `get-page` cover owners and other users, allowed Editors and lower-capability owners, denied Subscribers/Contributors, and original draft versus published trash status. Effective object capabilities and `_wp_trash_meta_status` are asserted where relevant. |
| 9: pure helper characterization | `PostsCharacterizationTest` covers numeric path grammar, by-reference nested replacement/no partial mutation, heading section boundaries/ambiguity, metadata recursive depth and encoded size/error limits. `SiteKitUrlTest` covers host case, effective ports, schemes, userinfo and fragments. Existing media URL and raw exact-match helper tests remain unchanged. |
| 10: remaining negative paths | Explicit callback/wrapper permission errors for media reads, SEO analysis and four list abilities, with allowed counterparts and response/hidden-field assertions. |

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
`ability_invalid_permissions`. Contributor status updates are an intentional
legacy exception: object permission succeeds, then the execute callback returns
the exact existing `success: false` / string `error` envelope. Required destructive
permission cases cannot be replaced with not-found/invalid-input failures.
Validator mutation tests protect this distinction and the no-write assertions.

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

Groups 2/3/8 retain their separate trash-safety, taxonomy and patch-HTML evidence.
Comment-object group 4 belongs to #105/#157; registered metadata group 5 belongs
to #110/#159. The separate 3.0 batch/SEO suite above covers the remaining #110
contract; none of these unit case totals establishes full #120 completion.
Runtime registration coverage must
still prove all 85 fixture abilities against `wp_get_abilities()`; static manifest
validation alone cannot make that claim.

## Diagnostic configuration and privacy regressions

The manifest retains existing diagnostic success/role cases and adds both request/configuration scheme mismatches, admin-only TLS, all six database query error contexts, and explicit lower-privilege field absence. `setup.diagnostics` installs scoped option/request/query fixtures and restores them in `finally`. `assert_diagnostic_findings` matches a check's bucket and label without relying on its array index or unrelated findings. Intentionally failed queries suppress database errors only within the disposable fixture; production logging is unchanged.

For focused baseline/fixed evidence, run `wp eval-file tests/e2e/diagnostics-runner.php` from the plugin directory in an isolated, disposable WordPress installation with the plugin active, users `admin`, `editor_test`, and `subscriber_test`, and a fixture table named with the current prefix plus `wstm111_plugin_data` (`id int PRIMARY KEY, value varchar(20)`, one fixture row). The runner returns JSON with individual predicates, responses, source/runner hashes, SQL read traces, outbound-call counts, and before/after hashes of options, posts, postmeta, users, and usermeta. It exits nonzero if any predicate fails. Run the **same runner** against baseline and fixed production sources.

The default mode exercises direct callbacks and the real ability wrapper, malformed/case-varied home options, permissions, all six real failed-query contexts, successful counters, and unchanged prefixed table identifiers. Direct callbacks are called as admin; permission denials use the actual permission callback and wrapper, not a claim that `execute()` alone authenticates callers. The core wrapper returns `ability_invalid_permissions` while these permission callbacks retain `forbidden`.

For authority-syntax regressions, run `wp eval-file tests/e2e/diagnostics-authority-runner.php` with the same prerequisites. It retains the unchanged original direct-runner report and adds the shared `tests/fixtures/diagnostics-authority.json` cases through direct callbacks and real wrappers, including read-only snapshots, response shape, and zero-outbound checks. Use the same runner against both production revisions. The syntax guard checks percent triplets and authority grammar, including bracketed IP literals; it deliberately does not validate DNS/IDN normalization, routability, or every path/query character. Valid local, Unicode/IDN, and percent-escaped configurations must retain their configured HTTP/HTTPS classification.

Use argument `debug` in separate PHP processes with `WP_DEBUG_LOG` disabled, `true`, custom inside/outside-content paths containing JSON/HTML punctuation, and a neighboring `.htaccess` fixture. Enabled logging must warn that access is unverified; disabled logging must still pass. Use argument `home` with an HTTPS `WP_HOME` constant to exercise WordPress's normal option filter. Do not put fixture configuration or tables on a real site.

HTTPS request variables in these tests are simulations, not actual TLS, certificate, reachability, or redirect tests. Run authenticated MCP HTTP checks separately when changing these findings. WordPress 6.9/7.1 on PHP 8.2 covers the WordPress floor, not PHP 8.0; the PHP floor requires its own runtime. Table prefix/plugin-table redaction remains deferred for compatibility review.

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
