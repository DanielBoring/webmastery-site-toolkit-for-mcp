# Ability Contract and Full MCP E2E QA

For the full repository QA posture, including static checks, unit tests, release checks, and GitHub Actions trigger policy, see [`docs/qa-strategy.md`](../../docs/qa-strategy.md).

The Docker QA suite has two layers:

1. Ability Contract QA is ability-driven. Every registered `webmastery-site-toolkit-for-mcp/*` ability must be represented in `tests/e2e/abilities-manifest.json`.
2. Full MCP E2E QA uses real MCP Adapter HTTP JSON-RPC requests against `/wp-json/mcp/mcp-adapter-default-server` to prove a remote MCP client can create, read, update, and delete content through the adapter transport.

## Separate private test-custody source

`tests/support/private-custody.php` implements a **test-only library**, not a
production export, new workflow, automatic upload, or ready-to-run encrypted
transport. `PrivateCustodyTest` uses mocked age/API boundaries and real local
ZIP operations, plus the actual receipt CLI with a mock server environment.
Its success is not real cryptography, GitHub-origin, WordPress,
Docker-issuer or release acceptance.

The library uses only ordinary standard age CLI argument arrays with one native
X25519 recipient. It requires an explicitly pinned, separately vetted official
executable and an injected trusted bounded binary-capture driver; it has no
floating PATH fallback or default process launcher. Before any actual use,
review and bind that driver and the authenticated bounded GitHub API/job-log
drivers, their exact source, arguments, output limits, original native status,
EOF/capture behavior and private destinations. A fabricated capture record is
not evidence. No keys, binaries, secrets, encrypted transfers or custody jobs
are supplied or executed by importing this library. The existing unit workflow
has only a separate nonsecret executed-workflow context receipt step.

| Boundary | Required behavior |
| --- | --- |
| Independent origin | Exact repository/head-repository IDs, workflow ID/path, executed workflow commit/ref and reviewed workflow bytes, run/attempt, job ID/name, separate event/PR/run heads and actual checkout commit/tree, nonce, deadline and immutable artifact ID. Require successful context/upload steps and one receipt of each kind in that exact authenticated job's original log; correlate run membership and upload time, then revalidate after decryption. |
| Public receipt | Approved origin/recipient, artifact digest and ciphertext size/hash only. Individual file names, stream hashes, detailed original observations and the content manifest stay inside ciphertext. Artifact ID is bound after upload, not included in the pre-upload content manifest. |
| Artifact | Retain original downloaded ZIP and metadata; require the API SHA-256 digest, exact size, unexpired state and only `private-test-custody.age`. Digest mismatch is fatal, never just a downloader warning or a reason to select another artifact. |
| Decryption | Retain stdout in a new protected quarantine file and original private stderr/status. Require actual native zero, complete bounded capture and both EOFs before parsing even a valid-looking ZIP. A missing final authenticated age chunk is failure. |
| Readback | Closed manifest, at most 10,000 regular files, 32 MiB each, 256 MiB total expanded content including the at-most-1-MiB manifest, and 258 MiB transfer/archive caps. Reject links, special members, traversal, duplicate/case-colliding names, nonportable Windows paths, altered bytes and existing destinations. Use synthetic flat output names; original names and nonregular fixture observations remain metadata, never recreated links. |
| Retention | Retain originals and failure/partial files. No automatic cleanup, retry, plaintext fallback or replacement encryption. Existing test/production cleanup and authority predicates remain unchanged. |

