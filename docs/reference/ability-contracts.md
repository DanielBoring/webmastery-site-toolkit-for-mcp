# Ability contracts

How the plugin's abilities behave for MCP clients: inputs, permissions, paging,
errors, safety interlocks and result fields. This describes current behavior.

- What changed from 2.x, with old/new examples: [3.0 migration guide](3.0-migration.md).
- Setup, roles and the full ability list: the [plugin guide](https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/).
- Hand checks for this behavior on a disposable site: [manual verification](../../tests/e2e/README.md#manual-verification-on-a-disposable-site).
- Every other document: the [documentation index](../00-sdlc-overview.md#document-map).

Ability names below omit the `webmastery-site-toolkit-for-mcp/` prefix unless a
table shows it.

## Contents

- [Calling abilities](#calling-abilities)
- [Errors and response format](#errors-and-response-format)
- [Strict input boundaries](#strict-input-boundaries)
- [Lists: paging and content projection](#lists-paging-and-content-projection)
- [Post metadata and SEO authorization](#post-metadata-and-seo-authorization)
- [Backslashes in writes](#backslashes-in-writes)
- [Comments](#comments)
- [Category and tag permissions](#category-and-tag-permissions)
- [Destructive-operation interlocks](#destructive-operation-interlocks)
- [Targeted content patching](#targeted-content-patching)
- [Image URL uploads](#image-url-uploads)
- [Scheduling](#scheduling)
- [Parent assignments](#parent-assignments)
- [Google Site Kit](#google-site-kit)
- [Untrusted result fields](#untrusted-result-fields)
- [Security and privacy behavior](#security-and-privacy-behavior)

## Calling abilities

The default Adapter `tools/list` lists three gateway tools, not per-ability
hints. Use `mcp-adapter-get-ability-info` to retrieve an ability's metadata.
On individual tools, compare registered `readonly`, `destructive`, and
`idempotent` with Adapter 0.6.1's actual emitted `readOnlyHint`,
`destructiveHint`, and `idempotentHint`. The
[assessment](mcp-adapter-0.7-assessment.md) distinguishes pre-release
annotation proof from release-package validation. Markers travel with result
data in both exposure modes.

## Errors and response format

Registered Webmastery ability failures use `{"success":false,"error":{"code":"not_found","reason":"not_found","message":"Comment not found.","details":{}}}`. The fixed categories are `forbidden`, `not_found`, `invalid_input`, `precondition_failed`, `conflict`, `unsupported`, and `upstream_failed`. Parse `code` and the specific `reason`, never message substrings. Empty `details` is an object. Error normalization preserves successful `success:true,data` payloads; separate metadata projections and additive markers are documented in this reference and in the migration guide.

For MCP Adapter **0.6.1**, owned gateway and individual-tool failures set `isError:true` with the canonical JSON envelope in one text block. Native code/data and structured error content are not retained by the Adapter, so clients must decode that text. The Adapter sets structured content to null internally; actual HTTP responses omit the `structuredContent` key. Clients may accept absence or explicit null, but neither carries a structured error payload. Success through the gateway retains its extra `success/data` wrapper. Foreign plugin results are not intercepted. Check HTTP/JSON-RPC failures separately.

Bulk operations remain non-atomic: top-level `success:true` means the batch was processed, including an all-failed batch. Inspect counts and every `{id,code,reason,message,details}` failure. See the [migration matrix, mappings, examples, and Adapter limitation](3.0-migration.md).

Missing-comment failures now uniformly use code/reason `not_found`; callers without `moderate_comments` still fail at the permission boundary. Invalid/nonpositive IDs are rejected without falling back to a global comment or coercing a negative ID into another target.

## Strict input boundaries

Registered fixed-property object schemas are closed: misspelled or unadvertised
properties fail rather than being ignored. The same schema supplies strict
type, enum, required-field, numeric-range, and array-item/bound checks before
registered execute callbacks and raw permission callbacks. Invalid requests
cannot enter the operation's capability, query, or write branches. Numeric-string
IDs and boolean-like strings/integers therefore report `invalid_input` rather
than being masked as permission denials; genuine authorization failures still
follow core permission redaction.

Schema failures keep code `invalid_input`, reason `ability_invalid_input`, and
the fixed message. Their `error.details` now identifies the first strict
failure, for example `{"field":"$.post_id","reason":"required"}` for
`get-post` with `{"id":2447}`. Detail reasons are `required`,
`unknown_property`, `type`, `enum`, or `bounds` (numeric or array length).
Required fields are checked before supplied properties; correct the reported
failure and consult the advertised schema for allowed values.

Paths begin at `$`, use `.name` for declared schema properties, `[]` for any
array item, and `.*` for unknown or dynamic map keys. Thus an extra property
beside a valid `post_id` reports `$.*`, and an invalid taxonomy item reports
`$.taxonomy_terms.*[]`. Supplied property names, values, array indexes and
provider diagnostics are never copied into these details. Schema names outside
the ASCII identifier form (letter/underscore, then letters/digits/underscores/
hyphens, at most 64 characters) also use `.*`. Paths are at most 243 characters;
`...` indicates that deeper segments were omitted, while validation still
checks the complete input. No allowed-value lists or stored values are returned.

These additive details survive raw registered callbacks, native execution,
gateway and individual MCP tools. Public native validation retains the exact
core `WP_Error` object and its original data; normalize it with
`Webmastery_MCP_Response::from_wp_error()` to obtain the safe details. If only
a core keyword such as `format` or a combinator fails, details remain `{}`.
Diagnostics do not alter schema acceptance, authorization, or defaults.

Use exact enum values (`"DESC"`, not `"desc"` or `"DESC!"`), JSON integers for
IDs, and JSON booleans for flags. Optional fields retain existing defaults when
omitted; explicit `null` is invalid unless advertised. Input-free abilities
accept an empty object or omitted input (native null normalizes to the empty
default), not arbitrary properties. No text sanitization can turn an invalid
enum into a valid one.

Standalone `meta_value` remains a polymorphic JSON value, and CPT
`taxonomy_terms` remains an extensible map of taxonomy names to integer arrays;
registration and assign-term authorization still apply. Unstructured objects
and explicitly open extension maps are not recursively closed. Block attributes
are not constrained by this input guard.

Validation order, Adapter behavior and helper limits are in the
[migration guide](3.0-migration.md#strict-input-schemas-and-raw-permissions).

## Lists: paging and content projection

Authorized content records also carry `untrusted_fields`, a JSON array of
record-relative field names. These are data, not instructions or approval for
later actions. Summary records never mark omitted content; full/get/write
records preserve their stored values and mark the fields actually present.

| Abilities | Pagination / content |
| --- | --- |
| `list-posts`, `list-pages`, `list-cpt-*` | Candidate windows; `fields:"summary"` (default) omits `content`; `"full"` preserves stored content |
| `list-media`, `list-orphaned-media`, `get-seo-scores`, `get-readability-scores` | Candidate windows; existing item fields retained, no content or new `fields` parameter |
| `list-revisions` | Existing capped revision list; summary/full projection as above, no new pagination fields |

The windowed lists return `data.items` (array), `data.page` and `data.per_page`
(integers), and `data.next_page` (integer or null), **not `total` or
`total_pages`**. Fetch the same filters/order with the returned `next_page`
until it is null. An empty or short `items` array is not end-of-list: object
permissions, score-key permissions, and known media references can exclude an
entire candidate window. Do not infer denied counts or exact totals.

`per_page` is a candidate limit of 1-100, not a promise of that many visible
items. Defaults remain 20 (10 for scores); `page` starts at 1. Primary sort and
filters remain, with an ID tie-breaker in the same direction. Access requirements
are unchanged: post/page editing floors and per-status object access, CPT mapped
capabilities, media upload/edit access, and effective object/key access for
scores. Orphan detection batches only the bounded candidates' featured-image and
literal URL/GUID checks, and still fails closed on database errors, even during
forced deletion.

Unrepresentable integer offsets/continuations return `invalid_input` before a
query. Candidate or priming SQL failures return `upstream_failed`, not an empty
end-of-list.

For example, start `list-posts` with `{"per_page":20,"page":1}`. Process the
returned items, then request `{"per_page":20,"page":2}` only if `next_page` is 2.
Use `fields:"full"` explicitly when content is needed. Summary does not return
an empty replacement, sanitize excerpts, or change other stored values; get and
write responses remain full. Invalid `fields` values are rejected by the
registered input contract before the list query.

These are not transactional snapshots: edits can shift offset windows. Database
offset/search and content scanning costs can still grow with site size. See the
[typed migration contract and
limitations](3.0-migration.md#bounded-list-windows-and-summary-projection).

## Post metadata and SEO authorization

`get-post-meta`, `update-post-meta`, and `delete-post-meta` require `edit_post` for the actual object and preserve the existing protected-key eligibility rules. Ordinary Authors can usually operate on their own posts; pages and other authors' posts generally require an Editor, and individual keys may impose additional requirements.

| Standalone ability | Key-level policy |
| --- | --- |
| `get-post-meta` | Requires `edit_post_meta`. An explicit denied key returns `forbidden`; listings omit denied keys. This conservative read policy is specific to this plugin, not a general WordPress read-meta capability. |
| `update-post-meta` | Requires `edit_post_meta` for existing, absent, and unchanged values. Successful response fields and structured-value support are unchanged. The capability choice matches core REST upserts, but denial of unauthorized no-ops is intentionally stricter than REST's same-value shortcut. |
| `delete-post-meta` | Requires `delete_post_meta`, including when the key is absent. Authorized deletion retains the existing `deleted_count` response. |

Registered global/subtype policies and WordPress's effective `map_meta_cap` / `user_has_cap` filters remain authoritative. Supported, genuinely unregistered SEO keys with no key authorization hooks receive only a temporary protected-key default, not an exception to capability filters. Other plugins may explicitly grant primitive metadata capabilities: Yoast's effective edit policy can permit a key whose registration callback returns false, while its delete policy can differ.

**Since 3.0:** post, page, and dynamic CPT create/update requests reject the presence of `meta`, `meta_input`, every `yoast_`/`seopress_` alias, and raw `_yoast_wpseo_`/`_seopress_` fields before post, metadata, taxonomy, scheduling, or save-hook side effects. Empty/null values and unchanged metadata are also rejected; Administrator access does not bypass this rule. Plain content, status, parent, and taxonomy requests retain their existing behavior.

Create a draft without metadata, call `update-post-meta` separately for each exact key, verify every result, then publish with a plain update. These operations are **not atomic**: an earlier authorized write remains if a later key is denied. Keep the draft unpublished on failure; do not retry a combined request. See the [complete alias migration table](3.0-migration.md#metadata-and-seo-authorization).

Separate SEO inspection, analysis, and score abilities also require effective `edit_post` plus `edit_post_meta` for each real object/key before reading it. Denied fields are omitted from raw and normalized metadata and reported in `unavailable_fields`, not advertised as visible plain text. Analysis skips checks that cannot be evaluated; scores filter permission within the bounded candidate window and return no exact totals. Opaque generated Yoast head output is unavailable because extensible generated output cannot be authorized by a fixed key list. URL-only head requests return `unsupported`.

SEO overview samples at most 100 published post/page IDs in ascending ID order. Each missing-field `count` and maximum-20 `ids` list includes only authorized observations; `observed_count` is the authorized sample denominator. `observation_scope.counts_are_sitewide` is false. Zero observations mean no evidence, not a healthy site. Independent native published post/page totals remain available to Administrators.

SEO Analyze Post uses static focus-keyword diagnostics: `Focus keyword found in title.` or `Focus keyword not found in title.` Authorized stored values remain unchanged in `data.metrics.yoast_focus_keyword` and `data.metrics.seopress_focus_keywords`; `seo_provider_focus_source` retains authorized Yoast-first selection with SEOPress fallback. Metadata denials follow the authorization rules above. The data/message separation and additive field markers do not make these values instructions.

SEO Analyze Post's `data.metrics.internal_links` and `external_links` count anchor occurrences in stored content. Web URLs are classified by parsed hostname: comparison ignores case, one leading `www.`, HTTP versus HTTPS, ports, and the home URL's path. Other subdomains and hostname-prefix lookalikes are external. Protocol-relative URLs (`//host/path`) use the same host comparison. Root-relative and path-relative URLs, query-only references, and fragment-only references count as internal. Empty hrefs, URLs that cannot be parsed, hostless absolute web URLs, and non-web schemes such as `mailto:`, `tel:`, `javascript:`, and `ftp:` count toward neither metric. HTML entities in hrefs are decoded and surrounding whitespace is trimmed before classification. This is a local SEO heuristic; it performs no network requests and does not establish that either host variant resolves, redirects, or serves the same site. Input remains the required integer `post_id`, and effective `edit_post` authorization remains required.

## Backslashes in writes

Post/page metadata and media titles, captions, and alt text preserve backslashes through WordPress storage, including repeated or trailing backslashes and escaped quotes. Send decoded values normally; do not add an extra WordPress slashing layer in your MCP client. JSON still requires its usual escaping: `"C:\\path\\"` represents `C:\path\`.

Existing text/HTML sanitization and registered metadata or SEO-provider sanitizers still apply. Responses report sanitized stored values, not necessarily the original input. Use `update-post-meta`: public keys support JSON-compatible arrays and objects; supported protected SEO keys retain their scalar normalization. Allowed standalone metadata keys are unchanged. Uploads require `upload_files` plus access to any parent post.

## Comments

Comment replies require `edit_posts` and `edit_post` on the post containing the parent comment. Listing requires `moderate_comments`; updating and the approve/trash/spam abilities also require `edit_comment` on the resolved comment. There is no separate `hold-comment` ability: use `update-comment` with `status: "hold"` and the required `content`.

## Category and tag permissions

| Abilities | Required WordPress access |
| --- | --- |
| `create-category`, `create-tag` | Registered taxonomy's `edit_terms` capability |
| `update-category`, `update-tag` | Taxonomy's `edit_terms` and `edit_term` for the existing term |
| `delete-category`, `delete-tag` | Taxonomy's `delete_terms` and `delete_term` for the existing term |

These names use the `webmastery-site-toolkit-for-mcp/` prefix. WordPress's final `current_user_can()` result applies, including capability mapping and site filters. Default mappings still allow Editors and Administrators to manage terms; custom mappings can grant tag management without `manage_categories`, or deny it despite that global capability. Creation intentionally requires term-editing access, not the more permissive nonhierarchical REST `assign_terms` policy. Granting a core alias such as `edit_post_tags` alone does not override WordPress's mapping of that alias.

Both permission callbacks and direct execution enforce write checks. Category/tag list and get permissions are unchanged. For callers with the taxonomy capability, missing IDs or IDs from the other taxonomy still return the existing `success: false` not-found envelope. Object-policy denials now fail before writes instead of bypassing the site's restrictions.

Term deletion is permanent and uses WordPress's normal relationship handling (including category reassignment). Core denies deleting the default category through `delete_term`; if site filters override that denial, an underlying `0` or `false` deletion result still returns `success: false`, never `deleted: true`. Successful deletion keeps the existing `{ "success": true, "data": { "id": 123, "deleted": true } }` shape.

## Destructive-operation interlocks

| Abilities | Required inputs and unchanged access |
| --- | --- |
| `delete-media` | `media_id`, `confirm:true`; object-specific `delete_post`. Optional boolean `force` overrides only known references. |
| `delete-category`, `delete-tag` | Term ID and `confirm:true`; registered taxonomy and per-term delete capabilities above. |
| `bulk-trash-posts`, `bulk-publish-posts` | `ids` (1-100 raw entries), `confirm:true`; `delete_posts` / `publish_posts` plus existing per-object checks. Optional boolean `dry_run` defaults to false. |

Confirmation must be the JSON boolean `true`, **including previews**. Missing, false, string, numeric, or null confirmation cannot authorize a write. Direct callbacks retain `precondition_failed/missing_confirmation`. Registered input validation also rejects boolean-like strings and integers with `invalid_input/ability_invalid_input` before permissions, even when WordPress's schema validator accepts them. Optional `dry_run` and media `force` reject non-booleans rather than coercing them.

Bulk requests reject 101 entries even when all IDs are identical. Accepted IDs are normalized and deduplicated before processing; `requested` counts unique normalized IDs. A preview performs the same type, per-object authorization, status, and disabled-trash checks without post, metadata, term, scheduling, or mutation-hook writes. Its normal batch summary adds `data.dry_run:true`; successes mean **would act**, not already changed. All-failed batches still return `success:true`. A preview is not a reservation: concurrent changes or real write-hook/storage failures can change a later execution.

For example, preview a bounded selection with `{"ids":[123,456],"confirm":true,"dry_run":true}`, inspect every success/failure, obtain independent approval, then send the same IDs with `confirm:true` and omitted/false `dry_run`. Do not retry an entire partially successful batch blindly.

Media deletion scans the same known references as orphan-media inspection: `_thumbnail_id` metadata and literal attachment URL/GUID strings in post content. A hit returns `precondition_failed/media_in_use` unless `force:true`; successful deletion always includes `data.in_use` as a boolean. Even forced deletion scans and fails closed on query errors, returning a safe canonical error, never SQL details. This is **not proof of universal non-use**: serialized settings, custom metadata, transformed image URLs, external sites, and concurrent edits may be missed.

These interlocks do not grant capabilities, prove human approval, or prevent prompt injection. Clients must independently bind approval to the action and exact IDs. Single post/page/CPT soft-delete inputs are unchanged. See the [migration guide](3.0-migration.md#destructive-operation-interlocks).

## Targeted content patching

`list-content-blocks` accepts required `content_id` (integer) and `content_type`
(`post` or `page`), and requires `edit_post` for that object in both permission
and execution callbacks. Successful `data` retains `id`, `type`, `content_hash`
and `blocks`, and always adds boolean `is_classic`. It is `!has_blocks()` of the
raw stored body: empty/whitespace-only content is Classic; mixed freeform and
block content, and an explicit `core/freeform` Classic block, are not. This
follows [WordPress marker detection](https://developer.wordpress.org/reference/functions/has_blocks/),
not editor-plugin presence, editing history, or structural validation; even a
malformed `<!-- wp:` marker makes the flag false.

Only when `is_classic:true`, `data.hint` is the fixed text: "This post has no
block markup. Use patch-post-content with target_type exact to change part of it."
Empty content has no partial target until content is added. The heading target requires parsed `core/heading` blocks, so raw Classic HTML
headings require exact matching. The hint does not grant write access or bypass
match/hash preconditions. Existing block paths, hashes, empty-freeform filtering and record-local `untrusted_fields`
are unchanged; the computed flag and plugin-authored hint add no untrusted
field markers. Denied requests expose neither flag nor hint. `get-post` is unchanged.

| Ability | Target | Required access |
| --- | --- | --- |
| `webmastery-site-toolkit-for-mcp/patch-content-block` | One Gutenberg block by path or unique hash in a post or page | `edit_post` for the specific object |
| `webmastery-site-toolkit-for-mcp/patch-post-content` | A heading section or unique exact raw-content match in a post, page, or public, UI-visible, editor-enabled CPT | `edit_post` for the specific object, resolved through its capability map |

Both abilities sanitize the **replacement fragment** with `wp_kses_post()`, not the entire rebuilt body. This prevents the plugin from stripping unrelated iframe, script, style, form, SVG, or event-attribute markup already stored elsewhere. Exact-match `old_content` is compared byte-for-byte against raw stored content, without sanitizing the search needle.

WordPress's normal save pipeline still applies. Preserving markup that KSES would strip requires the caller's **effective `unfiltered_html` capability**; a role name alone is not sufficient. Multisite, `DISALLOW_UNFILTERED_HTML`, or capability policies can deny it even to an Administrator. Callers without it remain subject to core's save-time KSES filtering. New replacement markup is filtered regardless of this capability, and full-content update abilities still sanitize all supplied content.

Block-path, block-hash, and heading patches retain the existing WordPress parse/serialize behavior, which can normalize noncanonical block delimiters and omit empty freeform separators. They are not raw byte-splicing operations. Exact patches do not parse or serialize the surrounding content.

## Image URL uploads

`webmastery-site-toolkit-for-mcp/upload-image` requires `upload_files` (normally Author or above), plus `edit_post` for an optional target post or page. The download is capped at the
site's `wp_max_upload_size()` limit, and the actual file size and allowed image
MIME are checked before sideloading. Empty files, incomplete PNG/JPEG/GIF
headers, HTTP length mismatches, and failed integrity checks are rejected;
generic or incorrect HTTP Content-Type alone does not reject valid image bytes.
Zero, invalid, or overflowing upload limits return `invalid_upload_limit`
rather than disabling the bound.

Hosts that resolve to private or reserved addresses, local names, failed DNS
resolution, cyclic aliases, and alias chains exceeding 16 lookups are rejected.
IPv6 literals and AAAA-only hosts are unsupported. The download safeguards and
their limits are described in the
[security strategy](../90-security-strategy.md#image-url-download-safeguards).

## Scheduling

Create/update abilities accept `status: "future"` and `scheduled_date`. Creating a schedule or moving non-scheduled content to `future` requires a nonempty date **at least 60 seconds ahead when validated**, matching WordPress core's scheduling cutoff. Prefer an ISO 8601 value with `Z` or an explicit offset, for example `2030-12-01T09:00:00-05:00`. Use a comfortably future date: even an ordinary clock tick between validation and core's later time check can make a date exactly 60 seconds ahead publish immediately. This is preflight validation, not an atomic status guarantee against elapsed time or third-party hooks.

An ordinary edit to an already-scheduled item may omit `scheduled_date`, with `status` omitted or still `future`. Both stored date strings are retained, and the stored GMT date must still meet the cutoff. Near-now or overdue schedules must be given a new safe date, or an explicit nonfuture status. Changing the site timezone does not invalidate an otherwise valid stored schedule or silently rewrite its dates. WordPress remains responsible for cron timing; timezone changes can affect the cron event's local-date conversion.

Malformed dates, invalid calendar/time values (such as February 30), and missing or unsafe future dates fail before the ability writes content, metadata, or terms. Scheduling errors use `success:false`, `error.code:"invalid_input"` and the specific `error.reason`: `invalid_scheduled_date`, `missing_scheduled_date`, or `scheduled_date_too_soon`. An explicitly blank date is not a request to reuse an existing future schedule.

For 2.x compatibility, valid PHP `strtotime()` date families remain supported, including relative dates. Offset-less input keeps its previous PHP-default timezone interpretation, normally **UTC**, not the site's local timezone. Named-zone DST folds/gaps retain PHP's resolution, including gap normalization; explicit offsets preserve the specified instant even during a repeated local hour. Local and GMT dates are formatted from the same instant without a lossy local-to-GMT round trip.

With a nonfuture or omitted status on non-scheduled content, a valid supplied date still sets the date arguments rather than being ignored. Normal WordPress rules apply: draft/pending updates with a previously zero GMT date can reset that date to now, and `publish` with a sufficiently future date can become `future`. The scheduling fix does not change those nonfuture semantics or publish capabilities.

## Parent assignments

Page and hierarchical custom-post-type create/update abilities accept `parent`.
A positive parent ID must identify an editable item of the same hierarchical
type and must not create a cycle or lead into an existing cyclic hierarchy.
Invalid or unauthorized requests fail before the ability saves any content,
status, metadata, or taxonomy changes. Existing permission and other input
errors retain their precedence.

Use `parent: 0` to detach an item, or omit `parent` to leave it unchanged on
update. Assigning the same parent still checks that immediate parent's edit
permission; ancestors do not require edit permission. Pages use `edit_post`
and CPTs use their registered edit capability, including WordPress capability
filters. An ordinary Author cannot edit pages by default.

Built-in post and nonhierarchical CPT create/update
schemas reject any supplied `parent`, including `0` and `null`. Omit it on
these types. Detach-to-zero remains supported for pages and hierarchical CPTs;
their existing permission, type, existence, and cycle safeguards are unchanged.

## Google Site Kit

These optional read-only abilities use Site Kit's registered internal REST routes in the current WordPress user context. They do not read Site Kit options, instantiate Site Kit internals, expose OAuth credentials, or change Site Kit settings.

| Ability | Result | Required access |
| --- | --- | --- |
| `webmastery-site-toolkit-for-mcp/get-site-kit-status` | Plugin/version, site connection/setup, and current-user authentication/reauthentication state | `manage_options` and Site Kit setup access |
| `webmastery-site-toolkit-for-mcp/list-site-kit-modules` | Safe module activation, connection, sharing, and dependency state | WordPress `read` plus Site Kit's module-list route permission |
| `webmastery-site-toolkit-for-mcp/get-site-kit-permissions` | Current user's normalized Site Kit capability and per-module sharing matrix | WordPress `read` plus Site Kit's user-permissions route permission; retains the 1.82.0 minimum-version diagnostic when that route is missing |
| `webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed` | Curated field data, category scores, and selected audit metrics for a same-site URL | WordPress `read` plus Site Kit's module-list and PageSpeed datapoint route permissions |

Site Kit does not publish these routes as a supported third-party API. The adapter checks route availability at runtime and returns `site_kit_unavailable` or `site_kit_unsupported` instead of assuming a specific Site Kit implementation.

PageSpeed accepts an optional `url` (defaults to the site home URL) and an optional
`strategy` (`mobile` or `desktop`, defaults to `mobile` when omitted). Both `{}`
and `{"url":"https://your-site.example/page/"}` request mobile analysis. Explicit
`null`, other types, and unsupported strategies such as `both` are rejected.
The permission callback and execution use the same resolved URL and strategy;
same-site URL validation and Site Kit authorization still apply. URL domain
validation runs in execution before any data request, retaining `invalid_url`
for an authorized caller instead of masking it as a permission denial. The successful
result retains `data.requested_url`, `data.strategy`, `data.id`,
`data.analysis_timestamp`, `data.field_data`, `data.categories`, and `data.audits`.

The three delegated abilities check `read` before plugin discovery, route lookup, or upstream work in both permission and direct execution callbacks. This is a minimum, not an access grant: the exact upstream GET route must expose a callable permission check, and normal WordPress REST dispatch still enforces it. Missing providers, routes, or usable permission callbacks fail closed. Status keeps its separate `manage_options` gate, without an additional `read` requirement.

Upstream route permission denials and dispatched REST 401/403 responses return
plugin-authored `forbidden` guidance naming **possible** Dashboard sharing or
Google sign-in/reauthentication requirements. They do not establish a specific
cause or imply a Google outage. An authorized administrator can review access
for the caller's role to the relevant service from the sharing icon in the
Site Kit dashboard header; see Google's [Dashboard sharing guide](https://sitekit.withgoogle.com/documentation/features/dashboard-sharing/).
Eligible users can sign in to Google in Site Kit as that WordPress user.
For further diagnosis, request `get-site-kit-status` (`manage_options` required)
and `get-site-kit-permissions` only where authorized; either can also be denied.
The denial itself performs no additional diagnostic reads. Local capability
denials and missing/non-callable upstream checks keep their existing messages
and categories. Non-authentication upstream failures retain their generic
request-failure message; raw provider messages, codes and data remain absent
from canonical public errors. Native execute-time permission rejection still
uses WordPress's `ability_invalid_permissions` masking; Adapter permission
preflight and dispatch failures preserve the authored guidance.

This plugin does **not** add an `edit_posts` or `manage_options` floor to the
delegated abilities, so a Subscriber whom Site Kit legitimately authorizes is
not excluded locally. Route-level permission semantics observed for a specific
Site Kit version are recorded in the
[E2E guide](../../tests/e2e/README.md#site-kit-permission-regressions).

## Untrusted result fields

Affected successful records add `untrusted_fields`: a unique array of the names
of potentially user-controlled fields **present in that same record**. Names
are relative keys, not paths or a global list. Null and empty values still
count as present. Marking does not change existing values, types, HTML, or block
markup; normal write sanitization and existing response normalization still
apply. Markers never restore omitted/redacted fields.

| Successful record | Fields marked when present |
| --- | --- |
| Post, page, or CPT record (including responses reusing these normalizers) | `title`, `content`, `excerpt`, `slug`, `url`, `author_name` |
| Revision | `author_name`, `title`, `content`, `excerpt` |
| Block | `block_name`, `text`, `html`, `attrs` |
| `patch-content-block` data record | `content` |
| `patch-post-content` `data.target` record | `heading_text` when present; `untrusted_fields:[]` for an exact-match target |
| Standalone `get-post-meta` containing data record | `meta` |
| Standalone `update-post-meta` data record | `meta_key`, `previous_value`, `current_value` |
| Standalone `delete-post-meta` data record | `meta_key` |
| Comment | `author`, `author_email`, `author_url`, `content` |
| Media | `title`, `caption`, `alt_text`, `url`, `filename` |
| Orphaned media / posts without a featured image | `title`, `url` on each `data.items` record |
| Stuck scheduled posts | `title`, `url`, `author_name` on each `data.items` record |
| User lookup | `display_name`, `nicename`, `url`, authorized `login` and `email` |
| Administrator account audit | `login`, `email`, `last_login` |
| Application-password audit | `user_login`, `app_name` |
| SEO analysis `metrics` | `title`, `url`, `slug`, authorized `yoast_meta_description`, `seopress_meta_description`, `yoast_focus_keyword`, `seopress_focus_keywords` |
| SEO provider containing record | `title`, `url`, `metadata`, `raw_meta` |
| SEO/readability score record | `title`, `url`, `score` |
| Sitemap containing record / robots record | Sitemap: `url`, `entries`; robots: `url` |

SEO/readability records mark `score` when its value is an integer (including
zero) or `null`. Markers follow field presence, not value type. Object and
score-key authorization still precede the read; denied records are not exposed.

Compact post/page/CPT trash results remain exactly `{"id":123,"status":"trash"}`:
they contain no stored text and do not acquire a marker or extra content fields.
This does not apply to `delete-post-meta`, which returns and marks `meta_key`.

Container values such as `attrs`, `meta`, `metadata`, `raw_meta`, and `entries`
are marked on their containing record; their nested maps are untouched.
Standalone metadata updates likewise leave nested stored maps in
`previous_value` and `current_value` unchanged. Content patch results retain
markers on their nested normalized post as well as the patch-specific records
above. In SEO/site overview, sitemap markers belong on the `sitemap` record
and the robots URL marker belongs on `robots_txt`. Canonical
errors and diagnostic error subrecords have no markers. This is scoped
coverage, not a declaration that all unmarked values are trusted.

For example, this illustrative successful block record preserves both the
markup and the attribute types (the marker order is not a parsing contract):

```json
{"block_name":"core/paragraph","text":"Review this draft.","html":"<p>Review this draft.</p>","attrs":{"className":"review","example":null},"untrusted_fields":["block_name","text","html","attrs"]}
```

Existing capability/privacy requirements are unchanged:

| Surface | Access and privacy requirement |
| --- | --- |
| Content, revisions, blocks | Existing object/status and mapped post-type capabilities; direct content getters and edits require `edit_post` on the target. |
| Comments | Listing requires `moderate_comments`; replies require `edit_posts` plus parent `edit_post`; moderation requires `moderate_comments` plus `edit_comment`. The existing comment normalizer emits author email/URL under these permissions; markers introduce no additional privacy gate or redaction. |
| Standalone post metadata | Existing `edit_post`, effective `edit_post_meta` for reads/updates, `delete_post_meta` for deletes, and protected-key eligibility remain unchanged; markers grant no key access. |
| Media | Existing upload/object capabilities and list filtering; a marker does not grant attachment access. |
| User lookup and audits | Lookup requires `list_users`; login/email additionally require `edit_user` on that user or `edit_users`. User access audit requires `edit_users`; role labels are guidance, not a replacement for capabilities. |
| SEO | Existing object and exact-key authorization remains authoritative. Denied fields stay absent, with `unavailable_fields` / `unevaluable_checks` preserved. Overview requires `manage_options`. |

Generated Yoast head data remains unavailable (`generated_head.available:false`);
URL-only inspection remains `unsupported`, and no provider head calls are made.
Field markers do not add head passthrough or a text-only `content_format` option.

## Security and privacy behavior

Untrusted-field markers, annotation hints, and confirmation interlocks are
defense-in-depth only: not a security boundary,
capability check, content filter, or prompt-injection guarantee. Model-supplied
confirmation is not independent human approval.

- Updating or moderating comments requires both `moderate_comments` and WordPress's object-specific `edit_comment` capability. Custom roles cannot moderate another author's post or a custom post type without its mapped edit capabilities. This does not change comment listing or reply permissions.
- List abilities for posts, pages, custom post types, media, and SEO scores filter every returned object before exposing full details; private, trashed, draft, pending, and scheduled content is only returned when WordPress grants the matching object/status capability.
- Creating or updating content as `publish`, `private`, or `future` requires the relevant publish capability, and bulk publishing requires `publish_posts`.
- Author display names remain in content responses, but login names are omitted from post, page, CPT, revision, and content-hygiene responses. User login and email fields are only returned from user lookup abilities when the caller can edit that user.
- Deletes for posts, pages, and custom post type items move content to trash. If `EMPTY_TRASH_DAYS` is `0` or another falsy value, these abilities refuse with `trash_disabled` before mutation instead of allowing WordPress to permanently delete the item. Bulk post trash reports this per authorized ID in `data.failures`, with no false success entries; its existing top-level summary remains successful even when every ID fails. Missing/type and permission errors take precedence. No permanent-delete override is offered.
- Comment trash and comment updates with `status: "trash"` set the comment status through `wp_set_comment_status()`, retaining the row even when site trash is disabled. Media deletion remains permanent.
- Permanent media/term deletion and both bulk post operations require exact `confirm:true`; bulk requests are bounded to 100 raw IDs and support non-mutating `dry_run:true`. Media reference checks fail closed even with `force:true`. These are safety interlocks, not human-approval or capability grants.
- Block and partial-content edits can use hash preconditions and fail when a target is missing, ambiguous, or stale.
- Targeted patches sanitize replacements only and retain WordPress's capability-dependent save filters; they do not grant unfiltered HTML write access.
- Subscriber-safe site info deliberately avoids secrets, filesystem paths, salts, auth keys, raw server internals, WordPress version, and theme version. `get-environment-info` requires `manage_options`.
- Site Kit module, permission, and PageSpeed abilities require WordPress `read` **and** Site Kit's own REST permission callbacks, failing closed when a required route or callable permission check is absent. Status separately requires `manage_options`. Responses omit OAuth scopes/proxy details, module owner identities, raw settings, screenshots, third-party entities, and full Lighthouse payloads. PageSpeed only accepts URLs on the current site, although Google processes those requests through Site Kit's PageSpeed service.
- Webmaster verification checks require `read`, including direct execution. Callers without `activate_plugins` receive neither `data.google.site_kit` nor `data.checks.google_site_kit`; plugin inspection is skipped and the summary counts only authorized checks. Public results, including failures and unknowns, share a 60-second cache scoped to the site, home URL, and result schema. Warm calls do not repeat HTTP/DNS work; private plugin state is inspected separately on each authorized call and is never cached with public results. Concurrent cold misses or early transient eviction can repeat work, so this is not a strict rate limit.
- `get-environment-info`, `plugin-audit`, `user-access-audit`, `database-health`, `performance-status`, `backup-status`, `security-audit`, and `site-health-check` are Administrator-only.
- `security-audit`'s `ssl` finding reports only the configured public `home` option (including normal option filters and `WP_HOME`): recognized HTTPS passes and HTTP fails. Missing/unsupported schemes or hosts, whitespace/control characters, malformed percent escapes, and invalid authority syntax warn as unknown. The local syntax guard accepts local names, Unicode/IDN forms, properly escaped components, and bracketed IPv6 (including escaped zone IDs) or IPvFuture literals; it is not a complete URL, DNS-name, or internationalized-name validator and imposes no address-routability policy. It is independent of the MCP request scheme and admin-only TLS policy. It does not test certificates, reachability, redirects, or the final filtered front-end URL.
- Debug-log findings omit filesystem paths. Enabled logging warns that access is unverified: a neighboring `.htaccess` file or a location outside `wp-content` does not prove protection from web access. Disabled logging still passes; enabled logging alone is not proof of exposure or a reason to disable necessary logging.
- `database-health` query failures retain the contextual `database_health_query_failed` reason without raw SQL/server error text. WordPress's own database logging behavior is unchanged. `table_sizes[].table` defaults to core logical names such as `posts` or response-local labels such as `custom_table_1`, with `is_core_table` identifying exact WordPress table mappings. Custom/plugin names and the configured prefix are not returned by default. `include_table_names: true` deliberately sends raw identifiers to the model provider; it is an Administrator (`manage_options`) diagnostic opt-in, not a grant of permission. Only actual booleans are accepted; strings, numbers, and null are rejected. Neither mode returns passwords, raw SQL errors, or filesystem paths. Counts, bytes, ordering, and the current-prefix query scope are unchanged.

For what the agent and its operator should do with these controls, see the
[agent threat model](../90-security-strategy.md#agent-threat-model).
