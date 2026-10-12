# Released 2.6.0 compatibility with MCP Adapter 0.7.0

## Outcome

The unchanged Webmastery Site Toolkit for MCP **2.6.0 production ZIP** passed
the focused local comparison with MCP Adapter **0.6.1** and **0.7.0** on
2026-10-12 UTC. No plugin code migration was required for the exercised paths.
This is a released-package result, not a claim that unreleased main is 2.6.0
or that all 3.0 development changes work with Adapter 0.7.0.

Recommend proceeding to the planned **3.0** for the existing breaking plugin
contracts, rather than inventing an intermediate 2.7 compatibility release.
Adapter 0.7.0 alone does not require a new Toolkit release based on this evidence.
An intermediate 2.6.x/2.7 would only be justified by a separately established
production bug or backward-compatible feature. This assessment/test-only PR
does not bump the plugin version or promote development dependency baselines.

## Authoritative inputs

- [Toolkit GitHub release v2.6.0](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/releases/tag/v2.6.0).
  Peeled tag commit: `1503b88750d1c0b9534ee5ecaf661b844d1f7ce3`
  (annotated tag object `98f2ad346500964452734c19d61aed1e60ff9b67`).
  The tag's compatibility configuration records Adapter 0.6.1 and WordPress 7.1.
- [WordPress.org plugin information](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=webmastery-site-toolkit-for-mcp)
  reports 2.6.0, published 2026-09-21. There is no released 2.8 baseline.
