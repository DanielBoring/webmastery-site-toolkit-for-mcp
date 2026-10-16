# QA harness reference

Detailed behavior of the QA harness: disposable runtimes, product acceptance
fixtures, package QA and compatibility lanes. The policy summary, what runs
when, and CI budgets are in [QA strategy](qa-strategy.md). Change this file
when you change the harness it describes.

## Disposable local and CI runtime

Use a trusted disposable Docker engine and the reviewed images. This is an
environment assumption, not whole-host provenance or an OS security audit.
Do not run the fixtures against production, a shared WordPress installation,
or someone else's Compose project.

The local wrappers create unique project names and output directories.
`scripts/qa-compose.sh` selects the fixed Compose files and verifies opaque
ownership labels before changing containers, networks or volumes. It refuses
foreign resources, caller-selected Compose files, privileged execution and
candidate root execution. The only root operation initializes the artifact
volume with a fixed directory command; candidate PHP and WP-CLI use UID/GID
33:33.

HTTP binds to a random IPv4 loopback port. MySQL has no host port. Source and
installed-package binds are read-only; the engine socket is not mounted.
Before package Compose startup, validate the pristine runtime extraction and
prepare only empty `tests`, `scripts` and `.github` directories plus the three
declared compatibility-support file mountpoints. Reject collisions, links,
unexpected entries and partial preparation; retain failed state without retry
or rollback. Recheck production hashes. The original archive and separate
checker extraction remain unchanged, and all nested support binds stay read-only.
Check bound root, ancestry, captured identities and closed inventories before
and after each creation. A detected change stops subsequent writes without
rollback or foreign cleanup. These pathname observations assume a trusted
disposable host; they cannot make a filesystem call atomic against a concurrent
host mutation. An inside-call swap may leave that call's directory behind,
but its postcheck must refuse further creation.
Private generated credentials and ownership records live outside the candidate
bind. Inside the stack, `/qa-artifacts` has mode 0700 and belongs to UID/GID
33:33. Password initialization reads private stdin without printing a prompt or
sending installation email.
Fresh installation preparation verifies an absent or empty native database
while the owned HTTP service and its health probes are stopped. An isolated
UID33 CLI uses the authenticated image, database network and installed/artifact
volumes without starting Apache. This prevents frontend rendering from creating
navigation fallback content outside the installer observer.
The image contract resolves the owned Compose configuration's WordPress and
database references to existing image config IDs and compares them with the
original containers before and after installation. The installer clones the
admitted WordPress image ID rather than a local cache-specific ID. Missing or
changed references, identities or readiness refuse installation; this does not
authorize new registry images or replace the trusted-engine assumption.
Native permalink auto-detection remains a failed request while HTTP is unavailable; no successful
HTTP result is fabricated. Preparation exports its private originals and retires
the CLI before restoring HTTP. Installation or unresolved CLI cleanup failures
retain the original failure and do not restore HTTP. Normal rewrite verification
and all product, legacy and package HTTP checks run after successful preparation.
Preparation attributes default fixtures to the pinned native initializer.
Remove only those verified defaults and their dependencies through
native APIs; preserve the administrator, taxonomy and unrelated options.
Foreign rows, changed native bytes or missing fresh-install evidence refuse.
Retain preparation snapshots and receipts privately. Persist native before
and insert evidence before comparing defaults, including when comparison
refuses. Incomplete receipts report bounded table counts and hash-associated
query outcomes without exposing row contents or raw SQL errors. An observed
insert attempt alone does not establish a successful write.
Persist captured after bytes before dependency and preservation comparisons.
On refusal, the incomplete private receipt binds that original to a bounded
first-difference diagnostic without exposing values. Missing or unreadable
captures remain failures; diagnostics do not widen permitted changes or replace
rollback, commit verification or complete preparation evidence.
The native calendar deletion hook can create or update its published-post cache
before the authenticated default post is removed. Preparation pins the native
callback bytes and associates the exact calendar query with its result. The
expected cache fields follow native pre-delete semantics; a new option identity
comes from the successful insert result, not from the after snapshot. Existing
cache updates require native write evidence unless the true value is unchanged.
All other option rows remain subject to strict preservation checks.

