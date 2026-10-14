# QA strategy

QA checks the plugin itself: correctness, permissions, response privacy,
WordPress compatibility, MCP interoperability, data integrity and the behavior
of the installed release package. It starts with syntax, static analysis and
small regression tests, then checks actual WordPress and HTTP behavior.
Permissions, response privacy and cleanup require explicit assertions; a
successful request alone is insufficient.

WordPress.org approval and directory listing are outside the scope of product
QA. Passing these tests does not establish either. Directory guideline review
and publishing checks belong to the release process, with separate results
from plugin behavior and package integrity.

See the [software development lifecycle](sdlc-overview.md),
[CI/CD strategy](ci-cd-strategy.md), [security strategy](security-strategy.md)
and [release strategy](release-strategy.md) for the related policies.

## QA checks

| Check | Command | Required evidence |
| --- | --- | --- |
| `1 - Static QA` | `composer qa:static` | PHP syntax, WPCS, PHPStan level 5 and baseline ratchet, locked dependency audit, manifest/security policy and whitespace checks. |
| `2 - Unit Tests` | `composer qa:unit`, `composer test:ci-safeguards` | Genuine PHP 8.0/8.4 unit tests and separate safeguard matrices. The stable aggregate requires all applicable jobs to succeed. |
| `3 - Ability Contract QA` | `composer qa:contract` | Actual WordPress registration and manifest execution, allowed/denied roles, response shape, persisted no-write checks and clean debug log. |
| `4 - Full MCP E2E QA` | `composer qa:e2e` | Actual HTTP JSON-RPC authentication, sessions, discovery, CRUD, authorization and cleanup through MCP Adapter. |
| `5 - Release Package QA` / `Package QA gate` | `composer qa:package` | Original-ZIP layout/metadata/hash validation and contract/transport/product checks against installed production contents; no directory checker requirement. |
| Minimum runtime / `Minimum runtime QA gate` | `bash scripts/qa-floor.sh` | Digest-pinned WordPress 6.9 on genuine PHP 8.0.30, actual CLI/HTTP lane observations and the same source acceptance tests. |
| WordPress.org publishing checks | `composer qa:publishing` | Plugin Check against a pristine original-ZIP extraction. Setup is not product acceptance or directory approval. |
| Combined release verification | `composer qa:release` | Both original-ZIP product QA and publishing checks; existing publication controls remain unchanged. |
| `6 - Compatibility QA` | `.github/workflows/compatibility-qa.yml` | Scheduled/manual upstream discovery, supported combinations, exact resolved versions and reviewed baseline promotion. |
| Workflow lint | `.github/workflows/workflow-lint.yml` | Actionlint, ShellCheck and zizmor using the maintained pinned lint tools. |

Static QA runs its full toolchain on PHP 8.0 and syntax checks on PHP 8.4.
Unit and safeguard jobs use actual interpreters on both versions and have
separate 60-minute limits. A failed, cancelled or unexpectedly skipped matrix
job cannot produce a successful `2 - Unit Tests` aggregate. Workflow lint is
independent of the local PHP toolchain.

The primary runtime baseline is WordPress 7.1.3, PHP 8.2 and MCP Adapter 0.7.0.
Dependency versions and executable-download digests come from
`.github/compatibility-versions.json`. `readme.txt` retains `Tested up to: 7.1`.
The advertised minimums remain WordPress 6.9 and PHP 8.0.

Memory-heavy modeled replay tests reclaim unreachable cycles and allocator
caches at their fixture boundaries, after owned cleanup on teardown. Keep the
full suite at its existing 128 MiB limit; a focused pass or a higher-memory run
does not establish minimum-runtime compatibility. This test-fixture lifetime
control does not change production readers or product evidence limits.

Native Windows PHP cannot execute the POSIX ownership and mode assertions.
Run those unit checks in a disposable Linux environment instead of weakening
them or treating Windows filesystem behavior as equivalent. Some unit fixtures
write beneath the test tree, so use an owned writable copy inside that
environment, with the original checkout mounted read-only. Do not copy retained
runtime evidence or use the unit copy to bypass the runtime retention guard.

## Suite classification and measurement