- [Adapter v0.7.0 release](https://github.com/WordPress/mcp-adapter/releases/tag/v0.7.0)
  and [tag-specific migration guide](https://github.com/WordPress/mcp-adapter/blob/v0.7.0/docs/migration/v0.7.0.md).
  Tag commit: `54ed266a8c46b71fdb8e5b8a7d35668f90fb8994`.
  Assessment uses the published ZIP, including its vendored runtime, not an
  unbuilt source checkout. Context7 documentation was cross-checked against
  this guide and the downloaded production implementation.

The runner pins these download SHA-256 digests:

| Artifact | SHA-256 |
| --- | --- |
| GitHub Toolkit 2.6.0 ZIP | `577f4f2a5f1a6ca2c9ac32d3cb24d3600a8aecfedb2e11dcd812d768c73de188` |
| WordPress.org Toolkit 2.6.0 ZIP | `42911f5967eee06db32cd01cd74cd23dc99220fc82163e80494c3bd86ed3001d` |
| Adapter 0.6.1 ZIP | `1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676` |
| Adapter 0.7.0 ZIP | `9168c18dbd018428ff14ee28e7018aa0399d4b8819b7c98b406f6610731c3a79` |

GitHub and WordPress.org ZIP archive hashes differ, but their **22 production
files are byte-identical**. The runtime mounts the pristine GitHub extraction
read-only. Current test support is mounted separately and adds no production
files, result filters, permission bypasses or schema repairs.
All 20 packaged PHP files were also compared byte-for-byte with the peeled
v2.6.0 Git commit; none differed.

## Breaking-change assessment

| Adapter change | Consequence for released Toolkit |
| --- | --- |
| Removed DTO namespaces, component validators and validation-disable filter | Toolkit registers ordinary WordPress abilities; no use of these removed APIs was found in the released production PHP. |
| Schema-selected getters and component records | Toolkit does not call these Adapter APIs. All 75 native-install abilities projected into individual tools in both supported 0.7.0 schema revisions. |
| No malformed-field repair; always-on wire projection | All advertised individual input schemas and boolean annotations remained valid. Representative success and failure results passed actual HTTP projection. This does not exercise every callback's output. |
| Custom transports must delegate to the wire orchestrator | Toolkit supplies no custom transport. The fixture uses the Adapter's standard `HttpTransport`, not a bypass of validation. |
| Two exact schema revisions, legacy negotiation | `2025-11-25` and sessionless `2026-07-28` passed. Clients proposing `2024-11-05` or `2025-06-18` received that echoed identifier and worked through the 2025 schema. |
| JSON-RPC batches rejected | Batch requests returned `-32600` in the 0.7.0 lanes. Toolkit does not itself emit JSON-RPC batches. Bulk abilities are a separate concept. |
| Final CLI classes; bundled-library deprecation | Toolkit neither subclasses Adapter CLI classes nor bundles the Adapter. Both lanes install it as a standalone plugin. |

## Observed wire behavior

The default server still exposes three gateway tools; discovery and ability-info
expose the Toolkit inventory. The test-only individual server advertised all
75 Toolkit tools with the registered `readOnlyHint`, `destructiveHint` and
`idempotentHint` values.

Successful gateway execution retains its extra `success/data` wrapper around
the Toolkit's own `success/data` response. Individual execution retains the
Toolkit response directly. Text JSON and `structuredContent` agree.

Released 2.6.0 permission/callback errors remain **plain text** in one content
block with `isError:true` and no wire `structuredContent`. They are not the
canonical JSON error envelope introduced on unreleased main. Applying the
current 3.0 error parser to this historical ZIP would be an invalid comparison.

Adapter 0.7.0 changes an absent ability input schema in `get-ability-info` from
`[]` to `{}`. The runner explicitly checks this Adapter-level representation
change. `2026-07-28` tool results add `resultType:"complete"` and
`_meta["io.modelcontextprotocol/serverInfo"]`; clients should accept the new
fields rather than assume an exact legacy result-key set. New-protocol calls
use `server/discover`, exact protocol/client-capability metadata, and
`Mcp-Method`/`Mcp-Name` headers; legacy clients keep the initialization path.
Clients negotiating 2025-11-25 must also send the matching
`MCP-Protocol-Version` on session requests. Empty tool argument maps must be
JSON objects (`{}`), not arrays (`[]`). The subsequent broad candidate run
demonstrated both rejections in the existing QA clients, as detailed below.

## Reproduction and results

Run from this checkout with PHP/ZipArchive, Python, Bash, curl, checksum tools
and a trusted local Docker engine:

```bash
COMPOSE_PROJECT_NAME=mcp-release-assessment-unique \
  bash scripts/adapter-release-assessment.sh

COMPOSE_PROJECT_NAME=mcp-release-assessment-floor-unique \
  WORDPRESS_IMAGE=wordpress:6.9-php8.1-apache \
  bash scripts/adapter-release-assessment.sh

python -B -m unittest discover -s tests/unit \
  -p test_adapter_release_assessment.py -v
```

In PowerShell, set `$env:COMPOSE_PROJECT_NAME` and, for the floor lane,
`$env:WORDPRESS_IMAGE` before invoking `bash scripts/adapter-release-assessment.sh`.
Every run needs a new project name. The runner refuses existing project resources,
uses generated credentials, retains native WordPress defaults, executes candidate
CLI/HTTP code as UID 33, exposes only loopback HTTP and no database host port,
disables HTTP proxies and redirects, and rejects remote-site URLs. Its test
administrator and subscriber use disposable application passwords. Fixture
and raw response files remain private; never upload the private directory.
Cleanup verifies ownership before removing this run's containers and volumes.

| Native runtime | Adapter 0.6.1 | Adapter 0.7.0 |
| --- | --- | --- |
| WordPress 7.1.2 / PHP 8.2.34 / MySQL 8.0.36 | 285 passed | 385 passed |
| WordPress 6.9 / PHP 8.1.34 / MySQL 8.0.36 | 285 passed | 385 passed |

The final runs retained 303 original HTTP responses for Adapter 0.6.1 and 408
for 0.7.0 per native runtime, with zero failed assertions. Neither final lane
created a WordPress debug log. All owned runtime resources were removed.

Each schema/protocol lane checks administrator and subscriber handshakes,
gateway tools, the full released ability discovery/info inventory, individual
tool projections/annotations, site/environment reads, administrator allow and
subscriber deny, malformed read inputs, missing-post errors, draft-title writes
and persisted unchanged title after denied/invalid writes, and anonymous denial.
Adapter 0.7.0 adds batch rejection and modern result-field checks.

Local `composer phpcs`, `composer validate:e2e-manifest`, PHP fixture syntax,
the four guard/parser unit tests, Bash syntax and `git diff --check` passed.
The current manifest validates **85 abilities / 601 cases / 313 negatives**;
it is not reused as a historical 75-ability runtime oracle. No ability registration,
permission or response contract changes were made in this PR.

`composer lint:workflows` was blocked by missing pinned `actionlint 1.7.12`,
`shellcheck 0.11.0`, and `zizmor 1.30.1` executables. Hosted workflow linting and
exact-head PR checks remain required after review and publication.
The new runner separately passed ShellCheck 0.11.0 in the existing local
ShellCheck container; the aggregate command still requires its pinned tools
on the host PATH.

## Limits

This is not a full release certification: optional Site Kit, SEO providers,
custom post types, every ability callback, custom transports, alternate CLI
subclasses, all PHP interpreters, and external MCP client/bridge implementations
are not covered. The PHP 8.0 plugin minimum has not been tested by these two
runtime lanes. The new header family `Mcp-Param-*` is not separately exercised.
The package is tested on a fresh installation and during an in-place Adapter
upgrade, not against existing production databases.

No connected live/staging WordPress site or remote WordPress MCP server was
accessed. No pending 3.0 implementation was imported or changed. Full main/3.0
runtime compatibility must be assessed independently before changing its
dependency baseline or publishing 3.0.

## Independent pending-3.0 candidate assessment

The subsequent assessment pins **origin/main commit
`115b577bf59e4d3c33b0ccc78d9317e09efab3e1`**, rather than treating this
review worktree or its historical ZIP as the candidate. Its plugin header and
stable tag still say **2.6.0**; that is not a released 3.0 artifact.
No version, release tag, dependency baseline or production file was changed.
The historical results above remain a separate comparison.

The canonical production ZIP has SHA-256
`492fd1ba5ab29f64437d44348fdf113ccf79cf082ebf2f13bb1585cef41bb84d`.
All **38 production files** match the exact Git blobs, and its complete
inventory matches the tracked `includes` tree plus the plugin entry point,
readme and license. Export with an explicit LF policy on Windows:
`git -c core.autocrlf=input -c core.eol=lf archive`; relying on
`core.autocrlf=false` alone produced a converted license and was rejected.
The candidate manifest SHA-256 is
`ac46429074c672cfb852f9b71eba0c1f39907ff4e0699b7410114e64a3e8f5c1`.
Its **85 abilities / 601 cases / 313 negatives**, including fixture CPTs,
replace neither the historical inventory nor the historical error parser.

### Dedicated candidate protocol results

| Native runtime | Adapter 0.6.1 | Adapter 0.7.0 |
| --- | --- | --- |
| WordPress 7.1.2 / PHP 8.2.34 / MySQL 8.0.36 | 300 passed, 417 original responses | 405 passed, 560 original responses |
| WordPress 6.9 / genuine PHP 8.0.30 / MySQL 8.0.36 | 300 passed, 417 original responses | 405 passed, 560 original responses |

All four lanes finished with zero failed assertions and removed their owned
containers and volumes. They check the current registration/discovery/info
inventory and individual schemas/boolean annotations, legacy negotiations,
exact 2025 sessions and 2026 sessionless results, JSON canonical errors with
object-shaped `details`, anonymous/subscriber denial, representative read/write
behavior, and persisted no-write after invalid or denied mutations.
Candidate-specific checks cover unknown keys, unadvertised parent assignment,
wrong types and unsanitized enum values; bounded summary/full projections and
untrusted-content markers; and bulk-trash confirmation, 101-ID rejection and
dry-run preservation. `delete-post` is not a confirmation-interlocked ability;
the guarded destructive test correctly uses `bulk-trash-posts`.

Reproduce from this assessment worktree with a separate clean checkout at the
pinned commit and a production ZIP built there:

```bash
COMPOSE_PROJECT_NAME=mcp-candidate-assessment-unique \
  bash scripts/adapter-release-assessment.sh /path/to/clean-candidate /path/to/candidate.zip

COMPOSE_PROJECT_NAME=mcp-candidate-assessment-floor-unique \
  WORDPRESS_IMAGE=<digest-pinned-WordPress-6.9-PHP-8.0.30-image> \
  bash scripts/adapter-release-assessment.sh /path/to/clean-candidate /path/to/candidate.zip

python -B -m unittest discover -s tests/unit \
  -p test_adapter_release_assessment.py -v
```

On Windows, pass native `C:/...` arguments to Bash and set environment variables
in PowerShell. The candidate mode verifies its exact commit and every packaged
byte before starting WordPress. The no-argument mode remains the released-2.6.0
assessment. Both modes refuse remote endpoints.
The floor image was built locally from the candidate's digest-pinned
`tests/fixtures/php80-floor/Dockerfile`; its resulting image ID was
`sha256:f07559b0f901a5516df0f6f8b9b569dc7983f17a3ba2c094b84f445916395e4d`.
Use `docker build -f tests/fixtures/php80-floor/Dockerfile --iidfile <owned-image-id-file> .`
from that candidate to rebuild it, then use the recorded ID as `WORDPRESS_IMAGE`.

### Existing QA-client compatibility corrections

Broad local `bash scripts/qa-run.sh all` and
`RELEASE_ZIP=candidate.zip bash scripts/qa-run.sh package` were also attempted
in separate candidate scratch checkouts. The original 0.7.0 product runner
refused the installed Adapter before executing any ability because two proof
guards hard-coded 0.6.1. Those guards now admit exactly 0.6.1 and 0.7.0;
unknown versions still fail closed.

After that correction, original wire evidence identified a second test-client
issue: initialization succeeded, but `notifications/initialized` returned
HTTP 400 because the client omitted `MCP-Protocol-Version`. Adapter 0.7.0
requires the negotiated header for a 2025-11-25 session. All five existing
session clients now send `MCP-Protocol-Version: 2025-11-25`, including session
cleanup. This is a client-side protocol requirement, not a Toolkit callback
or permission migration. The dedicated protocol client already sent it.
Behavioral assertions, wire validation and cleanup ownership checks remain
enabled. A transport unit regression checks the header on initialization,
notification, tool discovery and DELETE; package identity tests reject changed
bytes, missing files, duplicates, path traversal and the wrong commit.

The header-corrected full suite then reached CRUD and exposed another
test-client serialization issue: empty discovery-tool arguments encoded as
`[]`, rejected with JSON-RPC `-32602` at `/params/arguments` by Adapter 0.7.0.
The shared tool-call helper now encodes its argument map as an object, including
the empty `{}` case. A regression executes the actual helper with empty and
nonempty maps. It does not repair malformed input in production or relax wire
validation.

### Full candidate source and original-ZIP acceptance

The final native WordPress 7.1.2 / PHP 8.2.34 runs all passed:

| Adapter | Source command | Original-ZIP command | Final outcome |
| --- | --- | --- | --- |
| 0.6.1 | `bash scripts/qa-run.sh all` | `RELEASE_ZIP=candidate.zip bash scripts/qa-run.sh package` | Both passed with pristine candidate test support. |
| 0.7.0 | `bash scripts/qa-run.sh all` | `RELEASE_ZIP=candidate.zip bash scripts/qa-run.sh package` | Both passed after the seven QA-file corrections above; production remained exact-SHA. |

Each run passed all **601 manifest cases**, **23 HTTP CRUD cases** and
**78 canonical-error cases**. It also passed **1,180 metadata cases** and
**252 SEO cases** at each of four boundaries, plus **152 strict-input cases**
at each of direct, permission, ability, gateway HTTP and individual-tool
boundaries. The source/package runs include the existing bounded-read,
untrusted-content, CPT/taxonomy, comment, parent-assignment, metadata privacy
and destructive tests, with actual installed Yoast SEO 28.5 and SEOPress 10.2
and the repository's Site Kit fixture. These are separate boundary proofs,
not a claim that every manifest case was repeated under every protocol.

For 0.7.0, the exact command environment was:

```bash
MCP_ADAPTER_ZIP=https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip
MCP_ADAPTER_SHA256=9168c18dbd018428ff14ee28e7018aa0399d4b8819b7c98b406f6610731c3a79
```

Export those variables, select a fresh `COMPOSE_PROJECT_NAME`, and run the
commands above from separate scratch checkouts carrying only the proof
corrections. The 0.6.1 runs used the candidate's unchanged baseline.
Both package runs revalidated the original archive and mounted production
bytes after execution; neither changed the recorded ZIP hash.
Every final source/package run completed runtime restoration and cleanup,
retired its guard, removed owned resources and reported no final debug log.
Docker Desktop's observed Linux server was 29.6.2; Compose was 5.3.1.

The complete Linux **PHP 8.0.30** unit run passed **4,646 tests / 205,535
assertions**, at **110.01 MB** without raising the existing 128 MiB limit.
The complete Linux **PHP 8.4.25** rerun also passed **4,646 tests / 205,535
assertions**, at **106.01 MB** under the unchanged limit. Its focused transport
regression separately passed **23 tests / 100 assertions**.
The first full PHP 8.4 attempt reached all 4,646 tests but had 67 environmental
errors because that CLI image lacked `ZipArchive`, not callback incompatibility.
The required extension was restored in an assessment-only local image
(`sha256:f5b419c79e692abd862d36e4ca5e92f24dc71d3fdcabdd29afed2cceafa5791f`);
the final run was no longer blocked. Both complete unit runs used
`php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml.dist` as UID
33:33 in an owned writable Linux copy with the candidate mounted read-only.
They took 26:43.794 on PHP 8.0 and 11:47.152 on PHP 8.4. No runtime artifacts
were copied into these unit fixtures, and their containers were removed.

Candidate static QA (`composer qa:static`) passed, including 299 PHP syntax
checks, WPCS, PHPStan and its baseline ratchet, locked dependency audit,
manifest/security policy and whitespace checks. Eight focused Python tests,
changed PHP syntax, Bash syntax and pinned container ShellCheck 0.11.0 passed.
Aggregate host workflow lint remains blocked by its missing pinned tools.

No Toolkit production incompatibility was found in the exercised candidate
paths. The recommendation remains **straight to planned 3.0** for the deliberate
Toolkit contract breaks, not an intermediate 2.7 prompted solely by Adapter
0.7.0. Baseline promotion, release/version changes, protected PR verification
and publishing checks still require their separate review and authorization.

### Retained failed attempts

The failed early attempts deliberately retain their owned local stacks and
private captures because complete resource cleanup was not proven:

- `mcp-candidate-115b577-package070isolated`: refused the installed 0.7.0 version.
- `mcp-candidate-115b577-package070proof`: notification rejected without the protocol header.
- `mcp-candidate-115b577-source070proof`: the same missing-header rejection.

No verified repository-standard recovery was established for these incomplete
proofs; their guards were not removed, bypassed or replayed. They are preserved
for review, with no destructive disposal authorized. The collision-only
`mcp-candidate-115b577-package070` stack was safely removed with
`bash scripts/destructive-retention.sh cleanup` after the successful baseline
source run retired the shared checkout's guard. The later header-only attempts
failed at empty discovery arguments but completed ordinary verified cleanup.
Do not upload any private evidence directory, actor credentials or raw captures.

The minimum runtime above tests the dedicated candidate protocol probes, not
every manifest callback or the full source/package suite on PHP 8.0.
No cloud workflows, publishing checks, live/staging sites, external WordPress
MCP servers or shared-parent checkout changes are part of this assessment.

## Authorized 3.0.0 release preparation

The scope statement above describes the original assessment. The subsequent
release-preparation approval authorized integrating the assessment, committing
and pushing a release PR, and running local release gates. It did not authorize
merging, creating a release tag, production approval or publication.

Current main `115b577bf59e4d3c33b0ccc78d9317e09efab3e1` was merged into the
isolated branch without editing the shared checkout. The release changes align
the plugin header, stable tag, changelog, upgrade notice and migration guidance
at 3.0.0. Support floors remain unchanged, but object/key authorization is
tighter; client migrations must not assume unchanged metadata/SEO permissions.
The Adapter baseline remains 0.6.1.

### Versioned package and runtime evidence

The first versioned source was
`9ab6716d3af5aa46af7e4a85bb422ba7b10f6503`. Its ZIP SHA-256 was
`0e70f005b1764c70ec3e20c8461c7c742b54652d9be8849a23397ac1fb07e255`.
Separate owned checkouts/projects completed these local commands:

| Command | Actual lane and result |
| --- | --- |
| `bash scripts/qa-run.sh all` | Passed WP 7.1.2/PHP 8.2 with Adapter 0.6.1 and, separately, 0.7.0. |
| `RELEASE_ZIP=webmastery-site-toolkit-for-mcp-3.0.0.zip REQUIRE_CURRENT_PLUGIN_CHECK=1 bash scripts/qa-run.sh release` | Passed both Adapter lanes, including original-ZIP product QA and pinned/current Plugin Check. |
| `bash scripts/qa-floor.sh` | Passed the complete source contract/HTTP suite on genuine WP 6.9/PHP 8.0.30 with Adapter 0.7.0. |
| `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml.dist` | Passed 4,646 tests / 205,535 assertions on genuine Linux PHP 8.0.30: 23:07.899, 110.01 MB. |
| `COMPOSER_PROCESS_TIMEOUT=1800 composer qa` | Passed static QA and the complete PHP 8.4.25 unit aggregate: 4,646 tests / 205,535 assertions, 10:44.045, 106.01 MB. |

The existing 128 MiB unit memory limit was not raised. Versioned product runs
retain all 601 manifest cases, 23 HTTP CRUD cases, 78 canonical error cases,
1,180 metadata and 252 SEO cases at each of four boundaries, and 152 strict
input cases at each of five boundaries, alongside the existing safety,
bounded-read, untrusted-content, privacy and parent/taxonomy proofs.
Each successful final project proved restoration and owned cleanup.

A later authorization-wording correction changed packaged `readme.txt`.
The replacement ZIP was built from
`f662e223ce65e55a732e7b38ecd94411fe420259`, with SHA-256
`e64f16a11594460d45f7ebdeadfc3d4c2bffb66806e24b664b21fdeb422889e2`.
All 38 payload files match that commit's exact Git blobs; only `readme.txt`
differs from the first versioned ZIP. The first ZIP results do not certify
these replacement bytes. Both repeated combined release runs subsequently
passed on this archive, retaining its original digest and production bytes.
Plugin Check 2.1.0 (both pinned/current) reported zero errors and two warnings:
the existing diagnostic SQL warning and an upgrade-notice length warning.
Although the raw notice was 297 characters, the checker rejected its parsed
length. The notice was shortened; this changes packaged bytes again.
The release PR's Testing section records the new archive identity and repeated
combined results, separately from these superseded packages and exact-head
hosted checks. Subsequent assessment-document edits are not packaged.

Release product suites use the normal 2025 protocol transport. Detailed
2026-07-28/sessionless protocol evidence remains the separate exact
`115b577` assessment above, not a claim of repeated modern-protocol testing
on every versioned release package.

### Controls, environment failures and remaining release gates

On `f662e22`, `composer qa:static`, `composer lint:workflows`,
`composer test:ci-safeguards` and the eight assessment Python regressions
passed in an owned UID 33:33 Linux project. Static QA checked 302 PHP files,
PHPCS, PHPStan/ratchet, locked dependency audit and manifest/security policy.
All 85 registered abilities have manifest success and forbidden cases.
The complete safeguards include all seven release safeguard commands.
The engine-preflight fix directs PHP failures to stderr even when the local
CLI defaults to stdout; all 14 engine-control cases passed without relaxing
engine admission or cleanup guards.

Initial local failures are retained, not reclassified as passes:

- The default Composer 300-second timeout was insufficient; the bounded
  1,800-second local rerun passed. Existing hosted unit timeout controls remain
  unchanged.
- The initial PHP 8.4 image lacked ZipArchive; the successful release unit
  aggregate used the restored local image.
- Missing pinned workflow linters were restored locally after the actual
  failed check. Copying into `/tmp` correctly failed the release workspace
  guard; the admitted owned project root was used instead.
- A later scratch copy omitted Git metadata, so `git diff --check` could not
  run. A login shell then hid the installed linter PATH. Retaining the owned
  clone metadata and using its normal non-login environment resolved both;
  the complete aggregate was rerun successfully.
- The first hosted historical-assessment run
  [38249016231](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/38249016231)
  failed both lanes because restrictive host umask permissions made the copied
  public WP-CLI PHAR unreadable to UID 33. Public verified downloads now use
  mode 0644 and fresh extracted production files use a process-local 0022
  umask; private parent directories retain mode 0700. A genuine POSIX regression
  checks both boundaries. This changes no package bytes or ownership guards.
- The next hosted floor attempt
  [38249811021](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/38249811021)
  passed all 285 old-Adapter cases but returned mixed-version handler failures
  after replacing Adapter 0.6.1 with 0.7.0. Its fatal referenced an old
  `HttpRequestHandler::toArray()` call absent from the verified 0.7.0 ZIP.
  The harness now restarts and health-checks only its fresh owned WordPress
  service after each replacement; a mocked sequencing test does not substitute
  for the required new hosted runtime result.
- The restart attempt
  [38250936431](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/38250936431)
  reached healthy workers but retained a pre-restart ephemeral host port, so
  the probe connection was refused. The binding is now resolved and checked
  again inside each Adapter iteration after the restart; the lifecycle
  regression exercises two distinct ports.

The latest reviewed scheduled Compatibility QA run,
[37356229529](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/37356229529),
failed its latest-Adapter, combined-latest and latest-SEO HTTP CRUD lanes with
HTTP 400. Those older failures are not a green current drift check. The
controlled local release lanes passed with both Adapters and actual Yoast
28.5/SEOPress 10.2; no cloud replacement was manually dispatched.

Read-only GitHub configuration inspection confirmed the active main CI
requirements, restricted release tags and protected `wordpress-org` production
environment: DanielBoring approval, tag-only `v*` deployments, no administrator
environment bypass, and the expected SVN secret names. Secret values and live
SVN credentials were not tested. Package/minimum-runtime rollout gates are not
live ruleset requirements. No protection was changed.

The three retained failed projects listed above remain untouched. No live or
staging WordPress installation or remote WordPress MCP server was accessed.
Docker Desktop Linux 29.6.2 / Compose 5.3.1 was exercised; real Podman remains
unverified. Final-head PR checks, review, tag-time artifact validation and
separate protected production approval remain required before publication.