Database privacy runners write their native and HTTP JSON reports to the
private container artifact volume. The guarded host-export inventory includes
both reports for required workflow uploads. An attempted privacy boundary
requires its report even if the runner fails; missing or unreadable captures
and destination collisions fail without replacing the original runner status.
Verify the exported JSON and its case totals for source and original-ZIP runs;
successful console totals alone do not establish that upload evidence exists.

Before upload fixtures acquire their exclusive child directory, validate the
owned WordPress root and native uploads paths, then establish missing ordinary
uploads/date directories through the WordPress API. Recheck exact paths,
symlink-free ancestry, access and parent custody after creation. Native errors
or changed configuration refuse before fixture acquisition. Ordinary uploads
directories are not destructive fixtures; cleanup owns only the journaled
exclusive child and its verified resources.
Attachment metadata uses a path relative to that authenticated base. Executed
regressions cover acquisition through metadata writing for source and package
contexts; they do not replace installed WordPress acceptance.

Output directories are exclusively reserved and never reused. Failed runs
preserve private originals and the original exit status. Teardown rechecks
resource ownership. An unresolved destructive/schema/product retention marker
blocks teardown and restart; a passed flag or successful export alone cannot
clear it. Recover the exact owned resources and verify restoration and absence
before retiring the marker.

## Focused product acceptance

Both source and installed-package runs retain the existing main-branch
security, destructive, schema, metadata, comment, database-privacy and Site Kit
checks. The native manifest has 85 abilities and 601 cases, including all 589
inherited cases and 12 additions; permission-sensitive cases include allowed
and denied roles.
Run product acceptance once after installation, dependencies, authentication
and plugin setup, before legacy suites can populate the content tables.
Untrusted checks run before bounded catalogs. Keep the bounded seed's empty
posts/postmeta prerequisite and every later legacy check.

A failed catalog that never acquired resources can prove cleanup only with an
explicit pre-acquisition record, unchanged native snapshots, a closed artifact
inventory and fresh zero-owned-resource readback. Missing state after acquisition
starts still refuses. Cleanup readiness never changes a failed producer verdict.
The closed export/import contract includes each catalog's existing
`pre-acquisition.json`; unknown files and special paths still refuse.
SEO-score items require record-local markers for present `title`, `url` and
`score` fields without changing their authorized values or types.

| Area | Acceptance |
| --- | --- |
| Untrusted content | All 298 ordered labels: 104 gateway calls, 104 individual-tool calls and 90 actual annotation observations. Check record-local present-only markers, unchanged authorized values/types/HTML, canonical errors, privacy and role denials, stored-content/message separation and actual MCP hints. |
| Shared helpers | All 12 helper rows retain permission and lookup/type order, errors/response shape, normalization/defaults, scheduling/slashing, plugin inventory, SEO tables and pagination. |
| Bounded lists | Recompute all 336 large and 1125 small observations, exact order, empty-window continuation, authorized summaries/full/revisions, reference parity and injected failure controls from the retained raw records. |
| Post extraction | Six owners retain callbacks, schemas, permission checks and dispatcher/bootstrap wiring without changing ability names. |

The large benchmark uses 20,000 posts, 10,000 fileless attachments and 100
selected 55-KiB post bodies. Seven surfaces, two request-local cache regimes,
three authorization regimes, page sizes 20/100 and four predetermined windows
produce 336 observations. The small catalog checks full eligible sequences at
page sizes 1/20/100, filters, ties, full/default responses and EOF independently
of candidate output.

For page size P, candidate objects are bounded by P+1, distinct authorized
objects by P, capability calls by 5P+10, and SQL by 3P+12 plus the documented
orphan-reference allowance. Each candidate query uses IDs, `no_found_rows`,
P+1 and the correct offset. Reference batches contain at most 50 patterns;
thumbnail lookup uses at most one IN batch. No mutation is allowed during
measurement. Conservative incremental allocated memory must stay within
64 MiB; controlled default-20 content summaries must stay within 64 KiB.
Do not subtract a historical memory peak or describe request-local cache
flushing as a fresh database or process.
Reference-parity probes capture each original query once immediately before
fault injection, after native placeholder handling. Injection requires exact
equality with that observation; replay still checks original/replacement SQL,
failure outcomes and reference associations without query normalization.
List-query failure regressions cover native priming failures followed by
successful reads that clear `last_error`. A scoped observer retains only the
current-read failure flag and is removed in `finally`; it adds no SQL and
preserves stale-error warm-cache hits. Native reproduction and unit doubles
remain distinct from full source and installed-package acceptance.
Cache-fault evidence binds a completed failed query to its injected replacement
hash and query counter before a later read clears the error, or at the final
query boundary. Replay requires that closed witness, not an injection attempt
or a nonempty final error alone. The final observed error remains separate.

