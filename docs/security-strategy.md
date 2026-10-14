# Security strategy

Release recovery has two distinct provenance paths. Existing-artifact recovery
must still reject failed, cancelled, incomplete or missing original QA evidence.
Input-free fresh validation is separately reviewed and locks an immutable
release source/tag before execution; it seals a **new** successful run/attempt
and reviewed control identity, never claims the failed run validated the ZIP.
Both paths independently recheck actor authorization, tag/main ancestry,
artifact integrity and version progression after the same protected
`wordpress-org` approval. Neither local diagnostics nor a control-tag push
authorize publication. See the release strategy's fresh-validation runbook.

This public WordPress.org plugin exposes MCP abilities. Security policy governs
access to WordPress data and site-changing actions; the
[QA strategy](qa-strategy.md) defines the corresponding evidence. See the
[software development lifecycle](sdlc-overview.md) for review and release stages.

## Threat model

Use the narrowest relevant capability, keep permission callbacks consistent
with guarded execution, and check object/status permissions before returning
data or changing state. Primary risks include lower-role access to private or
trashed content, unauthorized totals, status escalation, identity/environment
fingerprinting, unsafe URLs/uploads/metadata/identifiers, vulnerable dependencies
and compromised workflow or release credentials.

## Agent threat model

### Untrusted site content

Stored content may contain instructions that try to redirect an agent. Treat
post, page, CPT and revision bodies/titles/excerpts/author display names as
untrusted, along with block markup/attributes, comment bodies and author
email/URL fields, media titles/captions/alt text, authorized SEO metadata and
keywords, user profiles and user-chosen application-password `app_name`.
A `message` field is not necessarily trusted instructions. 3.0
does not fetch or return opaque generated Yoast head HTML/JSON.

SEO Analyze Post uses exactly `Focus keyword found in title.` and
`Focus keyword not found in title.` for these diagnostics. Authorized stored
values remain in `data.metrics.yoast_focus_keyword` and
`data.metrics.seopress_focus_keywords`, with the title and existing provider
precedence. This separation shipped independently of the 3.0 markers.

Authentication, JSON serialization, capabilities and read-only annotations
do not make retrieved text authoritative. Capabilities authorize an account's
operations, not a human's intent. A read-only response can influence later
writes or transmission through another tool; retrieved content cannot approve
those actions.

Clients and operators should:

- Separate retrieved content from trusted instructions. Require user intent
  for the exact mutation, objects, scope and any transmission destination.
- Show an independent preview/diff and bind approval to actual arguments,
  especially deletion, bulk changes, publication, plugin changes and disclosure.
  Changed scope or destination requires renewed approval.
- Use bounded selections, small batches and available hash preconditions.
  Check proposed IDs and changes against the user request, then inspect results.
- Use a dedicated least-privilege account. Contributor/Author may suffice for
  own editable posts; use Editor for broader editorial access and avoid routine
  Administrator credentials.
- Keep trustworthy backups and tested recovery outside the agent's control.
  Trash, revisions and backup-status responses do not guarantee recovery.

Plain text can still contain instructions. Markers, hints and confirmation
interlocks are defense-in-depth, not a content filter, capability grant,
prompt-injection guarantee or universal write approval. Model-supplied
`confirm:true` does not prove human approval; `dry_run` cannot approve a later
write. Optional text-only `content_format` output is not implemented.

### Untrusted-field contract (3.0)

