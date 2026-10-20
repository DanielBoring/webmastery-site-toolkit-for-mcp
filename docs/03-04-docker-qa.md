# 03-04 - Docker QA

Workflow: [`.github/workflows/03-04-docker-qa.yml`](../.github/workflows/03-04-docker-qa.yml)
· Required to merge: **yes** (summary job `03-04 - Docker QA`) · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

Two runtime checks against a real, disposable WordPress stack (WordPress 7.1.3,
PHP 8.2, MySQL 8.0, MCP Adapter 0.7.0, Yoast SEO and SEOPress active):

- **`03 - Ability Contract QA`** executes the E2E manifest through registered
  WordPress abilities: registration and manifest coverage, allowed and denied
  roles, response shape, persisted no-write checks, trash configuration and a
  clean debug log.
- **`04 - Full MCP E2E QA`** goes through the real MCP transport: Application
  Password authentication, HTTP sessions, `tools/list`, gateway and individual
  tool execution, real mutations, lower-role denials and cleanup.

The two boundaries have different validation and error ordering, so they are
reported separately. Neither tests the built ZIP; that is 05.

## When it runs

- Every PR, every push to `main`, and manual dispatch. Not scheduled.
- The runtime jobs run only when `scripts/qa-changes.sh` (`runtime` scope)
  finds a runtime-impacting change. Docs, Markdown files anywhere (including
  `tests/e2e/README.md`), `readme.txt`, `tests/unit/`, `.github/` metadata,
  other workflows and release-only scripts skip them; unknown paths (including
  other `.txt` files), this workflow and `compatibility-versions.json` always
  run them. No runtime suite reads `readme.txt`; it still triggers
  `02 - Unit Tests` tooling checks and `05 - Release Package QA`, which tests
  the ZIP that ships it.
- A newer push to the same PR cancels the older run.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `03-04 - Docker QA / detect changes` | 5 min | Runs `scripts/qa-changes.sh` with `QA_SCOPE=runtime`. |
| `03 - Ability Contract QA` | 30 min | If in scope: checks retention, starts the stack with `scripts/qa-compose.sh`, runs `scripts/qa-run.sh contract`, records tested versions and manifest totals, fails on any debug-log warning, notice, deprecation or error, publishes a job summary, uploads evidence, cleans up. |
| `04 - Full MCP E2E QA` | 30 min | If in scope, in parallel with 03 on its own stack: `scripts/qa-run.sh e2e`, job summary, evidence upload, cleanup. |
| `03-04 - Docker QA` | 5 min | Summary: `scripts/docker-qa-gate.php` requires a successful detector and both runtime jobs, or a positive non-runtime result with both jobs intentionally skipped. Failed detection, invalid outputs and failure-induced skips cannot pass. |
| `03-04 - Docker QA / PR comment` | 5 min | Same-repository, non-Dependabot PRs where runtime jobs ran: posts the summary comment. No checkout; `pull-requests: write` only. |
| `03-04 - Docker QA / skip summary` | 2 min | When detection says non-runtime: writes "Docker QA skipped" to the job summary. |

Each runtime job uses a unique Compose project
(`wstm-contract-<run>-<attempt>`, `wstm-e2e-<run>-<attempt>`), a random
loopback port and read-only permissions.

**Artifacts** (7-day retention; a missing required file fails the upload):

| Job | Artifacts |
| --- | --- |
| 03 | `product-qa-contract-summary`, `ability-contract-summary`, `trash-safety-results`, `scheduling-regression`, `taxonomy-write-summary`, `patch-html-regression-evidence`, `parent-assignment-contract-evidence`, `post-meta-authorization-contract-evidence`, `metadata-boundary-contract-evidence`, `comment-contract-evidence`, `destructive-safety-contract-evidence`, `input-schema-contract-evidence`, `error-contract-ability-evidence`, `database-privacy-native-evidence` |
| 04 | `product-qa-transport-summary`, `full-mcp-e2e-summary`, `parent-assignment-http-evidence`, `post-meta-authorization-http-evidence`, `metadata-boundary-http-evidence`, `comment-http-evidence`, `destructive-safety-http-evidence`, `input-schema-http-evidence`, `error-contract-http-evidence`, `database-privacy-http-evidence` |