Actual product runners use an exact 12-field `wstm-product-context-v1` record
at `/qa-artifacts/context.json` and matching `WSTM_PRODUCT_RUN`,
`WSTM_PRODUCT_TOKEN` and `WSTM_PRODUCT_CONTEXT`, with `E2E_OPT_IN=1`.
The context binds a source inventory or original-ZIP SHA256, the owned project,
fixed internal roots, UID 33 and expected actual PHP major/minor. Its
`php_floor` field is an actual-lane value, not a support-floor claim: 8.0, 8.1,
8.2 and 8.4 remain distinct observations. CLI and authenticated HTTP workers
must match the selected lane exactly.

After checking WordPress and database ownership, the shared product guard uses
the normal `get-post` ability lookup to initialize lazy toolkit registration.
It requires the ability to exist without executing it, then checks activation,
loaded production roots and source stability. A fresh CLI process must satisfy
these checks independently of any earlier HTTP request.

Test credential creation supplies slashed names to the native WordPress API so
metadata unslashing preserves the original intent. Enrollment and recovery
still require exact actor, name and UUID agreement. An unresolved credential
intent blocks cleanup and acceptance; it is not evidence of absence.

Live credential names retain instruction-looking text, quotes and Unicode,
but omit backslashes because native usage updates strip them. Dedicated
boundary regressions cover backslashes separately. Before native MCP session
reads, refresh only the owned actor's metadata cache and retain exact token,
client-name and actor checks across enrollment, recovery and absence checks.

The owned product fixture registers the individual server through the normal
Adapter initialization hook, using native transport permissions and exactly
85 native plus five owned CPT abilities. Capture setup responses before
validation, but enroll sessions only after HTTP, fixture and typed JSON-RPC
request/response checks pass. Host replay independently checks all six client
setups and their catalogs. Failed evidence binds the complete final journal
and remains failed; closing its wire scope does not authorize cleanup.

Metadata expectations distinguish zero stored rows, one empty-string row and
multiple rows through independent native row observations. Provider availability
uses the same actor's effective native per-key read capability at the restricted
HTTP boundary. A standalone writable-key allowlist denial is not proof that a
provider field is unreadable. Keep inspect-only fields, exact typed comparisons,
denied-key privacy and no-write controls.
Address observations by actor, post and metadata key, so list calls can visit
multiple owned posts without overwriting another post's evidence. Identical
repeated decisions for one tuple are idempotent; conflicting decisions refuse.
Replay score observations against the original owned-post ledger and require
the primary post's allowed score-key observation.

The protected runtime endpoint returns a closed observation without the raw
token; missing or incorrect tokens receive 403. The product-only MU fixture
is active only during its owned stage and removed before checks that expect
exactly 85 native registrations. Do not precreate or reuse the two exclusive
per-run stage directories.

Raw responses, headers, SQL, credentials, resource ledgers, context and verdicts
stay private. Untrusted output and wire domains are separately bounded at
128 MiB, with at most 4096 wire frames and 32 MiB per file/frame. Bounded output
has at most 4096 paths, 32 MiB per file and 512 MiB attempted writes. Final
export limits do not prove attempted-write accounting or semantic completion.
Private file reads allocate from the locked, identity-checked observed size,
not the per-file ceiling. Reservation equality, before/after identity and size,
exact read length and expected-content checks still apply. Executed reader
controls cover empty and near/exact-limit inputs, mutation refusals and the
unchanged 128 MiB memory cap; they are not full campaign acceptance.
File observation and creation readback use the same guarded reader. Updates
read through their existing exclusive-lock handle without reopening or
downgrading the lock, and compare expected bytes and hashes before and after
persistence. Conflicting locks refuse; the per-file and domain budgets do not
change.
Public summaries contain only validated stage states, actual lane/UID and
known outcome counts. Retain incomplete records privately; missing observations
never count as passing.

