# Shared-helper boundaries

This is the behavior-preserving #119 consolidation map for the 17 ability groups.
The issue's old line numbers and proposed signatures are not the current API.
Its historical ability names, strict schemas, annotations, permission diagnostics,
successful fields, metadata eligibility and authorized totals remain exactly
reconstructible. The additive #167 integration below intentionally changes list
pagination/projection, not the authorization policies or historical oracles.

## Requirement rows

| #119 row | State | Owner and deliberate limits |
| --- | --- | --- |
| 1. Canonical errors | Already shared | `Webmastery_MCP_Response`; preserve `local_error()` versus canonical/provider boundaries and exact reasons/messages. |
| 2. Capability closures/admin checks | Equivalent checks consolidated | `Webmastery_MCP_Permissions::check/cap/admin`; public facades remain. Site Kit shares only its local floor, not upstream permission decisions. |
| 3. Object permission factories | Equivalent factories consolidated | `Permissions::object` handles exact-type lookup, then effective object capability, with caller-supplied missing/denied messages. Creation, publication, parent, revision, featured-image, bulk, metadata, comment and taxonomy checks retain their specific policies. |
| 4. Readable filtering/querying | Consolidated, then bounded by #167 | `Post_Access::query_readable` delegates candidate selection/priming to `List_Query::window`, authorizes at most P candidates and returns explicit continuation without totals. Posts/CPT status maps, attachment-type plus `edit_post`, and SEO real-object/key authorization remain distinct. |
| 5. List arguments/author restriction | Shared policy consolidated | `Post_Access::list_args/restrict_author`; post/CPT defaults, search, requested author and ordering share a builder. Media's inherit/MIME/fixed ordering, SEO's score/date/type filters, and hygiene's diagnostic queries remain local. |
| 6. Post/CPT normalization | Common fields and #167 projection | `Post_Content::normalize($post, $fields = 'full')` preserves stored values and existing untrusted markers; list facades default to strict summary while get/write responses remain full. Posts append categories/tags and CPTs append taxonomy terms. Revisions, media, scores and hygiene retain their own contracts. |
| 7. Scheduling | Already shared | `Webmastery_MCP_Post_Scheduling` with existing `Post_Parent` checks; do not duplicate or fold timezone/publication rules into generic input helpers. |
| 8. Active plugin inventory | Equivalent diagnostic inventory already shared | `Webmastery_MCP_Plugins::active_basenames` for backup/performance. SEO and Site Kit readiness uses loaded providers/core APIs/version information, not just option membership; intentionally not unified. |
| 9. SEO metadata tables | Consolidated | `Post_Meta::yoast_keys/seopress_keys/writable_keys`; inspection order retained, exactly 36 original protected write keys/types. Yoast SEO/readability scores and SEOPress news/video sitemap flags remain inspection-only entries, never new write grants. |
| 10. Pagination clamps | Consolidated without changing policy | `Input::per_page/page/pagination` supports defaults/maxima and optional lower bound. Raw Comments/Users/Taxonomy and pre-count post query arguments keep their old maximum-only clamp via `minimum = null`; strict registered schemas remain authoritative. |
| 11. Exactly-once slashing | Post/metadata boundary consolidated | `Post_Writes::insert/update/meta` accepts sanitized, unslashed values and applies one `wp_slash` immediately before the core write. Comments keep their distinct comment API boundary. No blanket recursive unslash, changed sanitization or comment/post API conflation. |
| 12. Array-style enforcement | Enforced for packaged production PHP | `Generic.Arrays.DisallowLongArraySyntax` requires `[]` literals throughout `includes` and the plugin bootstrap. Installed PHPCBF converted only 88 long literals in five files; all 1,223 existing short literals remain untouched. Array types, offsets and destructuring are not rewritten. |

These distinctions are not unresolved duplicate implementations of the same
policy. Row 8 provider-readiness merger and attempts
to genericize domain-specific policies remain deliberately unimplemented.

### Array syntax and historical provenance