Affected successful records have an additive `untrusted_fields` array of unique
names relative to that record. Mark only fields actually present, including
null/empty values; names are neither dotted paths nor a global list. See the
[coverage and example](../README.md#untrusted-result-fields-30).

Preserve existing values, types, HTML and block delimiters returned by the
normalizer. Existing write sanitization remains; markers do not promise raw
input echoing. Container markers such as `metadata`, `raw_meta`, `attrs` or
`entries` designate the whole value without injecting keys into stored maps.
Do not restore missing/redacted fields to satisfy a marker.

Standalone `get-post-meta` marks data `meta`; `update-post-meta` marks
`meta_key`, `previous_value`, `current_value`; `delete-post-meta` marks
`meta_key`. Nested maps and object/key permissions remain unchanged.
`patch-content-block` marks data `content`; `patch-post-content` marks present
`heading_text` on `data.target`, with an empty marker list for exact-match
targets. SEO/site overview marks present `url`/`entries` on `sitemap` and `url`
on `robots_txt`.

Authorize before marking. User login/email lookup still needs effective
user-edit permission; account/application-password audit remains privileged.
Comment policies remain unchanged, so marking email/URL does not add redaction.
SEO key denials retain `unavailable_fields` and `unevaluable_checks`; generated
head inspection remains unavailable, URL-only inspection unsupported and
provider head calls absent. Canonical errors and diagnostic error subrecords
receive no markers. Missing markers do not guarantee trusted content.

### Annotation scope

Registrations declare `meta.annotations` such as `readonly`, `destructive` and
`idempotent`. QA checks actual MCP Adapter 0.6.1 individual-tool `tools/list`
output for `readOnlyHint`, `destructiveHint` and `idempotentHint`; source
inspection alone does not establish those runtime hints.

The default server exposes discovery, get-info and execute gateway tools.
Their hints describe the gateways, not individual abilities. Retrieve metadata
with `mcp-adapter-get-ability-info` before interpreting a target's hints, and
never treat hints as approval. Markers accompany both transport forms.
Inspect actual server output before depending on hints or shape; see
[response format](../README.md#response-format).

## Ability permission policy

Every ability has an explicit `permission_callback`. Static policy blocks
unreviewed `__return_true` callbacks. Use these expectations:

| Operation | Permission |
| --- | --- |
| Public-safe read | `read`, with documented response safety. |
| Object read | Object-aware `read_post`, `edit_post` or mapped CPT capability before full payloads. |
| List/query | Query capability plus per-object/status filtering before normalization and totals. |
| Write/delete | Object `edit_*`/`delete_*` and publish/private/schedule capability. |
| Plugin/environment/database/security/runtime detail | `manage_options` or the relevant plugin-management capability. |
| Identity | User-management permission for login/email, with absence assertions for lower roles. |
| Delegated provider | Local capability floor plus the exact upstream route permission in permission and execution paths; fail closed on missing provider/route/callable check. |

Comment update/approval/trash/spam require both `moderate_comments` and
`edit_comment` on the resolved object, in permission and execution. Core maps
the latter to the parent post or orphan-comment `edit_posts`, including filters.
Listing/reply policies are separate. This intentionally differs from WordPress
7.1 REST's `moderate_comments` OR `edit_comment`.

Existing-object permission failures use `forbidden`, wrapped as
`ability_invalid_permissions` by WordPress abilities. Authorized missing
objects defer to guarded execution; 3.0 missing-comment failures use
canonical `not_found`. Direct denied callbacks retain missing-object handling
but cannot expose/write existing content before both capabilities pass.
Invalid/nonpositive IDs never reach `get_comment(0)` or its global fallback;
negative IDs cannot select a different positive object.

MCP Adapter's HTTP gateway checks permission before schema validation.
WordPress abilities validate schema first. 3.0 owned HTTP failures have
`isError:true` and one canonical-JSON text block without `structuredContent`
on the wire (internally null). Preserve these distinct validation orders.

[Site Kit](../includes/class-site-kit.php) status requires `manage_options`;
module/permission/PageSpeed use a local `read` floor plus available upstream
route permissions/sharing, rechecked before provider work. Data uses normal
REST dispatch. Roles alone cannot grant provider access; inactive or unavailable
routes can deny Administrators. Fixtures do not establish real-provider
compatibility; see [supported operations](../README.md#google-site-kit-compatibility-abilities).

## Diagnostic privacy

Administrator access does not justify sending database identifiers to a model.
Database health omits the configured prefix and custom/plugin table names by
default, using physical WordPress core mappings and response-local opaque
custom labels without widening current-prefix queries. Explicit boolean
`include_table_names:true` additionally requires effective `manage_options`,
including direct calls. It discloses fingerprints but never passwords, raw SQL
errors or filesystem paths. Opaque labels are not stable identities, and
counts/sizes are not complete anonymization.

Public webmaster verification requires `read` before HTTP, DNS, transient or
private-plugin work in both permission and direct callbacks. Only
`activate_plugins` callers receive Site Kit installation/activation/basename
at `data.google.site_kit` and `data.checks.google_site_kit`. Others skip plugin
inspection entirely and derive summaries from public results; private paths
remain absent rather than redacted afterward.

Only public checks/homepage reachability enter a shared 60-second transient
keyed by schema version, blog ID and home URL. Preserve failure/unknown details.
Never cache caller summaries or private plugin state; authorized inspection
runs separately. This bounds warm repeats, not concurrent cold misses: no
cross-request lock/coalescing guarantee exists and core may evict early.
HTTP/DNS/plugin fixtures, real capability/transient tests and actual MCP
transport provide distinct evidence.

## Destructive-operation interlocks

3.0 schemas require strict `confirm:true` for permanent media/category/
tag deletion and both bulk post operations. Callbacks enforce it independently,
including previews. Missing/false/nonboolean confirmation cannot write.
Bulk `dry_run` and media `force` require booleans. Prove permission denial with
valid input rather than confusing it with schema rejection.

Bound raw bulk arrays to 100 before normalization/deduplication. Preview the
same per-ID type/capability/status/disabled-trash eligibility without mutation.
Report would-act successes, canonical item failures and `data.dry_run:true`;
an all-failed processed batch retains `success:true`. Preview does not reserve
state or predict third-party hooks.

Media deletion checks attachment `delete_post` before the shared orphan helper's
featured-image metadata and literal content URL/GUID references. Only a known
positive reference may be overridden with `force:true`; query failure fails
closed even when forced, with safe canonical diagnostics. `in_use:false` cannot
prove absence from serialized/custom storage, derivative URLs, external uses
or concurrent references. Preserve orphan-list semantics.

Post/page/CPT/bulk trash refuses before `wp_trash_post()` when
`EMPTY_TRASH_DAYS` is falsy, because core otherwise permanently deletes.
Check existence/type and deletion permission first; use `trash_disabled` only
for authorized existing objects. Keep bulk item failures and totals without
adding a permanent-delete route.

Comments use row-preserving `wp_set_comment_status(..., 'trash')`, not
`wp_trash_comment()`. Characterize that distinct disabled-trash behavior without
changing retention or other plugins' hooks.

## Security-sensitive QA and environment assumptions

Retain allowed/denied manifest coverage, sensitive-field absence, invalid-input
no-write snapshots and canonical errors. Static checks include security policy,
manifest validation, locked audit, WPCS and configured PHPStan. Actual contract/
MCP checks prove registrations, roles, mapped capabilities, mutations, transport
and cleanup. Fresh trash boots verify constants and zero permanent-delete
filters/hooks; they are not HTTP proof. Debug warnings/notices/deprecations/
errors fail Docker QA.

Trust the disposable runner, Docker engine and reviewed images as prerequisites.
This product QA does not claim whole-host provenance, kernel attestation or
security of a hostile engine. Use ownership-labelled unique stacks, loopback
HTTP, no database host port, read-only source/package binds and UID/GID 33:33
candidate execution. Never mount the engine socket or run against shared/live
WordPress. A fixed trusted directory initialization alone runs as root.

Credentials/context/raw HTTP/SQL/wire/resource/error records remain private and
bounded. Public output uses only validated closed summaries. A successful
export or boolean cleanup flag does not prove live absence. Retain failed
records and original exits; restoration/absence failure blocks restart and
teardown until the exact owned state is recovered.

## Dependencies, secrets and release integrity

Audit locked Composer dependencies and review updates without suppressing
advisories or raising the declared PHP floor. Dependabot checks Composer/
Actions weekly; alerts and automatic security updates still require review.
Pin test dependencies, verify executable downloads and record floating tested
versions before proposing promotion.

Do not commit credentials, application passwords, tokens or service keys.
Rotate leaked persistent secrets; dispose of exposed isolated test credentials.
Keep Actions permissions explicit and PR code away from production secrets.
Secret scanning/push protection supplement review, not proof of no leaks.

`SVN_USERNAME`/`SVN_PASSWORD` exist only in the protected `wordpress-org`
environment after approval. Tags remain protected, source must be in main
history and the approved artifact must be deployed. Tag-rule administration
does not bypass environment approval.

## WordPress.org guideline review

Review external communication/consent, executable remote code, opt-in public
credits, scoped dismissible notices, GPL-compatible assets, default WordPress
libraries and accurate readme claims. Plugin contents remain the developer's
responsibility; do not promise security, legal, compliance, traffic, SEO or
automation guarantees unsupported by evidence.

## Vulnerability response

Use [private reporting](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/security/advisories/new)
and [SECURITY.md](../SECURITY.md), not public credentials or private site data.
Triage affected versions/exploitability, use a private branch/advisory fork
where appropriate, add regression coverage and coordinate a patched release/
advisory with WordPress.org user communication. Fixes target the latest stable
release without separate older backports. Response/resolution is best-effort
without a fixed deadline; reproductions should be disposable.

## Official references

- [Repository security advisories](https://docs.github.com/en/code-security/concepts/vulnerability-reporting-and-management/repository-security-advisories)
- [Secret scanning](https://docs.github.com/en/code-security/concepts/secret-security/secret-scanning)
- [Dependency review](https://docs.github.com/en/code-security/concepts/supply-chain-security/dependency-review)
- [Code scanning](https://docs.github.com/en/code-security/concepts/code-scanning/code-scanning)
- [Secure Actions use](https://docs.github.com/en/actions/reference/security/secure-use)
- [WordPress security](https://developer.wordpress.org/apis/security/)
- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