Retain each original response and case record once, and one complete final
summary, rather than capturing the growing cumulative summary after every
case. Independent replay must still account for every ordered label and its
original request, response and oracle. Charge journal/index overhead and
reserve terminal failure evidence within the existing wire domain; do not
increase, reset or refund its limits. Progress is not an acceptance verdict,
and failed historical packets remain unchanged.

The terminal summary references ordered original case frames by label, outcome
and serialized digest; replay rehydrates and checks the complete summary.
The summary commitment uses an ordered typed value stream rather than
whole-graph PHP serialization. Shared descriptor objects therefore commit to
their exact repeated values without depending on aliases lost between separate
frames. Preserve scalar/key types, container shape and entry order; reject
unsupported values and retain the mandatory scope seal comparison.
Charge expanded work before hashing: at most 131072 visited values including
keys, 32 MiB of encoded bytes and depth 512. Reject cycles and exhausted
reservations with controlled content-free errors, without allocating an
expanded tree. These parser-work bounds do not increase artifact quotas.
Compare private PHP case inventories and original case values with the same
bounded typed commitment. Native comment snapshots may contain NUL-prefixed
protected-property keys; preserve those keys without a JSON object round trip.
HTTP payload canonicalization, original serialized case hashes and strict
before/after no-write equality remain separate requirements.
The version-2 private index accounts for HTTP journal bytes and monotonic
index growth. Reserve one existing frame slot and 32 MiB of the existing wire
budget for terminal evidence, plus 4096 bytes for retirement transitions.
These reservations reduce available working space rather than add quota.
Native read-capability observations are fixture-only HTTP records; reject
missing, foreign, duplicate or ambiguous actor/post/key associations.

Bounded runtime and the live-absence helper share the owned QA configuration's single
unconditional literal-true cron definition before bootstrap. It does not
redefine the constant or suppress diagnostics. Check effective cron policy,
absence of cron work and unchanged configuration custody after bootstrap and
absence reads. Unsupported configuration shapes refuse before bootstrap.
An unconditional top-level definition may follow a completed closing brace.
Nested or unbraced conditional definitions, namespace declarations, duplicate
definitions and nonliteral or false values still refuse.

## Contract and transport boundaries

Contract QA executes the manifest through registered WordPress abilities.
It also boots separate PHP processes with actual `EMPTY_TRASH_DAYS=30` and
`0`, verifying enabled trash/restore and refusal before permanent mutation.
These direct configuration checks do not substitute HTTP transport evidence.

Full MCP E2E uses Application Passwords, initialized HTTP sessions, `tools/list`,
gateway/individual tool execution, actual mutations and lower-role denials.
The boundaries have distinct validation/error ordering and must be reported
separately.

Database privacy retains exact metrics/order across its three actual responses.
A read-only 15-second expiration horizon shares one 90-second deadline and uses
production timeout conversion. It does not record transient identities/values,
resample failed payloads or tolerate metric drift. SQL/window/deadline failures
remain failures with original responses retained.

## Local commands

Use the wrappers for fresh owned projects:

```bash
scripts/qa-local.sh
scripts/qa-local.sh --contract
scripts/qa-local.sh --e2e
scripts/qa-local.sh --contract --e2e --package
scripts/qa-local.sh --publishing
scripts/qa-local.sh --release
```

```powershell
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract -E2E -Package
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Publishing
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release
```

`composer qa` runs local static/unit checks. `composer qa:local`,
`composer qa:package` and `composer qa:publishing` run their respective runtime
scopes through the shared launcher without repeating static/unit checks.
Runtime Composer scripts disable only Composer's process timeout; CI jobs retain
finite limits. Raw Docker commands require an
explicit unique `COMPOSE_PROJECT_NAME`, managed Compose and fresh output.
For example:

```bash
COMPOSE_PROJECT_NAME=wstm-contract-mytest E2E_MANAGE_COMPOSE=1 \
  E2E_ARTIFACTS_DIR=e2e-artifacts-wstm-contract-mytest \
  COMPOSER_PROCESS_TIMEOUT=0 composer qa:contract
```

Choose a new name for each run. Do not overwrite retained evidence or delete
unknown build directories. Composer's timeout override permits a full local
runtime run; GitHub jobs retain their own finite limits.
Run actual runtime campaigns serially within one checkout. Different Compose
projects still share candidate/package support and the checkout-wide retention
interlock; an overlapping campaign can be refused and retained even when its
own project is clean. Independent CI jobs have separate checkouts.