Short array literals are supported throughout the PHP 8.0+ support range and
were already the predominant production style (1,223 short versus 88 long
literals). Choosing `[]` minimizes churn; the WordPress standard remains active
with its existing short-array prohibition excluded, while the explicit Generic
rule rejects long literals. This is a documented repository preference, not a
claim that WordPress Coding Standards itself prefers short arrays. Tests,
scripts and vendor code are outside the production PHPCS file scope.

`array-syntax-transition.json` adds 102 exact reversible hunks for five production
files before the unchanged #167, #127 and #119 sealed historical transitions.
Its independent seal binds raw and LF-normalized hashes, Git blobs, paths and
predecessor seals. The #167 source and dependency entry points reverse this layer
before comparing their existing hashes; historical ledgers are not regenerated.
Live fixture callbacks still execute converted production code, never restored
historical code. Targeted tests compare complete PHP token streams modulo
literal syntax and whitespace, reject unrelated source/forged proof drift,
retain prior chain safeguards and exercise the configured lint rule.

The opt-in floor selector adds a separate exact outer
`floor-selector-transition.json` layer for seven QA/test consumers and eleven
dependencies before the unchanged syntax and #167/#127/#119 layers. Source
selection does not change production abilities or grant runtime authority.
Further bound admission/controller changes require another reviewed outer
reversal, never regenerated historical seals.
The composed `bounded-admission-transition.json` supplies that next exact
reversal for eight QA consumers/bridge seams and their query/supervisor
dependencies. Its projection precedes the unchanged floor and earlier layers.
The separate observer/import-test reversal retains their accepted test history;
neither layer changes packaged production or grants runtime authority.

## Public helpers and consumer policy

| Public boundary | Responsibility |
| --- | --- |
| `Permissions::check(string $cap)` | Immediate uncached effective capability check; exact trusted local denial. |
| `Permissions::cap(string $cap)` / `admin()` | Deferred simple closure; no authorization at registration. |
| `Permissions::object(string $type, string $input_key, string $cap, string $not_found, string $forbidden)` | Deferred input-ID lookup/type check followed by one uncached object capability check. |
| `Post_Access::can_read($post, $read_cap, $edit_cap, $delete_cap)` | Published/private reads, trash deletes, other statuses edits; caller supplies CPT maps. |
| `Post_Access::filter_ids(array $ids, callable $can_read)` | Preserve candidate order and use the caller's actual object/key policy. |
| `Post_Access::query_readable(array $args, int $page, int $per_page, callable $can_read, callable $normalize): array\|WP_Error` | Fixed P+1 candidate window, P authorizations, shared normalization and explicit continuation; propagate candidate/priming failures, never false EOFs or filtered totals. |
| `Post_Access::paginate(array $ids, int $page, int $per_page, callable $normalize)` | Count authorized IDs before slicing/normalizing. Disappearing items do not recompute totals; zero authorized results with a positive page size have zero pages. |
| `Post_Access::list_args(array $input, string $type, string $edit_others_cap)` / `restrict_author(array $args, string $edit_others_cap)` | Common post/CPT arguments and effective author restriction, not permission grants. |
| `Post_Content::normalize($post, string $fields = 'full')` | Shared stored fields and existing marker contract; summary removes content and its marker, full retains exact content. No taxonomy or authorization policy. |
| `Post_Meta::yoast_keys()` / `seopress_keys()` / `writable_keys()` | Single-source inspection keys and protected-write normalization types; no object/key authorization or provider-read-value coercion. |
| `Post_Writes::insert(array $args)` / `update(array $args)` / `meta(int $id, string $key, $value)` | One slashing layer and unchanged core return/error identity; no authorization or sanitization. |
| `Input::per_page(array $input, int $default_per_page = 20, int $maximum = 100, ?int $minimum = 1)` / `page` / `pagination` | Pure existing clamps, not substitutes for strict boundary validation. |