**PR comment data sources**

- Result and run URL come from the current workflow run.
- "PR head commit" is `github.event.pull_request.head.sha`; "tested merge
  commit" is `github.sha`, GitHub's synthetic merge commit for PR runs.
- Ability coverage, manifest case totals, passed, failed and negative
  permission cases come from `e2e-artifacts/e2e-summary.json`, written by
  `tests/e2e/ability-runner.php`.
- WordPress, PHP, MySQL, MCP Adapter, Yoast SEO, SEOPress and plugin versions
  are read from the live stack after the contract run.
- The debug-log status comes from the debug-log scan step.
- Fork and read-only bot runs keep the job summary instead of commenting.

## Run locally

Use the wrappers; each runs `composer install` and `composer qa`, then the
selected Docker scopes in a fresh owned project:

```bash
scripts/qa-local.sh                  # static + unit only
scripts/qa-local.sh --contract       # + 03
scripts/qa-local.sh --e2e            # + 04
scripts/qa-local.sh --contract --e2e --package   # + 05
scripts/qa-local.sh --publishing     # Plugin Check only (09)
scripts/qa-local.sh --release        # package + Plugin Check
```

```powershell
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract -E2E -Package
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Publishing
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release
```

`composer qa` runs local static and unit checks. `composer qa:local`,
`composer qa:package` and `composer qa:publishing` run their runtime scopes
through the shared launcher (`scripts/qa-run.sh`) without repeating static and
unit checks. Runtime Composer scripts disable only Composer's process timeout;
CI jobs keep finite limits. Raw runs need an explicit unique
`COMPOSE_PROJECT_NAME`, managed Compose and a fresh output directory:

```bash
COMPOSE_PROJECT_NAME=wstm-contract-mytest E2E_MANAGE_COMPOSE=1 \
  E2E_ARTIFACTS_DIR=e2e-artifacts-wstm-contract-mytest \
  COMPOSER_PROCESS_TIMEOUT=0 composer qa:contract
```

Do not call `docker compose` directly: every Compose call goes through
`scripts/qa-compose.sh`.

- Choose a new project name for each run. Do not overwrite retained evidence or
  delete unknown build directories.
- Run runtime campaigns serially within one checkout. Different Compose
  projects still share candidate and package support and the checkout-wide
  retention interlock, so an overlapping campaign can be refused and retained
  even when its own project is clean. CI jobs have separate checkouts.
- Git Bash container commands keep `MSYS_NO_PATHCONV=1`; native host PHP calls
  remove it for Windows path conversion. Docker Desktop must be running, and
  fresh image and plugin setup needs network access. Report missing tools or
  failed setup; do not convert them into skipped acceptance.
- The bootstrap installs and activates Yoast SEO and SEOPress from
  WordPress.org. SEO ability assertions are Yoast-backed; SEOPress is active to
  exercise dependency readiness and coexistence guardrails.

**Retained local evidence.** A failed Adapter compatibility campaign left a
retained stack and private evidence in the maintainer's local checkout because
its resource ledger did not establish cleanup. Do not clear that checkout-wide
retention marker, or rerun runtime or package QA in that checkout, until a
reviewed recovery proves cleanup.

## Reading failures

Contract failures (03) concern registration, manifest coverage, roles,
response shape, persistence, trash configuration or the debug log. Transport
failures (04) concern authentication, sessions, transport, mutations and
cleanup. Distinguish real WordPress behavior from bootstrap or image-pull
failures, and from mocked safeguards. Keep original failures and reproduce
locally before changing code or test expectations. Cleanup success does not
turn a failed run into acceptance.

## Details

### Ability coverage contract

- Every new `webmastery-site-toolkit-for-mcp/*` ability needs coverage in
  `tests/e2e/abilities-manifest.json`.