Git Bash container commands retain `MSYS_NO_PATHCONV=1`; native host PHP calls
remove it for Windows path conversion. Docker Desktop must be running.
Fresh image/plugin setup needs network access. Missing tools or failed setup
must be reported, not converted to skipped acceptance.

## Original-package QA

Build once or use `RELEASE_ZIP`, validate the production allowlist and hashes,
then reserve `build/release-qa-<opaque owner>/{checker,runtime}`. Neither path
overwrites a prior extraction. Runtime uses the installed original package,
read-only test/compatibility support and a private artifact volume, without a
checkout-wide or vendor overlay on production contents.

Plugin Check reads a separate pristine extraction at `/qa-package-pristine`;
nested test-bind placeholders cannot alter its findings. Recheck installed
production hashes, the finite allowed empty placeholders and original ZIP
SHA256 after runtime QA. Source-only success does not establish package behavior.
Release safeguards mock external orchestration/failure boundaries; they do not
replace real WordPress, HTTP, benchmark or package acceptance.

`composer qa:package` runs product checks alone. `composer qa:publishing`
prepares an owned stack and runs Plugin Check alone, explicitly reporting that
contract/HTTP product acceptance did not run. `composer qa:release` combines
both for release verification. Report product and checker findings separately.
Plugin Check can find defects relevant to users, but its directory-specific
requirements are publishing checks, not evidence that plugin behavior works.
The manual `09-publishing-checks.yml` workflow reports checker verdicts independently.
The protected release workflow retains its combined command and prerequisites.

## Compatibility and release readiness

The maintained compatibility campaign separates the WordPress 6.9/PHP 8.1 lane,
pinned baseline, latest stable adapter/WordPress combinations, PHP 8.4,
MySQL 8.4, floating SEO plugins and current Plugin Check. Resolve exact tested
versions, refuse unavailable images/dependencies and propose only successful
updates in maintainer-reviewed PRs. Do not auto-merge baseline promotions.

The PHP 8.1 WordPress compatibility lane does not prove the advertised PHP 8.0
minimum. `06-minimum-runtime-qa.yml` separately builds actual PHP 8.0.30 with
WordPress 6.9 from digest-pinned bases and signed frozen Debian packages.
This EOL stack is a disposable compatibility fixture, not a production
recommendation. An image build alone is not runtime acceptance.
Genuine 8.0/8.4 syntax/unit evidence, actual CLI/HTTP observations and package
results remain distinct.
Do not raise a support floor, fabricate an image tag or label PHP 8.1 as 8.0
to close that gap.

Before a plugin release, follow the readiness checklist in
[release strategy](release-strategy.md): the tagged commit needs passing main
QA, including package and minimum-runtime QA, and the release job runs Plugin
Check on the built ZIP. `composer qa:package` alone does not establish
publication readiness.