`tests/qa-suites.json` accounts for all 109 PHPUnit files, the five Python
regression files and the existing Composer safeguard/runtime entry points.
Its file-level primary-target classification separates 30 production helper,
callback and bootstrap unit files from 79 QA-tooling/model/fixture-oracle files.
Mixed files retain all their tests; neither category is a speed claim.
Production units use doubles and subprocesses, not a real WordPress bootstrap.
For example, `MetadataTransportTest` exercises the QA transport shim and
`InputSchemaLifecycleTest` models lifecycle; neither is actual HTTP acceptance.
Real WordPress contracts, authenticated MCP integration, original-ZIP product
checks and publishing checks remain separate evidence layers.

`python3 -B scripts/qa-profile.py inventory` fails on missing/duplicate files,
unknown categories or safeguard-command drift. It does not modify PHPUnit's
directory-wide discovery or run/select tests. The existing opt-in Adapter
assessment Python tests are inventoried, not silently added to the normal
safeguard command. All existing safeguard commands still run.

For an owned writable Linux unit snapshot, retain the original checkout
read-only and record its exact commit, any uncommitted changes, actual PHP/image
identity and command status. Add `--log-junit` to the unchanged full PHPUnit
command only for an explicit profiling run:

```bash
php -d memory_limit=128M vendor/phpunit/phpunit/phpunit \
  --configuration=phpunit.xml.dist --log-junit /private/unit.xml
python3 -B scripts/qa-profile.py junit /private/unit.xml \
  --source "$EXACT_SOURCE_SHA" --php "$ACTUAL_PHP_VERSION" \
  --scope full --output /private/unit-timings.json
```

Check the PHPUnit exit status before parsing; parsing success is not test
success. A full diagnostic requires every classified class, not proof of
every expected data-provider invocation. Compare test/assertion counts with
the original complete PHPUnit output. Focused reports explicitly record
missing classes and cannot establish full-suite acceptance. Per-test durations
are PHPUnit's recorded case time, including case-level fixture work.
Discovery, shared setup and process/orchestration overhead can fall outside
that time; it cannot separate phases or be summed into elapsed job time.
The converter drops paths, messages, output and data-provider labels, preserves
failure/error/skipped outcomes, and rejects invalid durations and unclassified
tests. Keep raw JUnit private. Measure peak memory under the existing 128 MiB
limit before enabling additional logging in hosted required checks.

For command-level phases, use separate fresh outputs and preserve the command's
original exit status:

```bash
python3 -B scripts/qa-profile.py record --source "$EXACT_SOURCE_SHA" \
  --lane "linux-php8.4" --suite mixed-phpunit --phase execution \
  --output /private/unit-stage.json -- \
  php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml.dist
```

Wrap independently executed setup/execution/cleanup commands separately.
Do not wrap a combined package command as execution-only. The recorder reserves
evidence before execution, refuses replacement and never captures command
arguments or child output into public JSON. Child stdout/stderr remain the
caller's responsibility; do not upload them as profiling artifacts.
Its caller-supplied SHA/lane describe provenance, not automatic attestation.
An interrupted/partial report, or a zero exit from a setup/cleanup command,
is never product acceptance.

Existing Actions timings can be inspected without rerunning expensive QA:

```bash
gh api "repos/OWNER/REPO/actions/runs/RUN_ID/jobs?per_page=100" > /private/jobs.json
python3 -B scripts/qa-profile.py jobs /private/jobs.json \
  --output /private/hosted-stages.json
```

Use the attempt-specific jobs endpoint when inspecting an earlier attempt.
The parser rejects incomplete pagination and mixed source/run/attempt data.
Setup, job-level execution and cleanup/export steps are distinguished; unknown
or composite steps remain mixed/control. Internal package/setup/fixture/cleanup
durations cannot be recovered from one outer timestamp. Cancelled durations
are observed partial time, not a complete-stage estimate.

Initial observations on October 12, 2026, from identical trees at reviewed
`c348804` and merged main `2f9842b`:

| Hosted execution step | PR run 38257385962 | Main run 38269501887 |
| --- | ---: | ---: |
| PHPUnit, PHP 8.0 | 1,789 s | 1,820 s |
| PHPUnit, PHP 8.4 | 994 s | 916 s |
| Safeguards, PHP 8.0 | 527 s | 554 s |
| Safeguards, PHP 8.4 | 374 s | 411 s |