- Contract QA fails when a registered ability has no manifest coverage, and
  when a manifest entry names an ability that is no longer registered.
- For permission-sensitive abilities, include allowed and denied role cases
  where practical.
- Security-sensitive abilities keep the negative cases and sensitive-field
  absence assertions that `composer validate:security-qa` requires (see
  [01](01-static-qa.md#security-qa-policy)).
- [`tests/e2e/README.md`](../tests/e2e/README.md) has the manifest format and
  update rules.

### Workflow security

- Runtime jobs have `contents: read` only.
- PR commenting is a separate job with comment-only write scope and no
  repository checkout, so PR code never runs with a writable token.
- Actions are pinned to immutable SHAs.

### Runtime environment

Hosted Linux runners and the local Docker engine and images are trusted
execution prerequisites. The product checks do not claim whole-host, kernel
or loader attestation. Runtime bootstrap uses the maintained setup and
verified executable downloads without changing plugin support floors.

Every Compose call uses `scripts/qa-compose.sh`: fixed files, an explicit
unique project, ownership-labelled resources and checked teardown. Candidate
PHP and WP-CLI run as UID/GID 33:33; only a fixed trusted directory
initialization runs as root. HTTP is loopback-only, the database has no host
port, source and package mounts are read-only and no engine socket enters the
application containers.

The product context binds the actual CLI and HTTP interpreter lanes and the
expected source or package identity. PHP 8.1 compatibility, the PHP 8.2
primary runtime and PHP 8.0/8.4 unit evidence are separate claims. A bootstrap
smoke test, a modeled mock or a successful dependency install does not
establish product or package acceptance.

### Contract and transport boundaries

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

### Disposable runtime harness

Change this section when you change the harness it describes.

#### Trust and isolation

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

#### Network, mounts and package preparation

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

#### Credentials and fresh installation

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

#### Native default fixtures

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

#### Database privacy reports

Database privacy runners write their native and HTTP JSON reports to the
private container artifact volume. The guarded host-export inventory includes
both reports for required workflow uploads. An attempted privacy boundary
requires its report even if the runner fails; missing or unreadable captures
and destination collisions fail without replacing the original runner status.
Verify the exported JSON and its case totals for source and original-ZIP runs;
successful console totals alone do not establish that upload evidence exists.

#### Upload fixtures

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

#### Output and retention

Output directories are exclusively reserved and never reused. Failed runs
preserve private originals and the original exit status. Teardown rechecks
resource ownership. An unresolved destructive/schema/product retention marker
blocks teardown and restart; a passed flag or successful export alone cannot
clear it. Recover the exact owned resources and verify restoration and absence
before retiring the marker.

### Focused product acceptance

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

#### Bounded-list benchmark

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

#### Product context and guard

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

#### Test credentials

Test credential creation supplies slashed names to the native WordPress API so
metadata unslashing preserves the original intent. Enrollment and recovery
still require exact actor, name and UUID agreement. An unresolved credential
intent blocks cleanup and acceptance; it is not evidence of absence.

Live credential names retain instruction-looking text, quotes and Unicode,
but omit backslashes because native usage updates strip them. Dedicated
boundary regressions cover backslashes separately. Before native MCP session
reads, refresh only the owned actor's metadata cache and retain exact token,
client-name and actor checks across enrollment, recovery and absence checks.

#### MCP fixture and metadata observations

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

#### Evidence bounds and replay

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

#### Cron policy

Bounded runtime and the live-absence helper share the owned QA configuration's single
unconditional literal-true cron definition before bootstrap. It does not
redefine the constant or suppress diagnostics. Check effective cron policy,
absence of cron work and unchanged configuration custody after bootstrap and
absence reads. Unsupported configuration shapes refuse before bootstrap.
An unconditional top-level definition may follow a completed closing brace.
Nested or unbraced conditional definitions, namespace declarations, duplicate
definitions and nonliteral or false values still refuse.