For a WordPress.org submission or publication, separately review Plugin Check
findings and the
[WordPress.org guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
Licensing, bundled assets, external services/consent, executable code, readme
claims, default libraries, SVN/version discipline and trademarks remain
maintainer responsibilities. Known warnings must be documented. Plugin Check
does not establish directory approval or listing, and a product-QA pass does
not authorize publication.

## Retained local evidence

A failed Adapter compatibility campaign left a retained stack and private
evidence in the maintainer's local checkout because its resource ledger did not
establish cleanup. Do not clear that checkout-wide retention marker, or rerun
runtime/package QA in that checkout, until a reviewed recovery proves cleanup.

## Area-specific review checklist

Check the items that apply to your change. These were the PR template's
detailed checklist before it was shortened; they record lessons from earlier
changes.

- [ ] Capability checks use the narrowest relevant WordPress capability and return/surface `WP_Error` on failure
- [ ] Delegated permissions retain a local WordPress floor plus upstream authorization in both entry paths, fail closed on missing/non-callable upstream checks, and distinguish fixture evidence from version-specific real-provider inspection
- [ ] List/query abilities authorize every returned object, preserve order and empty-window continuation, and do not leak unauthorized totals or sensitive identity fields
- [ ] Bounded list changes retain measured candidate/query/capability, memory and response-size budgets, stable ID ties, summary omission versus exact full values, effective object/key authorization, and independently selected expected windows; unit/static evidence is distinct from real source/package measurement
- [ ] Extracted-owner changes retain current-source dispatcher/bootstrap/callback ownership, full/summary marker composition, and behavior-preserving regression assertions without changing ability names or support floors
- [ ] Direct SQL follows the diagnostic policy or narrow attachment-reference exception: existing caller/object authorization, prepared candidate-ID/literal-pattern batches, unchanged reference scope, no referring-content/ID/count disclosure, and fail-closed query errors even with force; retain permission, parity, escaping, budget, and no-write/failure tests
- [ ] Security-sensitive abilities include allowed and denied E2E manifest cases, plus `assert_missing_paths` for fields hidden from lower-privilege callers
- [ ] Metadata changes preserve pre-mutation combined-input rejection, real-object effective key authorization, and direct/ability/gateway/individual-tool no-write/no-forbidden-read proof, with plain-content and draft/write/publish migration controls
- [ ] Destructive changes retain exact confirmation, raw bulk bounds, strict optional flags, unchanged-state/hook/cron preview and denial proof, distinct callback/per-item permission evidence, and fail-closed media reference scans even with force; migrated callers retain their original assertions
- [ ] Destructive runtime wiring retains eight serial boundary/trash modes, native audit before test MU capabilities, actual CLI/HTTP config parity, exclusive evidence before credentials, exact owned restoration and original failure status; mocked stages do not replace source/original-package proof
- [ ] Schema stage retains three contract/two HTTP selections, 152 exact cases per invocation, no config writes, original HTTP restoration and owner/source/project-bound cleanup proof; retained posts also retain actors/credentials and block outer teardown until proven safe
- [ ] Inputs are sanitized or validated (`sanitize_text_field`, `sanitize_key`, `absint`, `wp_kses_post`, enum validation, or an equivalent WordPress API)
- [ ] Production array literals use PHPCS-enforced `[]`
- [ ] Fixed input objects are closed, raw permissions and execute callbacks reject malformed types/enums/nulls/unknown keys before work, intentional maps remain open, and mutation/no-query/no-write controls preserve exact boundary-specific errors and hierarchical detach/omission
- [ ] Native input validation delegates to core once before strict types, preserving core errors/empty schemas/normalization/lifecycle, genuine permission denials and direct destructive diagnostics; zero-work evidence does not claim zero core hooks
- [ ] Permission-default changes retain null-only exact-object-root materialization before one parent permission call, no normalization-filter replay or raw-callback relaxation, and Adapter empty-object/allowed-denied/nested-null/invalid-default/lifecycle/reentrancy/privacy controls
- [ ] Successful values/types are preserved except for documented contract changes; failures use canonical code/reason/message/object-details, native permissions retain `WP_Error`, and MCP gateway/individual-tool errors plus foreign-namespace isolation are covered (real HTTP errors have `isError:true`, one JSON text block, and omitted wire `structuredContent`, internally null)
- [ ] Untrusted-field changes preserve exact values/types/markup, use unique present-only record-local names, retain privacy omissions and unavailable SEO fields, and never mark canonical errors or diagnostic error subrecords
- [ ] Annotation changes compare registered hints with actual Adapter individual `tools/list` output; distinguish gateway/get-info metadata, unit/source checks, and real runtime evidence
- [ ] Runtime QA uses an explicitly owned disposable project with loopback HTTP, no database host port, generated private credentials, read-only source/package mounts, separate private artifacts, and actual nonroot candidate CLI/HTTP workers; no shared/live installation or assumed ownership
- [ ] Product runners require explicit disposable opt-in and an exact versioned context; capture original wire/stdout/stderr privately before parsing, retain each original response/case once and a complete final summary within unchanged limits including journal/failure reservations, independently distinguish metadata row cardinality and native provider read capability, validate owned fixture cleanup, and preserve partial evidence and the original failure status
- [ ] The trusted local/hosted Docker engine and image initialization are documented environment assumptions, not whole-host provenance or attestation claims; no candidate code runs as root and cleanup affects only verified owned resources
- [ ] New ability names use the `webmastery-site-toolkit-for-mcp/` prefix and set accurate `annotations` (`readonly`, `destructive`, `idempotent`)
- [ ] If this PR adds or changes `webmastery-site-toolkit-for-mcp/*` abilities, `tests/e2e/abilities-manifest.json` includes matching positive and negative cases where permissions apply
- [ ] User-facing changes update relevant docs (`README.md`, `readme.txt`, `tests/e2e/README.md`, or other affected markdown)
- [ ] Plugin-facing changes update `CHANGELOG.md` under `## Unreleased`
- [ ] Repository, CI, contributor, GitHub platform, template, or agent workflow changes update `.github/REPOSITORY_CHANGELOG.md` under `## Unreleased`
- [ ] Local QA checked with `composer qa` and relevant Docker/release wrappers, including original-ZIP checks for release-bound changes, or missing tools/runtime blockers are documented above
- [ ] Development runtime QA ran where the session executes; engine, genuine PHP lane, package results and any blocked checks are recorded separately from WordPress.org publishing findings
- [ ] `composer phpcs`, E2E manifest validation, relevant E2E QA, and `git diff --check` ran, or missing tools/runtime blockers are documented
- [ ] PHPStan level 5 passes without new baseline debt; resolved entries/counts are removed or lowered, and baseline changes follow the ratchet policy in `docs/qa-strategy.md`
- [ ] Genuine PHP 8.0/8.4 interpreter evidence is distinguished from PHPStan's PHP 8.0 analysis target and the separate WordPress 6.9/PHP 8.1 runtime compatibility lane
- [ ] Compatibility QA was considered for release candidates, dependency-sensitive changes, or WordPress/PHP support changes
- [ ] Workflow/shell changes pass dedicated workflow linters and applicable safeguard regressions; the unit aggregate requires all applicable unit/safeguard jobs and fails on failure, cancellation, or unexpected skip
- [ ] Required PR checks use the exact final head and do not rely on manually dispatched runs, failure-induced skips, disabled protection, or local-only evidence
- [ ] Dependency updates preserve the declared PHP support floor, verified download digests, and baseline metadata; unavailable candidate lanes cannot promote versions
- [ ] CI/CD, security, or release process changes update the matching strategy document when policy changes
- [ ] Release-impacting changes account for GitHub tag `vX.Y.Z`, WordPress.org SVN tag `X.Y.Z`, protected `wordpress-org` GitHub production approval (not WordPress.org staff review), and package/readme/version alignment
- [ ] Release changes preserve the exact validated artifact through approval and publication, and cover partial-publish recovery without rewriting release tags; recovery dispatch requires a separately authorized annotated control tag in reviewed main history, never a branch or a normal release-tag dispatch; fresh validation locks the original immutable source/tag and seals new run/control provenance instead of relabeling failed or missing evidence
- [ ] WordPress.org Detailed Plugin Guidelines were considered for public-facing or release-impacting changes such as naming, readme text, privacy/external calls, licensing, bundled assets, and release packaging; see `CONTRIBUTING.md`, `docs/security-strategy.md`, and `docs/release-strategy.md`
- [ ] E2E QA is passing, or failures are unrelated and explained above
- [ ] Scoped privileged native observations are explicitly enabled only on approved disposable CI hosts; keep controller/capture/PHP nonroot, fixed verified system argv/proc targets, clean environment/closed stdin, bounded exit/EOF/original retention, complete fd/listener/namespace/mount/start-identity/race predicates, unchanged grants/custody and a reviewed exact-byte outer projection; distinguish adapters from native sudo/runtime/CI acceptance and cooperative I/O deadlines from a root-child cleanup certificate
- [ ] HOST package repair is scoped to release-package-qa (PHP8.2), ability-contract-qa and full-mcp-e2e-qa (PHP8.4) on owned disposable GitHub-hosted runners; verify signatures/fingerprint and source archives, distinguish closed transaction authorization for incoming/retained maintainers and triggers from refreshed installed owner/version/digests and configuration eligibility, preserve fixed installation operations and original budgets, distinguish approved normal package/session housekeeping (including other versions) from configuration hardening, scheduler observations and certified root-child cleanup, restrict fixed chmod0644 to CLI php.ini and package-bound mods-available/sockets.ini with its exact conf.d alias, preserve the permission/inventory chain, withhold candidate PHP on failed acquisition, and retain Python AST plus all three mock-only prerequisite suites without local native provisioning.
