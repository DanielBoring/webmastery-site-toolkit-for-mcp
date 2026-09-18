# Ability Contract and Full MCP E2E QA

For the full repository QA posture, including static checks, unit tests, release checks, and GitHub Actions trigger policy, see [`docs/qa-strategy.md`](../../docs/qa-strategy.md).

The Docker QA suite has two layers:

1. Ability Contract QA is ability-driven. Every registered `webmastery-site-toolkit-for-mcp/*` ability must be represented in `tests/e2e/abilities-manifest.json`.
2. Full MCP E2E QA uses real MCP Adapter HTTP JSON-RPC requests against `/wp-json/mcp/mcp-adapter-default-server` to prove a remote MCP client can create, read, update, and delete content through the adapter transport.

Current coverage is 75 base registered abilities plus 5 generated abilities per eligible custom post type. The E2E runner registers two fixture custom post types, so the manifest covers 85 abilities (see the manifest validation script's runtime summary for the current test case count), including custom post type discovery and CRUD permission coverage for two capability maps, object/status-aware post, page, CPT, media, and SEO score list filtering, private-status and bulk-publish denial cases, sensitive identity-field absence checks, taxonomy get/update permission coverage, post and page listing response-shape assertions, expanded Yoast and SEOPress metadata write coverage, controlled Google Site Kit status/module/permission/PageSpeed route fixtures with shared-dashboard, denial, privacy, and same-site URL coverage, the bulk post abilities `webmastery-site-toolkit-for-mcp/bulk-trash-posts` and `webmastery-site-toolkit-for-mcp/bulk-publish-posts`, the revision abilities `webmastery-site-toolkit-for-mcp/list-revisions` and `webmastery-site-toolkit-for-mcp/restore-revision`, the post meta abilities `webmastery-site-toolkit-for-mcp/get-post-meta`, `webmastery-site-toolkit-for-mcp/update-post-meta`, and `webmastery-site-toolkit-for-mcp/delete-post-meta`, the block editing abilities `webmastery-site-toolkit-for-mcp/list-content-blocks`, `webmastery-site-toolkit-for-mcp/patch-content-block`, and `webmastery-site-toolkit-for-mcp/patch-post-content` with targeted post, page, CPT, permission-denial, and unsupported-type coverage, the comment interaction abilities `webmastery-site-toolkit-for-mcp/reply-comment` and `webmastery-site-toolkit-for-mcp/update-comment`, the media sideload ability `webmastery-site-toolkit-for-mcp/upload-image`, the content hygiene abilities `webmastery-site-toolkit-for-mcp/list-orphaned-media`, `webmastery-site-toolkit-for-mcp/list-posts-no-featured-image`, and `webmastery-site-toolkit-for-mcp/list-stuck-scheduled`, the site introspection abilities `webmastery-site-toolkit-for-mcp/get-site-info`, `webmastery-site-toolkit-for-mcp/get-user-info`, and `webmastery-site-toolkit-for-mcp/get-environment-info`, the user access audit ability `webmastery-site-toolkit-for-mcp/user-access-audit`, the Yoast score and metadata abilities `webmastery-site-toolkit-for-mcp/get-seo-scores`, `webmastery-site-toolkit-for-mcp/get-readability-scores`, and `webmastery-site-toolkit-for-mcp/get-yoast-metadata`, the SEOPress metadata ability `webmastery-site-toolkit-for-mcp/get-seopress-metadata`, the webmaster verification ability `webmastery-site-toolkit-for-mcp/webmaster-verification-status`, the database health ability `webmastery-site-toolkit-for-mcp/database-health`, the performance status ability `webmastery-site-toolkit-for-mcp/performance-status`, the backup status ability `webmastery-site-toolkit-for-mcp/backup-status`, plus the plugin abilities `webmastery-site-toolkit-for-mcp/list-plugins`, `webmastery-site-toolkit-for-mcp/plugin-audit`, `webmastery-site-toolkit-for-mcp/activate-plugin`, and `webmastery-site-toolkit-for-mcp/deactivate-plugin`.

`update-cpt-mcp-book` regression coverage (issue #107) proves taxonomy assignment is pre-validated before `wp_update_post()` writes anything: each denial case (a nonexistent taxonomy, a registered taxonomy the actor lacks `assign_terms` capability for, and a mixed payload combining one allowed and one forbidden *registered* taxonomy alongside the existing allowed-plus-nonexistent case) submits a full `title`/`content`/`status`/`slug`/`taxonomy_terms` payload, and the paired read confirms the fixture's title, content, status, slug, and taxonomy terms are all unchanged. A second fixture taxonomy, `wstm107_restricted_shelf`, is registered only for `mcp_book` and granted to no role, so it is always forbidden and exercises the mixed allowed/forbidden registered-taxonomy path independently of the nonexistent-taxonomy case. The fixture post used for these cases (`wstm107_book_id`) is created with an explicit, deterministic slug so the unchanged-slug assertions are stable.

## Diagnostic configuration and privacy regressions

The manifest retains existing diagnostic success/role cases and adds both request/configuration scheme mismatches, admin-only TLS, all six database query error contexts, and explicit lower-privilege field absence. `setup.diagnostics` installs scoped option/request/query fixtures and restores them in `finally`. `assert_diagnostic_findings` matches a check's bucket and label without relying on its array index or unrelated findings. Intentionally failed queries suppress database errors only within the disposable fixture; production logging is unchanged.

For focused baseline/fixed evidence, run `wp eval-file tests/e2e/diagnostics-runner.php` from the plugin directory in an isolated, disposable WordPress installation with the plugin active, users `admin`, `editor_test`, and `subscriber_test`, and a fixture table named with the current prefix plus `wstm111_plugin_data` (`id int PRIMARY KEY, value varchar(20)`, one fixture row). The runner returns JSON with individual predicates, responses, source/runner hashes, SQL read traces, outbound-call counts, and before/after hashes of options, posts, postmeta, users, and usermeta. It exits nonzero if any predicate fails. Run the **same runner** against baseline and fixed production sources.

The default mode exercises direct callbacks and the real ability wrapper, malformed/case-varied home options, permissions, all six real failed-query contexts, successful counters, and unchanged prefixed table identifiers. Direct callbacks are called as admin; permission denials use the actual permission callback and wrapper, not a claim that `execute()` alone authenticates callers. The core wrapper returns `ability_invalid_permissions` while these permission callbacks retain `forbidden`.

For authority-syntax regressions, run `wp eval-file tests/e2e/diagnostics-authority-runner.php` with the same prerequisites. It retains the unchanged original direct-runner report and adds the shared `tests/fixtures/diagnostics-authority.json` cases through direct callbacks and real wrappers, including read-only snapshots, response shape, and zero-outbound checks. Use the same runner against both production revisions. The syntax guard checks percent triplets and authority grammar, including bracketed IP literals; it deliberately does not validate DNS/IDN normalization, routability, or every path/query character. Valid local, Unicode/IDN, and percent-escaped configurations must retain their configured HTTP/HTTPS classification.

Use argument `debug` in separate PHP processes with `WP_DEBUG_LOG` disabled, `true`, custom inside/outside-content paths containing JSON/HTML punctuation, and a neighboring `.htaccess` fixture. Enabled logging must warn that access is unverified; disabled logging must still pass. Use argument `home` with an HTTPS `WP_HOME` constant to exercise WordPress's normal option filter. Do not put fixture configuration or tables on a real site.

HTTPS request variables in these tests are simulations, not actual TLS, certificate, reachability, or redirect tests. Run authenticated MCP HTTP checks separately when changing these findings. WordPress 6.9/7.1 on PHP 8.2 covers the WordPress floor, not PHP 8.0; the PHP floor requires its own runtime. Table prefix/plugin-table redaction remains deferred for compatibility review.

## Backslash persistence regressions

The `wstm122` manifest cases cover post/page create/update SEO metadata, media upload/update title/caption/alt text, and the already-correct direct structured metadata path. Assertions include repeated/trailing backslashes, escaped quotes, regex-style JSON text, sanitized HTML/text, and denied updates with unchanged stored metadata. Uploads use the existing in-process HTTP image fixture; they do not download a live image or relax production URL checks. The HTTP CRUD runner also asserts metadata backslashes on both post creation and update. Existing ordinary content/backslash and allowed/denied cases remain in place.

## Parent-assignment regression proof (#106)

Normal manifest QA retains its 85 registered abilities and all existing cases.
Parent cases add a page-limited actor, allowed and denied assignments, ordinary
draft create/update controls without scheduling, and nonhierarchical CPT
positive-parent rejection and zero-parent compatibility.

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