Do not pass private method arrays across class scope as generic callables.
Consumers use scoped closures to retain access to private normalizers/policies.
Test loaders evaluate actual helper source in the same namespace as the
WordPress spies; a global helper or literal global function-name callback can
bypass those spies.

All capability and inventory reads remain uncached. Metadata still checks
registered global/subtype policies and effective WordPress capability filters.
The compatibility default for supported unregistered SEO keys is not a bypass
for those filters. Read normalization of empty/null/boolean/score values remains
in SEO; standalone write sanitization now lives in Post_Meta.

## Ability-group audit

The actual-source registration fixture covers Posts, CPTs, Taxonomy, Comments,
Media, Users, Site Info, Health, Database Health, Performance, Backups, Content
Hygiene, Security, SEO, Site Kit, Webmaster Verification and Plugins. Its two
fixture CPTs produce 85 registered abilities. Every one retains a manifest case,
a success control and a forbidden role/capability control. The shared-helper
extraction preserved all 589 historical cases, including 307 negative cases;
the composed #166/#167 manifest now has 601 cases, including 313 negative cases.
The added controls do not rewrite historical-ledger inputs, oracles, ordering
or counts.

Health, Security, Site Info and Webmaster Verification retain their previously
shared simple checks. Plugin operations retain network/admin policy. Object
comments and taxonomy writes retain capability ordering and term/comment
meta-capabilities. Site Kit retains delegated REST checks in both public entry
paths. Hygiene does not adopt the full-post response/query policy.

## Completed #127 extraction

| Cohesive owner | Responsibility | Registrations |
| --- | --- | --- |
| `Post_Access` | Status access, authorized-ID queries and list/author policy; domain target lookup and deferred creation/publication/parent, featured, metadata, revision and content permission orchestration. Simple/object factories delegate to `Permissions`. | None; uncached policy helpers. |
| `Post_Meta` | SEO tables, key validation/eligibility, effective key authorization and temporary compatibility filter, combined-input rejection and value/response normalization. Persistence delegates to `Post_Writes`. | `get-post-meta`, `update-post-meta`, `delete-post-meta` |
| `Bulk_Posts` | Raw ID bounds, confirmation/preview, deduplication, per-item authorization/status/errors and summaries. | `bulk-trash-posts`, `bulk-publish-posts` |
| `Post_Revisions` | Revision normalization, parent authorization via Access and unchanged core revision API calls. | `list-revisions`, `restore-revision` |
| `Featured_Image` | Post/page authorization via Access, existing attachment-type/image eligibility and unchanged core thumbnail APIs. No new attachment capability requirement. | `set-featured-image`, `remove-featured-image` |
| `Content_Patch` | Raw content/block hashes, path traversal, nested replacement, heading/exact algorithms; target authorization via Access, localized replacement KSES and shared writes. | `list-content-blocks`, `patch-content-block`, `patch-post-content` |
| `Posts` | Slim dispatcher, post/page CRUD and taxonomy extensions over `Post_Content::normalize`. | Existing six CRUD/list registrations for each of post and page. |

`Posts::reject_combined_metadata(array $input): ?array` remains available to the
Input boundary and `Posts::can_read_post_meta_key(int $post_id, string $key): bool`
available to SEO as stable forwarding facades. `Posts::bulk_input_error` retains
its strict diagnostics through Bulk_Posts. `Posts::normalize` is public for
feature response reuse, retaining the post-only category/tag fields. Do not
resurrect combined SEO aliases: current main rejects them
before mutation, so the old issue's alias-block extraction proposal is obsolete.
Unit/reflection consumers use their actual owners. Bootstrap loads dependencies
and all feature owners before Posts inside the ability hook; package-map guards
verify their exact bytes. Response, Scheduling and Parent remain shared.

`PostsExtractionTest` compares all 24 registrations and permission factories
against the reconstructed #119 source, and binds all 61 original
non-dispatcher method bodies to seven final owners (57 moved, four retained).
Only owner qualification and required public visibility change inside these
bodies. Pre-move patch characterization and owner-specific tests retain dotted
path grammar, by-reference nested replacement, heading boundaries, raw exact
matching and metadata depth/encoded-size limits.