These are repeated-code observations on different commits/events/runners, not
controlled speedup measurements. The failed `v3.0.0` run 38273601311 spent
1,799 s in static/units, 474 s in safeguards and 1,630 s in an interrupted
composite package/checker step before the outer timeout. A prior successful
package step took 1,733 s; that does not prove how long the interrupted
release's package-plus-pinned/current-checker step needed.

An isolated, nonroot local Linux profile of exact main `2f9842b`, using
PHP 8.4.25 and PHPUnit 9.6.34 with JUnit enabled, passed all 4,646 tests and
205,535 assertions in 637.038 s at 106.01 MiB peak, within the unchanged
128 MiB limit. Its 1,921 production-unit cases totalled 11.302 s of recorded
case time; 2,725 QA-tooling cases totalled 526.461 s. The 84 cases in
`UntrustedSemanticReplayTest` accounted for 455.830 s. Recorded case times
totalled 537.764 s, not the elapsed suite duration. This identifies a profiling
target, not permission to remove independent faults or reuse mutable evidence.
Local/container and hosted measurements are not interchangeable benchmarks.
A second focused run of the same 84 replay cases on the same source/PHP
passed 2,409 assertions in 444.124 s at 70.00 MiB peak; recorded case time
was 444.041 s, 2.6% below the first run's class subtotal. Full-versus-focused
context and surrounding workload differ. No implementation changed, so this
is repeatability/variance evidence, not an optimization or full-suite pass.

Before optimization, repeat the expensive classes/stages on the same code and
interpreter, separating cold setup from execution and owned cleanup. Report
variance, original failures, test/assertion counts and instrumentation memory.
Remove duplicates only after proving the same failure mode and preserving
allowed/denied, persisted-no-write, destructive, real HTTP, original-ZIP and
Plugin Check evidence. No timing report caches acceptance across sources.
This measurement phase changes no job layout, coverage, release artifact flow,
required gate or publication protection.

## Execution location

Run development QA where the coding session runs. A local coding session,
including the GitHub Copilot desktop app or CLI, should use the local disposable
container engine. A GitHub cloud-agent session should use disposable containers
in its cloud environment or GitHub Actions. Do not dispatch a cloud workflow
merely to replace an available local development run.

| Context | Development QA location | Independent verification |
| --- | --- | --- |
| Local Copilot app, CLI or editor | Local Docker through the shared launcher/wrappers. Podman API adapter is experimental and runtime-unverified. | GitHub PR checks still run on the submitted candidate. |
| GitHub cloud agent | Containers available in the cloud execution environment; use Actions when that environment cannot run the required checks. | GitHub PR checks still run on the submitted candidate. |
| Scheduled compatibility or release verification | GitHub Actions using the reviewed runtime and package commands. | Existing required checks and publication controls remain in force. |

The location changes, not the assertions. Local and cloud runs must use the
same manifests, runners, dependency pins and package checks. Report actual
source identity, working-tree changes, engine/runtime versions, cases executed,
skips and cleanup status. A local run may validate uncommitted edits; protected
PR checks must validate the submitted commit.

`scripts/qa-run.sh` preflights engine connectivity, Linux server identity and
Compose availability, reserves fresh projects/output directories, and calls the
same existing acceptance runners. Local wrappers explicitly select local
execution; Actions selects cloud execution. Neither dispatches cloud workflows
nor starts a Podman machine. Ownership and mount/port isolation are verified
by the existing guarded lifecycle before candidate execution.
Use Docker by default. The experimental Podman path uses Docker CLI/Compose
against an explicit Podman Docker-compatible local API socket; native Podman
inspection is not assumed to have Docker's JSON shape. Real Podman source,
package, topology and cleanup verification is outstanding. Remote TCP/SSH
endpoints and mismatched engine identities fail preflight. Selected endpoints
are pinned during execution and bound to private project ownership state.
If the selected environment cannot run a required check, report it as blocked
and request an explicit execution choice rather than silently switching engines,
hosts or weakening the tests.

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

## PHPStan level 5 and baseline ratchet

`composer phpstan` analyzes the plugin entry point and all of `includes/` at
level 5 with a PHP 8.0 analysis target. Each reviewed baseline entry has an
anchored message, identifier, positive count and individual path. There are no
excluded production paths or global identifier-only ignores.
`treatPhpDocTypesAsCertain: false` and the WordPress stubs bootstrap remain.