Successful recipient decryption authenticates ciphertext integrity, **not the
sender**: anyone knowing the recipient can encrypt a new message. The separately
authenticated exact job/upload receipt binds the ciphertext and hence its private
content manifest; an encrypted self-asserted origin alone is rejected. Relevant
format requirements are in the [standard age specification](https://c2sp.org/age).

Real local identity generation remains gated on verified official binary
provenance and verified native Windows ACL behavior. The approved policy is a
**new owned directory** under the existing private session destination, accessible
only to the current user and SYSTEM, with inheritance disabled on that directory
only. Do not change parent/profile ACLs, rely on readonly as confidentiality, or
archive/log a private identity. The library does not establish those ACLs or
generate/read key contents; the trusted supervisor must establish this boundary
before allowing any output or passing the identity path to age.

The `Record executed workflow identity` step passes GitHub's server
`github.workflow_sha` and `github.workflow_ref` through explicit environment
fields to `tests/support/private-custody-context.php`. It records those separately
from `github.sha`, the PR head, the REST run head and the tested checkout. It does
not inspect the mutable checked-out workflow file or print the entire GitHub
context. The receipt is enabled only in this repository, not other fork repositories.

For non-PR events, `WSTM_CUSTODY_PULL_REQUEST_HEAD_SHA` must carry the explicit
nonempty sentinel `none`, because empty environment values can disappear across
Windows process boundaries. Only that field's exact sentinel decodes to JSON
`null`; PR events still require a real SHA. Missing or empty environment fields
remain errors, and the public receipt's fields and null meaning are unchanged.

The verifier requires the exact approved workflow commit/ref in both the grant
and the independently authenticated job-log context receipt. It fetches reviewed
workflow bytes at that workflow commit, never at an inferred head/checkout,
and rejects missing, duplicate, failed-step and substituted context evidence.
This direct-workflow receipt is not a reusable-workflow provenance protocol.
Mocked contexts and API responses do not establish the relationship in a real
GitHub run; vetted live API/capture drivers and actual platform evidence remain
prerequisites.

Custody is limited to private byte copies plus recorded original Linux
observations. It is not preservation of original inodes, whole-host attestation,
test success or producer survival until receiver acknowledgment. That stronger
survival gate remains explicitly **unresolved** where required. Both run and job
must still be completed before origin acceptance; those checks are not weakened
to let a hosted job wait for receiver acknowledgment. A durable producer/private
spool must survive job completion and retain originals until an independently
authenticated, exact readback-bound acknowledgment. Hosted runner teardown and
ciphertext upload alone cannot satisfy it. The production untrusted
export still contains exactly its original three safe files; none of these
private paths, ciphertext files or receipts is added to that contract.

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

## Actual canonical error transport proof

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

## Untrusted-content coverage (3.0 Unreleased, #108)

The current manifest has exactly 199 marker assertions on 189 stored-content
cases. Five compact post/page/CPT trash responses contain only `id` and
`status:"trash"`; their complete `data` maps are asserted exactly, with no
phantom stored fields or marker. Standalone metadata deletion still marks
`meta_key`.

The historical 190-case/200-marker test-only inventory in
`tests/unit/fixtures/untrusted-manifest-inventory.json`
was derived from reviewed marker source
`2ef8c40b7d0ac3cc8b15a9292a4ec987b55854e6` and accepted baseline
`2feed8d18d0721a4c7ca2e0187005c8cfae76322`, not regenerated from the working
manifest. Its pinned inventory checks exact case indices, labels, paths and
ordered field lists before removing only those direct `assert_values`
properties. It restores an absent `assert_values` only where the frozen
baseline lacked it; arbitrary stored maps are never traversed.

First, a separate closed hygiene projection requires the four exact
`data.items.0.untrusted_fields` assertions on orphaned media, posts/pages
without featured images, and stuck scheduled posts (indices 332, 334, 335,
337). It removes only those four assertions and requires the complete
pre-hygiene manifest fingerprint to match the existing compact-delete
ledger's after-manifest golden. Every original value, type, role, input and
privacy assertion remains intact; Subscriber denials at indices 333, 336
and 338 remain unmarked. Dedicated mutations reject missing/weakened markers,
wrong record ownership, private-field additions and changes to original data.

Then, before the historical marker projection, the separate
`untrusted-compact-delete-calibration.json` ledger validates and reverses only
the five exact strengthened cases (indices 69, 79, 202, 219, 509). It pins full
typed before/after rows to source `18a8716e469778819b1c92a3d6720df19c1d029b`,
accepted `2feed8d`, and the genuine compact responses from package run
`35680977162`. Every role, input, permission, state and metadata assertion is
preserved; any other change to those five rows fails. After the explicit
four-marker hygiene projection, all other 565 rows remain unchanged.
Historical marker validation still checks all original 190 cases
and 200 paths, using explicit full-row historical handling only for those five.

The resulting complete, ordered, typed 570-case baseline must retain SHA-256
`da4395a6a9d6532c10423e25d02c710c2ed150d226dfa87a988b5594ede8fa4a`.
Only then do the unchanged historical 547-case, 16-error-correction and
seven-privacy-row checks run. Object/array and integer/float distinctions,
original properties, all 52 imported cases, and historical ledger goldens
remain protected. Mutation tests exercise the same strict projection.
The separately pinned `untrusted-import-inventory.json` binds all 52 new
rows to those same immutable sources: indices 0-43, 187, and 372-378. An
executable provenance check requires the mutation provider to match that
entire ordered inventory, rejecting omissions, duplicates and extra indices.

`scripts/untrusted-stage.sh` runs one separate serial stage after the existing
destructive-safety stage has fully restored, before the later metadata/error
fixtures. Source `contract`, `e2e`, and `all` selections each require both
actual gateway and individual HTTP boundaries. Original-ZIP and compatibility
lanes use that same stage; they do not rebuild the archive or overlay checkout
production. The complete ordered plan is 90 individual annotation/get-info
comparisons (85 native abilities plus five owned CPT abilities), followed by
98 semantic cases per HTTP boundary: 286 cases, not a minimum-pass threshold.
Actual advertised tool names come from the catalog rather than a guessed
sanitizer. Bulk publish retains `destructiveHint:true`.

Run only within an explicitly authorized **owned disposable** project.
The proof additionally requires `WSTM108_HOST_AUTHORITY_ROOT` to name an
existing native POSIX host directory owned by the invoking user. CI supplies
`RUNNER_TEMP` explicitly. There is no checkout, `.git`, `build`, artifact,
temporary-directory or receipt-adoption fallback. The entire source checkout
is container-writable in source mode, so it cannot hold independent authority.
Before acquisition, the host checks this project's effective Compose binds,
live container mounts and named-volume configuration, including all configured
services available to planned one-offs. Remote daemons, external/bind-backed
volumes, unknown mappings and noncanonical paths fail closed. The owned stage
itself launches only `exec`, not one-offs with additional mounts.
Native Windows PHP mode/UID/GID values are not an NTFS ACL proof; Docker
Desktop/WSL aliases are not guessed. Positive native-Windows authority and
local current/floor/ZIP feasibility remain **blocked**. Genuine Ubuntu
execution must establish the positive path; synthetic fixtures and actual
Windows refusal controls do not substitute for it.
`WSTM108_STAGE_DISPOSABLE=1` guards the stage entrypoint;
`WSTM108_ALLOW_DISPOSABLE=1`, `WSTM108_STAGE_CONTEXT`, and the exact
`WSTM108_ARTIFACT` identify its runner. These flags are not a Docker lease or
permission to change another session's stack. The stage verifies actual
missing-opt-in CLI exit 2 and actual HTTP 403 `CLI only.` for both entrypoints
before credentials. Do not copy MU fixtures manually or edit `wp-config.php`.
Exclusively owned loaders reference the original fixture files and expose
`/wp-json/wstm118/tools` only for the disposable proof.

Quarantined stage evidence lives in `e2e-artifacts/untrusted-<owner>/`: source/tree/project/owner
and original-ZIP digest binding, actual production/harness file hashes, active
provider version digests, native/enabled/restored runtime digests, validated actual
catalog names/boolean annotation hints, schema/capability digests, case
verdicts, cleanup and finalization. Both files for every report are exclusively reserved
before bootstrap or credentials; an existing summary **or** `.http.jsonl`
journal fails closed, including racing creators. Partial reservations remain.
These paths are not artifact upload sources: a refused collision can contain
foreign bytes even when its basename normally denotes a safe report. No existing
directory is created recursively or chmodded. Original status,
headers and body bytes are persisted **privately before parsing** in exclusive
0600 opaque event files under the owned 0700 lock. Serialization preserves
invalid UTF-8, object/list and integer/float distinctions without executing or
deserializing frames in production. The journal durably binds creation intent,
committed length/hash/identity, parser verdict and case/scope verdict. Known-secret
redaction is not evidence that an arbitrary body is safe to publish. Public
`.http.jsonl` files contain safe hash/length/status/verdict witnesses, never
unvalidated bodies, headers, session tokens or provider messages. A passing
case does not publish its entire response.
The complete public runner journal digest and private inventory are checked
before retirement. All six actor/boundary catalogs, including every pagination
page, must match captured descriptor witnesses. Registration records must match
the enabled runtime's schema digests, not just its names.

After the unchanged primary arm, #108 records private creation intent outside
every project bind and exclusively creates
`build/wstm116-retention-<project>-wstm108-<owner>` before acquisition or
credentials. Its original build identity, file identity and bytes remain
independently bound. A partial creation is retained, never adopted or repaired.
The existing f93 scan already recognizes this companion; neither its helper nor
the outer gates are modified. This distinction matters because the unchanged
primary clear unlinks its marker **before** its final echo.

The companion stays present while that exact clear runs under private
stdout/stderr capture. Complete exact stdout, empty stderr, zero child exit,
primary absence and unchanged companion ownership are mandatory. An echo,
capture, framing, controller or receipt failure before commit retains the
companion; the primary may already be absent and must not be reported as present.
All producers finish, the public evidence inventory is closed, source/harness
and original-ZIP bytes are rechecked, and the exclusive outside-bind
`untrusted-content-release-authorization.receipt.json` is persisted before the
terminal operation. This receipt remains private; its filename alone does not
authorize publication. Independently constructed exports contain typed source
binding/digests and **precommit observations only**, never private authority
paths, identities, transcripts or credentials.

Successful final companion unlink is the irreversible **RELEASE COMMIT**.
There is no post-unlink output/frame check or further success prerequisite.
Subsequent controller death or acknowledgment failure does not recreate
protection: release may have committed while acknowledgment is unknown.
Preserve every observed nonzero/unknown result; neither the authorization
receipt nor guard absence establishes successful QA, publication or merge
readiness. If unlink's outcome cannot be established, report unknown rather
than claiming a guard remains.

The source and original-ZIP outer mocks cover partial-companion collision,
actual unchanged-helper echo failure, failed/truncated clear output, precommit
controller interruption, authorization collision, evidence drift, same-byte
companion replacement, successful commit and postcommit failed acknowledgment.
Real native POSIX controls separately inject partial creation/capture writes
and check retained originals, mode/identity refusal and postcommit fault
classification. Docker/site payloads remain synthetic; those expected fault
cases are contract coverage, **not** successful real runtime runs. Native-Windows
positive paths remain BLOCKED rather than passed/skipped.

Host verification accepts the two exact report basenames or their exact absolute
paths only under the explicitly supplied, source-bound owned artifact directory.
It does not resolve against ambient working directories or alternate roots;
traversal, foreign roots/identities, symlinks and multiply linked files fail closed.

The independently retained host handle binds original lock and state identities
across processes: device/inode, UID/GID, mode and link count, plus owner, source,
project, paths and context. Reads verify the opened handle before and after
reading; same-byte replacements and partial writes are not adopted. The
receipt originals are kept outside every bind source for recovery. They are not
artifact upload candidates or an automatic authority
fallback after host-memory loss. Existing receipts are never overwritten or
removed by success/failure cleanup.

Before any owned PHP helper, a finite native Bash bootstrap exclusively reserves
and holds separate original stdout/stderr descriptors, its creation-intent
descriptor and the controller's publication descriptor. Failures before both
diagnostic descriptors exist have no transcript guarantee and launch no PHP or
runtime helper. The narrowly bounded pre-custody interval routes native and
redirection diagnostics away from public output; only a fixed refusal enum and
nonzero status are public. It does not claim to preserve those original bytes.
Until physical outside-bind admission succeeds, these are
quarantined nonsecret admission captures, not independent authority or exportable
evidence. The controller captures helpers themselves, including startup/fatal
output; inner stage captures cannot retroactively capture their parent helper.
Children receive neither GitHub command-file environment variables nor the
publication descriptor. The controller preserves actual native exits, original
binary streams and partial evidence; it never extracts a frame from mixed output.

Native admission is deliberately narrow: Linux, supported ext4/tmpfs coordinates,
an owned root with its original stable identity, and the exact link-count
transition caused by each exclusively owned child creation. Regular-file
hardlink checks remain unchanged. Mount filesystem-root coordinates, device
identities and nested mounts must prove the authority outside all actual bind
exposures. Canonical text paths alone do not establish that property. A
root-owned `/run/docker.pid` is only a discovery hint: selected listener/socket,
that PID's descriptors, process/start identity and matching mount namespace/table
must corroborate it before and after admission. Inaccessible evidence, unknown
filesystems/mappings and races are `BLOCKED`, without escalation, namespace
entry, process-wide scanning or a claim that hosted CI meets these prerequisites.
Conflicting `DOCKER_CONTEXT`/`DOCKER_HOST` selectors refuse before any daemon
query; all owned discovery/execution uses the verified endpoint and original
executable. Genuine native alias/daemon admission remains independently pending.

Every owned stage/runner/proof process reserves exclusive private stdout and
stderr captures before launch. Both streams are drained and checked for complete
persistence, preserving the actual child exit independently of capture/parser
failure. Public process evidence names only fixed actions and records safe
witnesses. Exit zero with unexpected output or stderr is still failure.
Acquire accepts exactly one successful anchor frame, never a last-line or
success-prefix fallback.

Only the controller constructs the independent flat export, seals and syncs
its three files, verifies readback/original custody, and writes readiness to
its original GitHub output descriptor. The exact upload inventory is
`untrusted-proof.json`, `untrusted-failure-witnesses.json`, and
`untrusted-export-manifest.json`. A verify-only workflow step checks source,
project, run/attempt/job, original directory/file custody, schema and hashes
before those exact files may be selected. Raw stage directories, receipt globs
and broad-upload flat/nested `untrusted-*` paths have no fallback.
Structural publication safety is separate from semantic success: a constructed
safe failure witness may upload, but failed QA, missing readiness, non-passed
proof, verification failure or upload failure blocks promotion. Export and
required capture writes precede terminal companion unlink; later workflow
verification is an artifact/job gate, not rollback or a new release prerequisite.

The tag-release `release-qa` job is the seventh closed-export consumer, alongside
the two E2E jobs, package QA and three compatibility jobs. Its existing PHP 8.2
package run receives the explicit `runner.temp` authority root; source, project,
run/attempt/job and original custody must match before the three safe files upload.
Sealing the original ZIP bundle, uploading that release bundle and announcing
production approval each explicitly require both overall success and successful
required-evidence gating. Safe failure witnesses cannot advance those steps.
This adds no root executor or native-host feasibility claim, changes no terminal
unlink prerequisite, and leaves the original release artifact, publication
approval and historical recovery eligibility unchanged.

The private stage/resource/wire journals and process transcripts never enter
public artifacts. Creation
intents, owned actors/IDs, application-password UUIDs and MCP sessions support
narrow recovery after a lost response. Returned user IDs are enrolled before
capability changes, then validated after the reader's `list_users` grant.
Cleanup must prevalidate the complete ownership inventory before mutation and
prove resource and metadata
absence; attachment reference failures or deletion vetoes retain the associated
file and actor, never bypassed through user deletion. The unchanged host
retention guard is armed before runtime alteration. Missing, partial or foreign
proof, unknown lock entries, changed source/configuration, or failed restoration
blocks teardown and re-entry. Resource cleanup success never permits retirement
of failed HTTP, malformed, unparsed or semantically failed evidence. Expected
canonical denials pass only through their exact existing case oracles.
Original config bytes/hash/mode/UID/GID and actual
native schema/server/observer state must match again before retiring the probe,
private journals and guard. Finalization first prepares without deleting targets;
the host validates its complete captured outcome, then authorizes that specific
generation and target-inventory digest. Retire rejects stale, substituted or
replayed preparation and rechecks ownership before mutations. Late failure
reports partial retirement truthfully, retains remaining evidence and leaves
the host guard armed. The private wire journal persists an exact
`removal_pending_id` before each unlink; this is intent, not proof of removal.
Only a successful remover return, guarded predecessor reread, complete remaining
inventory validation and successful journal persistence confirm `removed: true`.
A failed postcheck, veto or interrupted write leaves the target unconfirmed (or
the journal partial), never a claim that every original still exists. Fresh
resume refuses partial or complete retirement rather than adopting missing files.
Only fully validated retirement and the final host proof
allow guard clearing; independent host receipts/transcripts are not retirement
targets. Only known-owned stale GET observations may retry:
five requests of at most two seconds, four one-second sleeps, 14 seconds total.
Mutations, wrong identities, malformed responses and authorization denials do
not retry.
Application-password ownership binds the exact pre-creation adversarial name,
owned actor and returned UUID; it does not replace the stored name with a test
label. The owner token in public evidence is a run identity, not an authentication
secret. Its keyed digest cannot authenticate evidence against an operator who
can rewrite public files: exact private-journal equality and fresh live absence
remain mandatory for retirement. The separately private probe secret authenticates
GET observations. Only the exclusively created upload child receives public
traversal permissions; existing upload-parent permissions are never changed.

The actual Adapter must be 0.6.1. Both real SEO providers must already be active;
pinned lanes require the selected Yoast/SEOPress versions, and candidate lanes
retain their actual versions. The wire decoder preserves original JSON types
and strips only the single marker owned by the compared record. Exactly two
legacy expected diagnostic `details:[]` literals are corrected to canonical
`details:{}` through a source-pinned ledger; legitimate empty lists remain lists.
No production serializer or existing value/authorization oracle changes.

Dedicated runtime acceptance remains pending. Keep validation categories distinct:
synthetic policy/process fixtures, actual native-Windows refusal controls, and
genuine Ubuntu positive authority plus outer source/original-ZIP execution.
The unit method
`test_opted_in_native_capture_export_and_interruption_controls` supplies
`WSTM108_BOUNDARY_OPT_IN=1` itself; Linux PHP 8.1+ unit execution automatically
runs its filesystem/process component fixture, without an external opt-in.
Missing POSIX or builtin `fsync` fails that required-positive branch. On
PHP 8.0 or non-Linux hosts the method instead asserts the actual constructor's
capability refusal before creating its fixture directory: **refusal-only**,
not a new skip or positive native coverage. Linux positive coverage remains
blocked/unexecuted on those hosts. The older StageProof conditional is unchanged.
Neither branch establishes daemon/alias, Docker or WordPress proof.

The QA host requires Linux PHP 8.1+, POSIX and checked builtin `fsync`; there
is no `fflush`-only durability fallback. `WSTM108_HOST_PHP`, when present,
must identify an existing canonical trusted PHP executable; empty, relative,
missing or invalid explicit values refuse rather than falling back to PATH.
Both outer mock entrypoints apply the native canonical-path, regular/executable,
PHP-name, root-owner and non-group/world-writable checks **before invoking the
selected target even for a capability probe**. Explicit paths are not
canonicalized into acceptable replacements. They first use native platform
classification to preserve non-Linux/Windows `BLOCKED` exit 78, without invoking
PHP or creating fixture directories. The admitted host and matrix paths remain
separate and pinned through the subsequent mock controls. The additive rejection
and ordering cases are source-pinned models, not native shell observations.
Without an override the already selected native PHP is resolved canonically
and must satisfy the same capability checks. The finite Bash nonsecret
quarantine descriptor reservation necessarily precedes PHP capability refusal.
Authority/export/helper/runtime/credential mutations do not.

The PHP 8.0/8.4 unit matrix and Composer platform 8.0 are unchanged.
Composer install, `qa:unit`, ordinary safeguards and package/checker utilities
retain matrix PHP. The existing pinned setup action provisions a separate
canonical version-specific PHP 8.4 companion before restoring matrix PHP;
its path, identity and hash are checked again afterward. Only the host-specific
untrusted source/ZIP mock fixtures and controller helpers select that companion.
Those positives are not PHP 8.0 coverage. The two E2E and three compatibility
runtime jobs explicitly select host 8.4; package/release keep host 8.2.
Host paths are never substituted for container `php`. The container stage
separately refuses absent builtin `fsync` before fixture mutation, without
changing the plugin or container PHP floor; unsupported floor-runtime proof
therefore remains blocked, not silently skipped.

The original controller descriptors and their birth identities remain held
through terminal release. Every verification rejects active output buffering,
flushes actual STDOUT/STDERR writers and held duplicates, checks `fsync`,
bound-path identity, empty bytes and original writer position. The last
in-memory strict-success guard runs after release prerequisites immediately
before the unchanged terminal unlink. Same-byte replacement, unexpected
precommit bytes and observed append/truncate refuse; an external writer that
appends and truncates between observations without moving the original writer
position is not claimed observable. No postcommit diagnostic becomes a new
release prerequisite, and a replaced/unlinked path does not mean all originals
remain at their names. Readiness already written stays immutable; subsequent
nonzero QA cannot promote.
Local/fake-stage tests and green
CI on a predecessor without this stage do not establish provider/catalog/HTTP,
floor, or original-ZIP proof. Later summary/full response-selection composition
also requires separate integration and actual proof; no speculative selection
input is sent by this stage.

`tests/untrusted-stage-test.sh` and `scripts/test-release-runtime.sh` use the one
`untrusted-host-boundaries.php` fixture to install exact, reversible substitutions
only in their disposable copied checkout, before its future fixture commit.
Endpoint/peer/executable identity and named interruption boundaries are
**synthetic**; replacement counts and exact preimages must match, the real
checkout must have no bypass, and a substitution/unchanged-remainder ledger is
retained. These controls retain the real source
provenance producer, native host stdout/stderr capture, exact output framing,
public-proof parsers and outer retention helpers. Their topology/site records
are synthetic, including prepared-target and resource-absence records: passing
them does not prove native daemon admission, a real provider, container journal
or WordPress cleanup. A ZIP built after fixture construction remains bound to
that synthetic source, never relabeled as a genuine candidate ZIP; its original
bytes must remain unchanged throughout each lane.
Both scripts report native-Windows positive execution as `BLOCKED` with
nonzero exit 78, rather than skipping assertions or claiming a positive result.
Cases preserve the first failing exit, private original bytes, ordered 286-case
inventory and all inherited destructive-stage outer assertions. Expected
transport, malformed JSON, valid-JSON semantic, partial journal/spool, pending
parser and unexpected process-output failures must prevent finalization and
outer teardown. Only the fully validated synthetic success path retires.

The first positive package-runtime invocation has a **test-only failure
diagnostic**, installed only in the copied controller's outer terminal catch.
An explicit per-invocation opt-in writes a separate `0700`
`WORK/first-package-diagnostic` sibling, never the authority directory,
`GITHUB_OUTPUT`, or production's three-file export. Its exclusively created
`0600` single-link `terminal.json` has the closed shape
`{"version":1,"scope":"synthetic-only","reason_code":17,"exception_exit":78}`.
The 36 exact codes in `tests/unit/fixtures/untrusted-runtime-diagnostic.php`
map only static topology reason strings; a source characterization requires
coverage to match the actual topology reasons. Code `0` means unmapped within
eight exception-chain nodes, not an inferred cause. No original exception
text, stack, path, environment or site payload enters this record.

The writer checks writes, flush, builtin `fsync`, bounded readback and close;
the reader checks close as well as canonical JSON, enum/types, original exit,
ownership/mode, regular-file/link and before/after identities. Record output
is capped at 1 KiB; readback refuses files over 4 KiB and reads at most 4097
bytes. Reader stdout/stderr are captured privately. Only an exact bounded
integer can become a public `topology_code`; other public states are
`unmapped`, `not-observed` or `refused`. The original package exit remains
authoritative even when diagnosis or public reporting fails. Reporting errors
attempt only a fixed safe stderr notice; failure of that notice is also guarded,
and neither can replace the original package exit. Internal returns/native failures
may bypass the outer catch; a missing record does not distinguish that from
a writer failure before creation. These states are not QA success or origin
proof, and no actual failure cause is inferred without its observation.

`UntrustedRuntimeDiagnosticTest` separates pure projection/source checks from
real Linux filesystem/process controls, including synthetic I/O fault seams.
Its 37-control native inventory retains the existing 36 controls in order and
appends a real `/dev/full` diagnostic-stdout failure with private stderr capture,
requiring the original exit `78` rather than the reporting command's failure.
The native cases require a real Linux PHP 8.1+ interpreter (the fixed
`/usr/bin/php8.4` companion already verified by the Unit workflow in the PHP
8.0 matrix, with no PATH fallback); a non-Linux skip is **not** positive
coverage. No startup-stream hard quota, whole-host attestation, artifact
upload, or survival after hosted-runner teardown is claimed. Existing private
failure retention and successful-path assertions remain unchanged.

Retain the following evidence independently of static registration/manifest
coverage:

| Contract | Required verification |
| --- | --- |
| Record-local markers | Each affected successful record has unique relative names for only present fields; null/empty values count, absent keys do not. Compare against the [field table](../../README.md#untrusted-result-fields-30-unreleased), not a global list or dotted paths. |
| Value/type preservation | Remove only the newly added record marker for comparison with the original normalized response; retain exact HTML, Gutenberg delimiters, attributes, quotes, backslashes, arrays/objects, empty values and null. Do not strip content or coerce types to make a test pass. |
| Container values | Provider `metadata`/`raw_meta`, block `attrs`, and sitemap `entries` retain their original maps/values; markers belong on containing records. |
| Standalone metadata | `get-post-meta` marks `meta` on the containing data record; `update-post-meta` marks `meta_key`, `previous_value`, `current_value`; `delete-post-meta` marks `meta_key`. Nested stored maps and existing object/key authorization remain unchanged. |
| Content patch results | `patch-content-block` marks data `content`. `patch-post-content` marks present `heading_text` on `data.target`, or an empty array for an exact-match target. Retain the nested post markers and exact original patch result values. |
| SEO/site overview records | Check present `url`/`entries` on `sitemap`, and `url` on `robots_txt`; no paths or global marker replacement. |
| Permissions and omissions | Keep allowed and denied role/object cases. Lower-privilege user lookup must omit both restricted values and their marker names. SEO-denied keys stay absent; preserve `unavailable_fields` and `unevaluable_checks`. Markers add no comment privacy policy. |
| Diagnostics | Require exactly `Focus keyword found in title.` / `Focus keyword not found in title.` for those branches; exact authorized keyword values remain in metric fields. No markers in canonical errors or diagnostic error subrecords. |
| Removed head output | Assert `generated_head.available:false`, unsupported URL-only inspection and no provider head calls. Do not restore opaque HTML/JSON as an injection fixture. |
| Gateway result data | Actual HTTP success retains Adapter's existing wrapper and record markers; errors remain `isError:true` with one canonical JSON text block and no wire `structuredContent` (internally null). |
| Discovery and individual tools | Default `tools/list` exposes the three gateways; use get-info for each ability's metadata. Capture actual individually exposed Adapter 0.6.1 `readOnlyHint`/`destructiveHint`/`idempotentHint` and compare with registered `readonly`/`destructive`/`idempotent`. |

Retain original responses privately while validation or recovery is unresolved,
and retain public version digests, context, hashes and verdicts for both failures and
successes. Fully validated retirement removes the owned container wire spool,
not independent host receipts/transcripts. Hosted-runner disposal still limits
private recovery lifetime; public artifacts are not copies of private originals.
Marker/annotation checks do
not prove model resistance to prompt injection. The five destructive-operation
confirmation interlocks are a separate unreleased 3.0 layer, and optional text-only output
is not implemented. Existing #110/#118 no-write, key-authorization, and error
transport assertions must not be relaxed for marker addition.

## Comment moderation regression coverage

`comments-fixture.php` adds `wstm105_*` fixtures and comment-specific checks. Its `wstm105_moderator` actor has the actual `comment_moderator` role with only `read` and `moderate_comments`. Cases cover all four writes, optional update statuses, Author moderation-floor denials, mapped-CPT allowed/denied controls, own-draft moderation, Administrator access, orphan comments, and missing/nonpositive IDs. Existing Editor cases and every landed main manifest case remain unchanged; runtime registrations remain the coverage authority.

`assert_comment_state` requires `comment_id`, `content`, and `status`. It reloads the comment after execution and checks both persisted fields, even when the expected result is failure or the response assertion already failed. Security QA requires this evidence for the four moderator-only denials.

The contract runner also invokes both registered callbacks directly for authorization, malformed input, capability filters, and core orphan behavior. Missing objects retain their execute-callback errors rather than becoming permission failures. Expected core permission notices are not suppressed; only notification emails for deliberately orphaned fixtures are disabled.

The CLI-only `comments-runner.php` adds 308 cases across direct execution (104), the actual WordPress ability wrapper (104), and authenticated MCP HTTP (100). It retains raw HTTP tool results, exact error messages/codes, effective capabilities, per-comment content/status, and before/after hashes of all comment and commentmeta rows. Failed calls must leave both tables unchanged. Global-comment controls cover both direct execution and the ability wrapper; the contract fixture also calls the permission callback with a populated global comment and a zero ID. Negative-existing-ID controls cover all three boundaries. HTTP fixture application passwords are revoked. The runner refuses web access before WordPress bootstrap; artifact write failures are fatal.

Contract and HTTP lanes run their respective boundaries via `WSTM105_BOUNDARY` and retain `comments-direct.json`, `comments-ability.json`, and `comments-http.json` for seven days, including failed runs. For baseline comparison, use this identical runner with `WSTM105_MODE=baseline` against the old registered callbacks on a disposable site, and a separate `WSTM105_ARTIFACT` path. Baseline mode records the old authorization/global-comment bugs rather than asserting the fix; malformed direct calls without a stable historical contract are fixed-only. Compare the 152 cases marked `compatibility` without normalizing away raw envelopes or error codes. Never install old callbacks on a shared/live site, and preserve baseline/failed calibration artifacts outside `e2e-artifacts` before a fresh suite clears it.

HTTP session-close and application-password revocation failures are recorded individually under `cleanup_errors`, increment the failed count, and do not prevent subsequent cleanup or evidence writing. Existing case failures remain intact and the runner exits nonzero. Unit regressions inject both transport-close and credential-revocation failures to verify this behavior.

## Shared runtime and coverage

### SEO keyword data/message separation (existing partial #108 coverage)

`tests/fixtures/seo-analysis.php` supplies five inert-marker scenarios to the unit tests, ability manifest fixtures, and existing MCP HTTP CRUD runner: Yoast found/missing with a competing SEOPress value, SEOPress found/missing after empty-Yoast fallback, and no keyword. Contract cases compare the complete `good` and `issues` arrays (including check IDs, severity, and every diagnostic message), exact keyword/title metrics, provider source, and score through existing `assert_values` placeholders. All earlier cases, including permission negatives, remain intact.

The HTTP runner creates a separate owned SEO post, updates plain content and each authorized metadata key in separate calls, confirms stored metadata, executes SEO analysis through the actual MCP gateway, and retains each response in `mcp-crud-summary.json` under `seo_analysis`. It also rejects inert markers in every diagnostic message. The original CRUD post remains scheduled for its existing future-post deletion scenario; an extra read verifies its state before deletion. Dedicated SEO cleanup runs even after a case failure, records its response, and fails the summary for unsuccessful cleanup, wrong IDs/statuses, or exceptions. Unit tests additionally cover exact markup, quote, and backslash retention for both providers and both branches. Preserve those assertions while accepting the 3.0 additive metrics marker; all original authorized metric keys remain. Run `composer qa:unit -- --filter SeoAnalysisTest`, then managed `scripts/e2e-test.sh all` for actual WordPress and transport evidence.

This existing suite covers separating focus-keyword data from diagnostics,
not the complete field-marker or emitted-annotation contract. Use the separate
unreleased 3.0 coverage above for #108; neither is a prompt-injection prevention
test. Inert fixture text is distinct from the `untrusted_fields` metadata key.

The harness disables request-triggered WordPress cron before installation and fixture setup in its disposable QA installation. Otherwise, HTTP health checks can start background tasks such as enclosure cleanup while a regression compares whole-database snapshots. Scheduled events and explicit calls to core's future-publication guard remain enabled and asserted; no production plugin setting or no-write predicate is changed. Use a fresh owned runtime, since setting `DISABLE_WP_CRON` does not stop a cron process that is already running.

Compatibility lane artifacts retain both runtime metadata and the detailed `e2e-artifacts/` reports for 30 days, including failed scheduling cases. A failed or unavailable lane still blocks promotion.

Before source-bound QA, each compatibility runtime lane records its generated
baseline JSON in an ordinary local-only commit. The current-checker job does the
same for its baseline JSON, Compose image and `Tested up to` metadata. Unexpected
tracked changes, staged inputs and untracked inputs outside the artifact directory
fail before staging; no source-proof exception permits dirty harness or production
files. A no-change proposal retains the original HEAD and records `changed: false`.
`compatibility-artifacts/tested-source.json` separates the discovery base SHA from
the actual tested commit/tree, and export verification uses the latter. These
disposable commits are not published or reused as the final update-PR candidate,
which still requires its own exact-commit QA and existing promotion safeguards.
`tests/compatibility-candidate-test.sh` extracts and executes those actual workflow
steps in fresh local Git fixtures, including unchanged inputs, dirty baseline-only
harness data, a real `Tested up to` delta and rejected foreign/staged/untracked
inputs or receipt collisions. It checks the real provenance entrypoint before and
after candidate creation and retains its native outputs and fixtures under a fresh
temporary directory. It runs through the existing compatibility dependency-policy
CI safeguard; these local shell/provenance cases are not WordPress runtime proof.

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

Seven additional manifest cases preserve the earlier diagnostic cases and add default/false/private and true/raw modes, Subscriber raw-opt-in denial, and string/numeric/null rejection. `setup.diagnostics.table_privacy` replaces only the table-size metadata SELECT with deterministic core/custom/core-lookalike rows. Assertions compare every projected field; the opt-in case uses actual-prefix fixture placeholders. WordPress accepts boolean-like strings/numbers at schema validation, so the direct strict-boolean guard rejects them with reason `invalid_input`; null fails schema validation with `ability_invalid_input`.

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