## #166 / #167 and provenance integration

#167 is retargeted onto the completed #119/#127 owners. `Post_Access` bounds
post/page/CPT/media/score candidates; `Post_Content` owns common projection;
`Post_Meta`, `Post_Revisions` and `Content_Patch` own their marker composition.
The hygiene reference scan retains its prepared candidate-specific batches and
fail-closed deletion semantics. P+1 candidates, P authorizations, 5P+10
capability calls, 3P+12 SQL queries (plus ceil(2P/50) for orphans), 64 KiB
controlled payload and 64 MiB peak allocator budgets remain unchanged.

The composed #166 delta retains the exact b3 `Untrusted` helper, entry-point,
all 28 bounded production dependencies and the frozen #167/#127/#119 JSON seals.
It adds absent stage/pipeline and safe admission diagnostics, not a second
production marker implementation. Actual-owner fixtures prove full raw values,
default summary omission and retained-field markers together; the HTTP plan
also has distinct explicit-summary cases. No production-source reversal is
needed because none of the bound production bytes changes.

`untrusted-bounded-manifest-transition.json` adds an exact typed test-only layer
before the existing bounded/schema/marker projections. It reverses only 188
new marker-assertion case changes to recover the exact 601-case bounded view,
and separately reconstructs the historical 589-case marker view. All previous
inputs, roles, values, absence/no-write assertions, 12 added bounded cases and
frozen source ledgers remain intact. The workflow transition similarly recovers
the original pinned custody oracle without replacing its historical hash.
These source proofs do not establish real runtime, lease or GitHub acceptance.

`shared-helper-transition.json` seals the exact reverse hunks from the reviewed
pre-extraction source at `8ea870b` to these helper consumers, including normalized
SHA256 and Git blob hashes, plus helper dependency hashes. Its PHP loader seals
the entire fixture, checks current source/dependencies, reverses only the exact
hunks, then verifies the reconstructed baseline. Historical comments, taxonomy
and scheduling ledgers still compare against their original hashes and execute
current production bodies. Shared-helper units additionally execute the
reconstructed original bodies only in an isolated comparison namespace.

The additive `posts-extraction-transition.json` reverses eight changed/new
runtime files through 24 exact hunks, binds all 61 original non-dispatcher
methods and 26 dependency sources, and restores the exact #119 handoff bytes
before the frozen bridge runs. Its seal is
`2be14f26642f28014a9df9e99df36cb6697520b158938f1b3bad23e9da44c4f5`.
The #119 JSON and seal
`1bdd69f8b930f9c744afe557800eeafd195f30eeaa9b61c9b81fff5267e426d6`
remain unchanged. Consumer, helper, reverse-hunk, owner and historical-hash
forgeries remain fail-closed; no later HEAD substitutes for a historical oracle.

Future changes to a bound consumer/helper must add an explicitly reviewed
provenance transition before this frozen bridge, or maintain an equally exact
reviewed chain to the same historical bytes. Do not replace historical ledgers,
silently accept new hashes, disable the bridge, or regenerate its baseline from
a later HEAD. Preserve source-drift and forged-binding mutation controls.

`bounded-integration-transition.json` is the new outer #167 layer: 13 exact
consumer reverse bindings and 28 current dependencies, including List Query
and Untrusted, restore the immutable final prerequisite snapshot before #127
and #119 run. Historical comparison namespaces explicitly load those restored
prerequisite bodies; current bounded/marker/native tests execute the actual
new owners. Both older JSON fixtures and seals stay byte-identical. Forged
current/baseline SHA256, Git blobs, predecessors, hunks and dependency sources
are rejected, including changes to either newly added primitive.

Local static/unit evidence does not replace authorized real WordPress/MCP,
PHP-floor or original-ZIP runtime checks. No Docker or live-site mutation is
needed for the isolated consolidation tests.