A clean analysis means no diagnostics beyond reviewed debt; it does not prove
runtime safety or soundness of every mixed value. Matching is by message,
identifier, file and count, so moving an equivalent error within a file still
requires diff review. PHPStan's target is not genuine PHP 8.0 execution.

The PHPStan 2.2.15 inference update removed an unmatched sitemap offset ignore.
Its source fallback was preserved. Later extraction-related baseline reductions
are retained; do not restore resolved entries to reproduce a historical total.

To reduce debt:

1. Fix the underlying behavior/type issue with focused evidence. Do not remove
   defensive checks, broaden types or add casts/ignores solely for analysis.
2. Run the complete `composer phpstan`. Unmatched-ignore reporting rejects stale
   entries and counts; per-file analysis is not full ratchet evidence.
3. Remove only resolved entries or lower their counts. Review any regeneration
   for increased counts, widened messages, wildcard paths or unrelated debt.
4. Run `composer phpstan`, `composer test:phpstan-baseline` and `composer qa`.
   Keep the fix and baseline reduction together.

An empty `parameters.ignoreErrors: []` is valid after the final debt is fixed.
The baseline guard generates isolated, non-executed fixtures and verifies
existing debt, new errors, other paths, different messages, excess/stale counts
and corresponding successful reductions. It runs in static QA and safeguards.
Tool upgrades need review when inference or message wording changes.

## Security QA policy

An ability needs explicit security coverage when it accesses private content,
users, environment/plugin/database/security data, full payloads or identifiers;
changes site state; or accepts object IDs, URLs, HTML, metadata keys, taxonomy
terms, plugin identifiers, statuses or capability-sensitive input.

Require an allowed role and a denied role unless the response is intentionally
public-safe with documented rationale. Lists need object/status-aware filtering;
lower-privilege responses need sensitive-field absence assertions. Test invalid
input, protected keys, unsafe URLs, stale preconditions and no-write failures.

`composer validate:security-qa` rejects unreviewed public permission callbacks
and preserves the permission-hardening, comment moderation and sensitive-field
regressions. Comment cases retain mapped-CPT behavior, the Author moderation
floor, zero-ID controls and exact missing-object results. Permission denial
must use otherwise valid input rather than an unrelated schema rejection.
Static policy checks supplement runtime evidence and human review.

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

## Trigger and merge policy

Static and Unit checks run for every PR and main push. Runtime-impacting changes
require Contract and Full MCP E2E. Runtime paths include source, tests, scripts,
Compose, Composer, workflows, package metadata, assets and `readme.txt`.
Release-impacting PRs also require original-ZIP package QA. Scheduled/manual
compatibility covers broader upstream combinations.

The active `main-ci-gates` ruleset requires `1 - Static QA`, `2 - Unit Tests`
and `Docker QA gate`. The Docker aggregate requires successful change detection
and both runtime jobs for runtime changes; failures, cancellations, malformed
outputs and unexpected skips cannot pass. Explicit non-runtime detection may
permit skipped runtime jobs.

`Package QA gate` and `Minimum runtime QA gate` always report. They accept
skipped producer jobs only after successful detection explicitly identifies a
non-product change. Failed detection, malformed output, cancellation or an
unexpected skip fails the aggregate. The package workflow no longer has
workflow-level path filtering. These new checks are not yet activated in the
live ruleset; activation follows merged workflow availability and successful
PR-event verification, without weakening existing requirements.
Only genuine final-head PR-event checks satisfy protected PR gates; local or
manual-dispatch results are supplementary. Do not change protection to complete
a PR. See the [recorded setup evidence](../.github/SETUP-COMPLETE.md#rollout-evidence)
for the distinction between rollout, bot-workflow approval and merge.

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
The manual `publishing-qa.yml` workflow reports checker verdicts independently.
The protected release workflow retains its combined command and prerequisites.

## Compatibility and release readiness

The maintained compatibility campaign separates the WordPress 6.9/PHP 8.1 lane,
pinned baseline, latest stable adapter/WordPress combinations, PHP 8.4,
MySQL 8.4, floating SEO plugins and current Plugin Check. Resolve exact tested
versions, refuse unavailable images/dependencies and propose only successful
updates in maintainer-reviewed PRs. Do not auto-merge baseline promotions.

The PHP 8.1 WordPress compatibility lane does not prove the advertised PHP 8.0
minimum. `minimum-runtime-qa.yml` separately builds actual PHP 8.0.30 with
WordPress 6.9 from digest-pinned bases and signed frozen Debian packages.
This EOL stack is a disposable compatibility fixture, not a production
recommendation. An image build alone is not runtime acceptance.
Genuine 8.0/8.4 syntax/unit evidence, actual CLI/HTTP observations and package
results remain distinct.
Do not raise a support floor, fabricate an image tag or label PHP 8.1 as 8.0
to close that gap.

Before a plugin release, align version metadata, run `composer qa` and the
original-package checks, review supported-version compatibility, and confirm
no unexpected debug-log problems. `composer qa:release` also requires Plugin
Check; `composer qa:package` does not establish publication readiness.

For a WordPress.org submission or publication, separately review Plugin Check
findings and the
[WordPress.org guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
Licensing, bundled assets, external services/consent, executable code, readme
claims, default libraries, SVN/version discipline and trademarks remain
maintainer responsibilities. Known warnings must be documented. Plugin Check
does not establish directory approval or listing, and a product-QA pass does
not authorize publication.

## Verification and rollout

The shared launcher, separated scopes, genuine minimum-runtime fixture and
stronger manifest assertions require actual source/package/HTTP runs, not only
modeled harness controls. All eleven formerly smoke-only successes now have
explicit response or native-state assertions; health/security counts must match
nonempty observed buckets. Native term-state and diagnostic bucket assertions
run once per applicable case, independently of response-value assertions.
The contract workflow keeps its owned stack available for version and debug-log
reporting, then performs guarded cleanup.

The local WordPress 6.9/PHP 8.0.30 campaign completed its source and HTTP
checks: all 601 manifest cases across 85 abilities and all 23 MCP CRUD cases
passed, with successful owned cleanup. After the historical fixture corrections,
the complete Linux PHP 8.0 unit suite passed 4,645 tests and 205,528 assertions
at the unchanged 128 MiB limit. Static QA, focused safeguards and workflow lint
also passed. The runtime campaign preceded those unit-fixture corrections;
original-ZIP acceptance and a final-candidate runtime rerun remain blocked.
These results do not establish Podman support or GitHub PR-event behavior.

At that historical checkpoint, Adapter 0.6.1 remained the pinned baseline. The attempted Adapter
0.7.0 campaign stopped at the product proof's explicit 0.6.1 version check,
before proof actors were created. This is a harness compatibility refusal,
not a reproduced plugin regression or the historical HTTP 400 failure.
Adapter 0.7.0 promotion was blocked in that campaign. A later successful
[compatibility campaign](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/38295107636)
covered the proposed dependency set and its source/package checks; current PR
acceptance must still pass on the updated head before promotion.
The failed run's stack and private evidence
remain retained because its resource ledger did not establish cleanup.
Do not clear the checkout-wide retention marker or rerun runtime/package QA
in this checkout until a reviewed recovery proves cleanup.

Historical unit-test projections use a sealed, exact eleven-case additive
assertion transition. They reconstruct the unchanged historical manifests;
live product runners use the stronger current manifest. Case order, typed
inputs, roles, outcomes and all unrelated assertions remain hash-covered.

Podman runtime verification remains blocked by the local disconnected engine;
no machine is provisioned automatically. Keep that adapter experimental until
source, original-ZIP, HTTP, private-artifact and ownership/cleanup checks pass.
Cloud PR-event execution and new required-check activation follow workflow
rollout. Local modeled controls and image builds do not replace that evidence.

Reproduce historical upstream compatibility failures on current source before
changing pins. Separate setup/infrastructure failures from product or transport
regressions, and require successful reruns before baseline promotion. Keep
scheduled discovery in GitHub Actions.

## Reading failures

Fix syntax/static/manifest/security-policy failures before runtime work.
Unit failures usually indicate helper or error-contract drift. Contract
failures concern registration, roles, response shape, persistence or debug logs;
MCP failures concern authentication, sessions, transport, mutations and cleanup.
Product package failures concern metadata, production contents and installed
runtime behavior. Report Plugin Check findings under the separate publishing
scope, including when combined release verification runs both checks.
Compatibility failures need triage as supported-version regressions, upstream
drift or workflow maintenance before changing pins or merge policy.
