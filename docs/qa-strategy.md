# QA Strategy

This repository uses layered QA for a public WordPress.org plugin. The goal is to catch the cheapest problems first, then add WordPress runtime, MCP transport, security, compatibility, and release-package evidence when a change can affect users, site data, permissions, or publishing.

The plugin is now reviewed as a WordPress.org plugin, so QA must prove more than "the code runs." It must also prove ability permissions, object-level access, response privacy, WordPress compatibility, and package contents stay aligned with WordPress.org expectations.

See the [Software Development Lifecycle](sdlc-overview.md) for where QA fits in the complete development process.

Related strategy guides:

- [`ci-cd-strategy.md`](ci-cd-strategy.md) explains GitHub Actions automation, branch protection, workflow permissions, schedules, artifacts, and failure handling.
- [`security-strategy.md`](security-strategy.md) explains the WordPress plugin threat model, ability permission policy, secrets, dependency security, and vulnerability response.
- [`release-strategy.md`](release-strategy.md) explains versioning, release readiness, GitHub releases, WordPress.org SVN publishing, hotfixes, and rollback policy.

## QA checks

### Current-source composition witness and mock-only static coverage

The three-surface gate is composed onto published PR167 head
`29f76178ceed3cf49eea02122defe7524eb2b555`, not substituted from its older
511-path input baseline. The outer host-prerequisite transition reverses
only additive composition and QA changes to the exact published518 bytes
before the unchanged nsfs/net and older witnesses. Its closure includes the
three Python scripts, three mock test files, workflows and documentation.

`composer lint:php` also AST-parses every Python source under scripts/tests
without executing inventory or provisioning. `composer test:host-prerequisites`
runs the three fixed mock-only suites (31 inventory,22 provisioning,32 shared
driver/projection controls). Both static QA and the existing CI safeguards entry run
those controls; their existing scripts/tests path coverage includes every
new file. None of these checks is native HOST acquisition or protocol
approval. Real PHP8.0/8.4 and Docker acceptance remain new-head CI evidence.

### Read-only HOST prerequisite inventory before the three QA surfaces

Signed OS-package owner repair is approved only for `release-package-qa`
(PHP8.2), `ability-contract-qa` and `full-mcp-e2e-qa` (PHP8.4) on owned
disposable GitHub-hosted machines. Verify signatures/the approved fingerprint,
source/archive bindings and installed owner/version/digests through fixed
installation operations and unchanged budgets. Normal signed-package
housekeeping may clean expired sessions for other PHP versions on those
machines. It is authorized behavior, not observed scheduler nonexecution,
unlimited ROOT permission, candidate application elevation or certified
root-child cleanup. No local/shared/production or release permission follows.

The package-native guard uses regular CLI `php.ini` and
`mods-available/sockets.ini` files. It verifies the socket template's installed
package/source version and digest plus the exact root-owned `20-sockets.ini`
alias. Cleanup observation retains the complete query domain and distinguishes
active workers from entries missing an executable or SAPI INI. The verified
closed APT transaction admits authenticated incoming code and verified retained
maintainers/triggers without treating prospective data as installed authority.
After installation, status, owner/version/digest, loaders, generated UCF INIs,
modules and aliases must pass before candidate eligibility. Unchanged producers
require installed bindings; incoming producers require archive bindings.
Direct helper execution retains installed checks and future-ELF refusal.
Original prerequisite suites and frozen restoration guarantees
remain required with additive current bindings. Genuine small producer fixtures
and bounded acquisition mocks do not establish native installation or HOST success.

Release Package, Ability Contract and Full MCP QA use the same
`scripts/host-prerequisite-setup.py` driver immediately after the final host
signed package provisioning and shared unit controls, BEFORE candidate configured PHP, custody
probes, safeguards or runtime QA. Its required explicit selection is8.2 for
the package job and8.4 for Contract/Full MCP; no version/environment fallback.
The driver hardens the selected two files, then invokes
`scripts/host-prerequisite-inventory.py` with the SAME closed selection.
After acquisition it retains and validates a finite public projection, then
logs exactly one `WSTM_G1G2_ACQUISITION_V1 ` marker followed by compact JSON.
The existing Actions job log retains this line even if later runtime QA
refuses admission with exit78. Unit-only execution never emits that marker.
Retrieve the original job/attempt log and compare its three `sources` digests
(`driver`, `guard`, `gate`) with the reviewed candidate; step completion alone
is not availability evidence. Log metadata supplies run/attempt/head context,
not a fabricated field or standalone attestation in the projection.

The closed `g1g2-acquisition-v1` schema contains only the selected8.2/8.4,
the corresponding finite job ID, canonical SHA256 strings, enumerated tool/API
IDs, strict booleans, nulls and finite observed/refused/unknown states.
Tool IDs are setpriv, python, php, dpkg-query, readelf, sudo and chmod.
PHP facts use all seven function and thirteen constant IDs in the existing
no-argument presence query, including optional fcntl/CLOEXEC IDs. False optional
facts do not change the existing six-function/nine-constant eligibility checks.
Python socket facts are explicitly unknown: this collector has not probed them.
Configured PHP presence is distinct from the bare PHP original-capture flags.
No raw paths, argv, environment, package/module/version strings, configuration,
process data, stdout/stderr or error details are public.

The guard and inventory snapshot their original private inventory, retention
index and closed native-command observed/stdout/stderr bytes before projection.
The last retained phase rechecks all three own-source identities and full hashes
against its existing ledger, charging re-read bytes to that same ledger.
It writes/fsyncs `projection-input.private.json` and its SHA256 file before
parsing their verified readback. Each inventory/event/pipe is bound to the
retained index's original identity, size and digest, and the original direct
child exit plus both EOF flags. No inferred wait or mock result supplies a fact.
`projection.public.json` is also retained and read back before closed-schema
validation and log emission. These new files remain private; there is no upload,
recipient, secret, new transport or export of the full inventory.

The input's exact base64-expanded size is bounded before encoding. Input/hash
and projected bytes share the last phase's existing16MiB aggregate capture
ledger, rather than a new pool; individual records retain their existing16MiB
bound, and the entire public line is limited to16384 bytes. All checks use the
last phase's original absolute deadline, including before and after log flush.
No deadline reset, retry, refunded failed debit or drain allowance is added.
Missing APIs can yield a validated `refused` projection; malformed/partial
source/capture bindings or retention failures instead emit only a fixed refusal,
never a success fallback, raw diagnostic or traceback. Either failure returns78
and prevents downstream candidate PHP. Original failure prefixes remain private.
The availability projection is explicitly **not HOST admission, native FD
behavior, protocol acceptance or a privileged-child cleanup certificate**.
An optional `origin_failure` object is null unless a retained `tool-origin`
refusal supplies its closed tool ID, subject (tool/dependency/python-module)
and exact origin-check ID. No package, operand, path or command text is included.
Dependency checks retain the actual top-level tool context; successful Python
origin alone does not prove that its dependencies completed or PHP was reached.
This diagnostic preserves the original refusal and every provenance predicate;
it does not admit unmanaged PHP installs or infer the cause from setup output.
The read-only gate uses fixed public Linux tool/package paths and an
isolated, scrubbed nonroot interpreter. It never executes setpriv (including
`--version`), sudo, a launcher, NNP, socketpair/send/receive, Docker or a daemon.
PHP is queried for actual sockets functions/constants; requesting only `zip`
in package PHP setup, or only posix in E2E host setup, is not evidence that
sockets exists. No extension is installed
automatically, and the plugin's PHP8.0 floor is unchanged.

The gate pins complete root-owned non-set-ID ELF bytes, canonical parent modes,
installed package/source-version metadata and installed-manifest digests.
It recursively inventories declared ELF loader/library dependencies, isolated
Python modules, and the fixed root-owned PHP CLI configuration/native modules
before the configured PHP query. Private hashes pin actual configuration;
arbitrary extension paths, preload/prepend scripts and ambient loader/PHP
environment overrides are rejected. Only a finite standard module catalog
may be queried; CLI session startup, opcode file caching, logging and Xdebug
profiling/tracing are disabled for the inventory query. This neither installs
nor pretends to enable missing sockets. Source archive digests and actual loaded
module/duplicate-FD behavior are not inferred from package names or OS versions.

The gate has one30s absolute clock, at most256 pinned files/256MiB,512 fixed
read-only native commands,64KiB stdout/8KiB stderr per command and16MiB total
command bytes; cached installed manifests are also limited to16MiB.
Every unique tool, dependency and PHP configuration pin shares the same
256-file/256MiB ledger. File size and a slot are reserved before acquiring
bytes, retained on failure, and not charged again for a validated cached alias.
Changed file, alias or parent identities invalidate that cache.

Writable PHP configuration still refuses this read-only gate. A separate
approved setup-only guard, `scripts/provision-php82-permissions.py`, runs
immediately after signed package provisioning and before the first candidate
configured PHP invocation, including workflow custody and safeguard probes.
It selects ONLY one of two literal pairs: `/etc/php/8.2/cli/php.ini` and
`/etc/php/8.2/mods-available/sockets.ini` for Release Package, or the equivalent
two literal8.4 files for Contract/Full MCP, through one fixed system
sudo/chmod argv to0644. Other versions, mixed pairs, missing/extra selectors
and mismatched canonical interpreters refuse. The legacy php82 script filename
and setup mode remain compatibility labels; one shared guard implementation
uses the explicit approved version/job allowlist, not path/version inference.
The Python guard never runs as root; it is not an inventory
or runtime observer sudo permission. The failure diagnostic is also withheld
if the combined provisioning/acquisition fails. Always-running PHP summaries
and export verifiers are gated too. No extension is added and no PHP-n controller
substitution is made; only the identity query uses PHP-n before hardening. These are the existing
configuration-hardening constraints, not the complete authorization scope of
the separately approved signed-package installation and housekeeping.

The setup guard requires the owned disposable GitHub-hosted workflow interval,
before any candidate/config writer is started; these workflow assertions are
scope checks, not standalone credentials or proof against a hostile root
administrator. Unexpected layout refuses. It checks root-owned nonsymlink,
single-link non-set-ID targets with no file capabilities, canonical nonwritable
root-owned parents and full content hashes. Original read handles remain held
across chmod; postchecks require identical device/inode/ownership/parents/
length/content/mtime and mode0644. Only the expected mode/ctime change is
permitted. Sudo alone may be set-UID. Complete sudo/chmod ELF, installed
package/source-version/manifests, declared loader dependencies and the fixed
sudo policy module are pinned. Custom sudo.conf plugins/paths refuse;
sudo-private loader paths are limited to the two fixed root-owned package
directories, not arbitrary RPATH or environment paths. Actual loaded-module
behavior and source-build provenance are not inferred.

Setup owns one30s absolute admission/capture clock WITHIN the unchanged
55-minute package or30-minute Contract/Full MCP job, not a kernel/helper pass.
It has the same ceilings of256 pins/256MiB
acquisition bytes,512 native commands INCLUDING at most ONE root invocation,
64KiB/8KiB per command and16MiB aggregate command capture; cached public
manifests remain bounded to16MiB. All repeated target hash reads debit the
same256MiB setup ledger before bytes. The two targets are read at most four
times (initial, prepared guard, immediate prelaunch, postcheck), at most8MiB
total, WITHIN that ledger, not an added pool. There is no retry or fresh
per-hop clock. The separate read-only inventory retains its original30s and
budgets; kernel/controller/observer/helper budgets are unchanged.
Both setup-root and read-only system launches recheck that same absolute
deadline immediately after private reservation is retained, before Popen.
Reservation/fsync reaching exactly the deadline refuses without a child;
the command debit and failed originals remain retained, not refunded.

Original private intent, capture and actual direct-child exit/both EOF records
precede interpretation and postcondition success. A privileged child is never
signaled by the guard. Deadline/retention/failure leaves original prefixes and
unknown exit/EOF honestly; no detach/drain clock, hard wall-time guarantee or
root-cleanup certificate is invented. The existing enclosing runner/job
lifecycle remains responsible for administration. Full native provisioning
execution is pending independent review and genuine existing PR-runner evidence;
unit/mock0644 postconditions do not prove a real chmod occurred.

Original0600 command streams/intents precede parsing in an
exclusive0700 `wstm-prerequisite-*` child of the owned runner custody root.
Failures retain original prefixes and observed exits/EOFs where available;
missing tools, package mismatch, sockets/constants, changed identities,
over-limit output, deadlines or retention failures close the gate with exit78.
Synchronous I/O/scheduling still cannot be certified as hard-preemptible.

Public output is a finite summary only. Full inventories, native output and
private paths are not uploaded to public artifacts or printed. Originals are
job-local; durable encrypted retrieval requires the existing separately bound
private-custody mechanism, not an automatic plaintext upload or key generation.
An inventory pass is not HOST admission, remote-wait authority, FD isolation,
root cleanup, package/release acceptance or a source-transition proof. Genuine
runner evidence must not be replaced by WSL tool hashes or unit mocks.

Portable controls:
`python -B -m unittest discover -s tests/unit -p test_host_prerequisite_inventory.py -v`.
Provisioning and shared-driver selectors are
`test_provision_php82_permissions.py` and `test_host_prerequisite_setup.py`.
CI uses the shared driver's closed `units 8.2` or `units 8.4` entry to load
exactly these three test files; that mode never runs provisioning/acquisition.
These cover actual missing PHP APIs, tool/parent/link/origin/hash refusal,
unsafe inputs/configuration, original-prefix retention, finite output, exit/
EOF failure, deadline exhaustion and closed public summaries.

### Opt-in read-only system observation on disposable CI hosts

Disposable GitHub-hosted source, package and PHP-floor QA entries explicitly set
`WSTM108_HOST_INSPECTION=system-readonly-v1`. Unset preserves native unprivileged
reads; an unknown value refuses admission. Do not enable it on shared, live or
self-hosted systems. Inspection does not provision fixtures, reset a stack,
grant a lease or bypass any admission predicate.

The controller, PHP and private capture remain nonroot. Verified absolute,
root-owned, non-group/world-writable system tools alone run through
`/usr/bin/sudo -n --user=root --`: a fixed root `/usr/bin/timeout` supervises
`/usr/bin/env -i PATH=/usr/bin:/bin LC_ALL=C` and one fixed `/usr/bin/find -P`,
`/usr/bin/stat` or `/usr/bin/head` read of the validated Docker PID's descriptors,
mount namespace or mount table. Tools require a matching native ELF64
little-endian Linux/System-V x86-64 or AArch64 header; only sudo may have set-ID
bits. No candidate PHP, Python, shell or script is elevated. A fixed nonroot
system timeout also bounds the sudo-entry transport.

The positive PID/root hint, process/start identity, canonical root-owned socket,
unique listening inode, descriptor ownership, namespace identity, equal complete
mount tables and before/after identities remain mandatory. Missing tools, sudo
denial, partial/over-limit bytes, stderr, nonzero exit, deadline expiry and races
refuse without a success fallback. Stdin is closed and environments contain
only fixed `PATH` and `LC_ALL`; the native env entry clears sudo-added variables.

Private originals precede parsing. An exclusively reserved observation child
uses the existing owned-child transition; helpers retain its handle and mode,
while candidate child environments receive neither. Release checks the complete
original inventory, identities, hashes, lengths, fixed argv and parsers.
Incomplete or changed observations retain private evidence and block release.
Public diagnostics expose only closed reasons, never raw process/filesystem data.

One capture deadline starts before reservation/tool checks and covers streams,
exit/EOF, persistence and identity completion. Refusal uses nonblocking PHP
process-resource disposal, not a blocking reap or a signal to a possibly reused
PID; fixed system timers remain intact. These deadlines do not certify
root-child cleanup or hard preemption of synchronous filesystem/kernel I/O.
Late completion cannot be accepted, but blocked storage operations can return
after the deadline. Parser and nonprivileged transport adapters are not native
sudo, Docker admission, custody, owned runtime or required-check acceptance.

The additive exact-byte outer projection restores the reviewed current-main
generation before its unchanged historical bridge. It does not regenerate any
accepted ledger or change original dependency hash assertions.

Full source/package QA requires a separately approved bare owned stack before
fixture execution. Read-only admission does not provision or tear down that
stack. The harness checks native admission before clearing artifacts or managed
reset, then checks the changed inventory again before installing fixtures or
arming their cleanup. Contract-only lifecycle behavior remains separate.

### Kernel mount verification

The parser retains the exact decoded root as opaque metadata only for the
reviewed joint pair: type exactly `nsfs`, root `net:[inode]` with canonical
positive decimal inode at most 4294967295. Both parser modes use the same
bounded, 32-bit-safe recognizer as the relative-root diagnostic. Every other
root still uses the original canonical path validator. Mount points and caller
lookup paths never receive this exception. No row, key or table ordering is
changed, and structural decoding, EOF, IDs, optional tags and limits still apply.

This is not physical authority. Both coordinate implementations keep their
ext4/tmpfs gate before joining a root with a suffix. Nested nsfs mounts under
authority or source remain in the physical closure and refuse as unsupported;
they are not filtered out. Whole-table comparisons, duplicate-point visibility,
descriptor mount/device/inode checks, namespace/process identity, race checks,
capture custody and shared budgets remain mandatory. Parsed/model rows never
mint native proof. Tests cover exact minimum/maximum metadata rows, all rejected
near-matches, unchanged lookup paths, unsupported coordinates in both models,
nested closure, complete-table equality and duplicate visibility.

For the original relative-root row only, failure diagnostics distinguish
`mount-root-net-true`, `mount-root-net-false` and `mount-root-net-unknown`.
True requires the complete joint predicate: the existing row's exact type is
`nsfs`, its decoded root is a canonical positive `net:[inode]` token, and the
decimal inode is at most 4294967295. Decimal string comparison is 32-bit safe.
False is a known nonmatch, not a filesystem inference; missing, malformed or
inconsistent row context and unverifiable native birth remain unknown. The
original parser row is bound through five exact argument-free native source
frames and the existing private birth/full-trace custody, not a caller Boolean
or receipt. The longest new label is 22 ASCII bytes. No operand is reflected.
The failure helper still refuses for all three outcomes. A genuine reviewed
true pair is now retained by the parser before calling the path validator, so
it produces no refusal diagnostic. A reflected helper or forged true frame
cannot establish attribution. False/unknown pairs still reach the unchanged
path refusal. No physical-coordinate allowance, new read, grant or privilege
transition accompanies the metadata exception.

Decoded mount-root refusals refine only the exact, source-bound parser caller.
The original host canonical predicate is evaluated once, unchanged; its success
return and all admission decisions remain unchanged. Failure-only sites report
ordered empty, relative or NUL prefixes; the relative case reports the joint
fact above instead of the generic legacy `mount-root-relative` label.
Otherwise `mount-root-rx-c`, `-s`, `-d`, `-cs`, `-cd`, `-sd` or `-csd` identify
the exact combination of control-byte, double-slash and dot-component matches,
without ranking overlapping matches or disclosing matched bytes. Inconsistent
classification or unverifiable native birth remains `unknown`. Other reviewed
callers retain their IDs. These diagnostics do not identify a filesystem,
namespace owner, mount row or physical coordinate and cannot establish that a
hosted root uses cgroup namespace semantics. A canonical host-parser root longer
than 4096 remains accepted by that parser; the separate physical-coordinate
limit is unchanged. The longest refined ID is 19 ASCII bytes; existing scalar
and terminal-channel limits remain 32 and 256 bytes.

Terminal `noncanonical-path` diagnostics distinguish decoded mount roots/points,
legacy source/input/physical callers and kernel selected/input/source/held/path/
chunk callers. Kernel IDs end in `length` or `canonical`; only the existing
model length/canonical conjunction is split, in its original evaluation order.
Neither validator accepts new paths. A valid decoded root longer than 4096
and a shorter valid root whose computed backing coordinate exceeds 4096 both
remain reproducible model refusals. Both computed-coordinate controls report
`kernel-physical-length`; that ID distinguishes the predicate, not the root
length or private bytes, and neither control attributes a hosted failure.

The original controller entry sets `zend.exception_ignore_args=1` once and
requires the exact read-back value. Classification requires weak object-identity
custody from argument-free exception creation, exact trusted guard/caller
file/line/class/function metadata and a matching terminal classification.
Unverified settings, foreign entries, unfamiliar frames, altered traces and
unregistered objects produce `unknown`; they do not authorize admission.
No argument/object/message/path values are serialized. Each terminal channel's
combined old witness and new scalar payload is at most 256 bytes, and each
scalar is at most 32 bytes. Both use the existing terminal writer, without
additional evidence reads, files, budgets, retries, grants or cleanup exemptions.
The passive classifier resides in the already-hashed topology source file;
it does not add a provenance leaf, capture file or source-copy dependency.

One ordinary controller invocation exits after its one terminal failure.
GitHub jobs and their `qa` steps have separate output channels. A repeated
report nevertheless overwrites the scalar with `unknown` when unassignable;
the formatter never pairs a noncanonical callsite with a different reason.
Original controller stdout/stderr remain exclusively retained in the private
bootstrap child before PHP starts; public diagnostics do not upload those
originals. The formatter and its eight ENV-only steps cannot replace exit 78
or any original runtime/release gate.

The same provenance-gated classifier also maps every existing
`native-coordinate-prerequisite` guard, including constant-default inherited
owner guards and direct serialization refusals. IDs distinguish platform,
scope/reservation, safe-exec schema/process/credentials/current-capsets/lane,
deadlines, executable checks, parent/child coherence and inherited fd5 custody.
Compound predicates and loop bodies retain one guard ID: an ID is not a report
of which credential, capset, path, loop member or subcondition failed. The
public reason alone is not unique and cannot establish an unsafe credential
configuration. Caller files and invocation lines must match the finite reviewed
source bindings. Direct serialization guards have finite reserved IDs, but no
current reviewed source caller: they remain `unknown` rather than accepting a
new or builtin caller merely because it points somewhere inside a source file;
uninitialized authority subprocesses and non-source calls remain `unknown`.
No prerequisite predicate or control flow, launcher, credential transition,
evidence read or authority was changed.

Synthetic outer diagnostic controls bind the controller's fixed output fd9
to their own exclusive private0600 capture, independently of stdout/stderr.
Untyped synthetic terminal exceptions must emit exactly the current
`untrusted_admission_callsite_v1=unknown` line there; cases that never invoke
the terminal must leave it empty. Unknown is not mapping proof. Extra bytes,
known IDs without provenance, private sentinels or typed witnesses are refused.
Existing empty-stderr, original-exit, reader-noise, privacy and I/O-fault
assertions remain unchanged. This prevents incidental inherited descriptors
from routing a finite public diagnostic into another synthetic channel.
Guard coordinates are compiler-specific, not line ranges or aliases: PHP8.0's
exact closing-token line is mapped to the reviewed opening-token guard; later
engines retain the exact opening-token binding. Tests verify both coordinates
against source tokens, reject the other compiler's multiline coordinate and
keep unchanged full native trace/creation custody checks.

Strict `mounts()`, `coordinate()` and `outside()` models still refuse stacked
tables without live native evidence. Structural parsing preserves all rows and
their order; it is not admission. Native stacked admission observes `/` and
every duplicate mountpoint with a fixed source-bound nonroot Python `O_PATH`
holder. Independent PHP reads of the actual child's status, start identity,
task set, namespace, held fdinfo, device/inode and point anchor select the exact
kernel mount ID. There is no first-row, maximum-ID or lexical fallback.

Before Python exec, the existing actor must have matching nonroot real,
effective, saved and filesystem credentials, exact supplementary groups and
zero effective/permitted/inheritable/ambient capabilities. The kernel must
already enforce either `NoNewPrivs=1` or a zero bounding set. The latter lane
also requires a pinned ordinary non-set-ID interpreter. Missing or malformed
fields refuse before launch; no capability, privilege or namespace changes
are made. This does not enable no-new-privileges globally or alter the existing
fixed read-only sudo scope. Child credentials are independently verified before
target paths are sent and at every live boundary.

Visibility-only observations accept any filesystem or inode type and never
read contents. Physical authority/source checks retain ext4/tmpfs coordinates
and reacquire the complete checkout, Docker storage, bind/volume and relevant
nested mountpoint closure. Same-device whole, root-relative and nested bind
aliases remain exposures even when mount IDs differ. File sources expose only
their exact coordinate. Hidden points must be observable; they are not inferred
invisible from row order or location.

Each controller action shares at most eight seconds of charged holder work,
eight launches and sixteen MiB of retained originals. A contiguous native
verification scope pins the first existing observation deadline and clips it
to any governing original discovery deadline. Later admits only tighten it;
the additional 64-read ceiling does not reset within that scope. Chunks,
shutdown and receipt persistence consume the same allowance. Trusted helpers
receive an up-front debited share through an original mode-0600 read-only fd 5,
bound to parent PID/start/namespace, action, context, source and private custody.
There is no reported-unused refund. Candidate captures replace fd 5 with
`/dev/null` and strip kernel metadata.

A private once-per-original-pass marker is set before helper reservation work,
not inferred from the remaining parent-slot count. Even a partial reservation
failure consumes the attempt: retry cannot charge another slot or redistribute
the remaining allowance. Interleaved repeat requests and the actual private
launch engine used by parent collect dispatch retain the original query count.
Only the existing controller query-pass lifecycle resets this marker.

Discovery is not a whole-runner timeout. The live holder finishes before a
long runner; original post-capture admission supplies its own existing clock
but uses the same remaining allocation. Controller returned-state and terminal
checks use the pre-reserved remainder. Exhaustion refuses, including receipt
or finalization failure. Direct stacked calls without a source-owned governing
scope refuse instead of minting an implicit per-method budget.

Input/output/kernel originals are independently bound, hashed and flushed
before parsing and checked again on completion. Final live credential/fd
checks, known exit zero, EOF and a durable receipt precede inventory acceptance.
Failed intent/originals remain private. Receipts, JSON, booleans, callbacks and
retained nonces cannot construct a live session. All collectors and final
capture checks finish before final companion unlink; nothing new runs after
successful commit. Synchronous filesystem limits remain cooperative.

Portable controls (no native acceptance):

```text
php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml.dist tests/unit/UntrustedKernelMountTest.php
python -B -m unittest discover -s tests/unit -p test_kernel_mount_holder.py -v
```

Current synthetic-admission fixtures match the complete admission body exactly,
including the stacked-mount observation guard and kernel visibility probe.
LF/CRLF, reversible substitution, source-binding, already-installed and Windows
refusal controls remain separate from native acceptance. Removing the guard or
changing the probe must refuse substitution, not select a looser source model.
The admission-only pipeline assertion requires collector finalization before
stream verification and return; the CI safeguard assertion includes the Python
holder controls in its exact command sequence.

Completed capture registration uses a private named collector method and an
exact class/function owner check, not PHP's version-dependent closure backtrace
name. Portable owner controls run on PHP 8.0 and newer without kernel reads or
`fsync`; they verify bookkeeping and rejection of arbitrary scoped closures,
external receipt/caller data, changed custody and duplicate registration.
They cannot establish live proof. Run the same controls on PHP 8.0 and PHP 8.4,
then separately rerun the genuine PHP 8.4 unique boundary below to verify the
actual holder-to-durable-finalization path.

Native definitions must be run separately by the owner of an already-approved
nonroot Linux environment. Supply a fresh, pre-existing, empty mode-0700 owned
directory and the existing approved inspection environment:

```text
php tests/native/kernel-mount-boundary.php ABSOLUTE_PRIVATE_DIRECTORY unique
php tests/native/kernel-mount-boundary.php ABSOLUTE_PRIVATE_DIRECTORY unsafe-exec
```

Use separate directories and existing credential lanes; do not manufacture a
lane, install tools or change privileges for these controls. `unique` exercises
the current live holder and original controller query clock, independent proc
checks and complete private receipt. It refuses a stacked fixture rather than
claiming stacked coverage. `unsafe-exec` requires genuine pre-existing unsafe
credentials and verifies refusal with empty holder transport before exec.
Neither definition calls Docker, proves daemon admission or runs candidate QA.

Native acceptance still requires independently observed proc/sysfs identity
nodes, genuine pre-existing stacked-positive fixtures (including a visible
row neither first nor maximum, hidden parents and same-device aliases), races
and exact current source/floor/original-ZIP consumers. Missing genuine stacks
block that acceptance, not portable controls. Required hosted Contract, Full
MCP and package checks remain separate; a different exit or synthetic success
does not replace them. Existing historical proof generations remain immutable;
the publisher must compose a new additive outer source seal before running
historical source-bound safeguards.

Native discovery and original floor selection use the existing Linux pidfd
supervisor: ten seconds per query, at most 64 queries/120 seconds per discovery
pass, and four MiB per original stream. Captures and failures remain private;
closed diagnostics never publish exception text or originals. Filesystem
verification is cooperative, not a hard storage-I/O deadline. The floor
selector preserves original config/hash and conflicting inputs through the
bootstrap. Source-only floor selection is incompatible with original-ZIP
package QA, including offline package mode; its early refusal is captured
before any build/extraction. Successful selection never substitutes for native
admission, fixture authority, cleanup leases, custody or publication approval.

The synthetic native-authority exception observer disables all observers before
attempting its closed location record. A failed writer reports once and rethrows
the original Throwable into PHP's default fatal handler, retaining the native
255 exit and original private trace rather than delegating into another observer.
This test-only behavior does not change production diagnostic channels.

| GitHub Actions check | Command | What it proves | When it should run |
| --- | --- | --- | --- |
| 1 - Static QA | `composer qa:static` | PHP files parse, WordPress Coding Standards pass, PHPStan level 5 passes against reviewed baseline debt with working regression/ratchet guards, Composer dependencies have no known locked advisories, the E2E manifest is structurally valid, security-sensitive QA policy checks pass, and the diff has no whitespace errors. | Every PR and every push to `main`. |
| 2 - Unit Tests | `composer qa:unit` | Small pieces of PHP logic behave correctly without booting WordPress. These tests are the fast safety net for sanitization, response shape, permission helpers, SEO metadata normalization, taxonomy helpers, plugin safety logic, and failure paths. | Every PR and every push to `main`. |
| 3 - Ability Contract QA | `composer qa:contract` or `bash scripts/e2e-test.sh contract` | WordPress boots in Docker, required plugins load, every registered ability is represented in `tests/e2e/abilities-manifest.json`, manifest cases execute through `wp_get_ability()->execute()`, permission-sensitive cases pass, and the debug log stays clean. | Runtime PRs, ability PRs, security-sensitive PRs, `main`, releases, and manual dispatch. |
| 4 - Full MCP E2E QA | `composer qa:e2e` or `bash scripts/e2e-test.sh e2e` | A real MCP HTTP JSON-RPC session can discover and execute abilities through the MCP Adapter, including Application Password authentication, session setup, tool discovery, editor CRUD, and subscriber denial. | Runtime PRs, ability PRs, security-sensitive PRs, `main`, releases, and manual dispatch. |
| 5 - Release Package QA | `composer qa:release` or `bash scripts/release-qa.sh` | Release metadata is aligned, Ability Contract QA and Full MCP E2E QA pass, the release zip contains only packaged plugin files, and WordPress Plugin Check evaluates the built package instead of the development checkout. This check must pass before the protected WordPress.org SVN deploy job can run. | Release PRs, tags, and manual dispatch. |
| 6 - Compatibility QA | `.github/workflows/compatibility-qa.yml` | Scheduled/manual Docker QA discovers official upstream releases, isolates pinned and candidate WordPress/MCP Adapter combinations, and promotes passing versions through a maintainer-reviewed PR while exercising ability contracts, MCP transport, and debug-log cleanliness. | Weekly schedule, manual dispatch after an upstream release, release-candidate investigation, and upstream-breakage triage. |

Static QA runs the full toolchain on PHP 8.0 and syntax checks on PHP 8.4; Unit Tests run on both versions. Each workflow has a stable aggregate result. `Docker QA gate` reports successful change detection and the required Docker results; failure-induced skips cannot pass it. A separate workflow lint check runs actionlint, ShellCheck, and zizmor without making local PHP QA depend on Docker.

The `2 - Unit Tests` gate also requires a separate ten-minute Ubuntu 24.04
synthetic controller-component job using preinstalled Python 3.11 or newer.
`scripts/test-controller-components.py` fails unsupported/denied pidfd hosts
rather than accepting skipped tests. Its result ledger requires the exact ten
component IDs to start, finish and pass; skips, expected failures, substitutions
and partial execution fail. Eight additional in-process harness regressions
check that accounting and scoped retention; they are not part of the ten
controller cases and do not launch children.

The job reserves a fresh mode-0700 namespace under runner temporary storage.
The test class, not the launcher, creates its absent capture child. Always-run
retention copies only allowlisted synthetic regular files, hashes their original
bytes and records intentional symlinks as metadata without following targets.
The synthetic bundle/result ledger is retained for seven days even when tests
fail. File/count/byte retention limits are synthetic CI safeguards, not the
WordPress benchmark's payload or memory budgets. This job neither starts
WordPress/Docker nor invokes the benchmark entrypoint, reads private producer
directories, or proves durable private custody, receiver completion, floor,
transport or original-package correctness. Local `composer qa` includes Python
AST and mock-only prerequisite controls, but no native provisioning or Docker dependency.

Database privacy's three real responses retain exact full-payload metric/order parity. To avoid comparing opposite sides of an existing transient's natural expiration, the disposable runner first admits a read-only 15-second expiration horizon, bounded by one shared 90-second run deadline. It uses production timeout conversion and the strict expiration boundary, records no transient identities/values, and never retries failed payloads. Exhaustion, SQL failure, clock/window overrun, newly changed expirations, and other metric drift remain failures with original responses retained. Synthetic expiration tests supplement, rather than replace, the genuine native/HTTP original-package proofs described in `tests/e2e/README.md`.

The current compatibility baseline is WordPress 7.1.2 with MCP Adapter 0.6.1.
The default Docker image is `wordpress:7.1.2-php8.2-apache`; other pinned
dependencies and their digests are unchanged. `readme.txt` retains the
major/minor `Tested up to: 7.1` header. This baseline update does not change
the declared WordPress 6.9 / PHP 8.0 plugin minimums. The existing compatibility
floor lane uses PHP 8.1 and is not genuine PHP 8.0 runtime evidence.

## PHPStan level 5 and baseline ratchet

`composer phpstan` analyses the plugin entry point and all of `includes/` at level 5, explicitly targeting PHP 8.0 regardless of the local interpreter. `phpstan-baseline.neon` records pre-existing diagnostics, not permission to introduce new ones. Each generated ignore has an anchored message, identifier, positive count, and individual file path. There are no excluded production paths or identifier-only/global ignores. The existing `treatPhpDocTypesAsCertain: false` policy and WordPress stubs bootstrap are retained; no extra bootstrap constants or relaxed rules hide debt.

The initial reviewed baseline contains 35 diagnostics in 21 entries across nine files:

| Existing diagnostic group | Count | Review context |
| --- | ---: | --- |
| Missing WordPress constants | 9 | `ARRAY_A`, `WP_CONTENT_DIR`, `WP_PLUGIN_DIR`, `DAY_IN_SECONDS`, and `MINUTE_IN_SECONDS` are runtime/bootstrap knowledge absent from the current analysis setup. Kept as explicit debt, not globally suppressed. |
| Argument types | 4 | Media sideload array typing and post-update array shapes after `wp_slash`; investigate with WordPress stub and runtime evidence before changing calls. |
| Media callback control flow | 16 | Always-true/false comparisons, boolean expressions, and unreachable code around by-reference state in deferred download hooks. Do not delete runtime safeguards merely to satisfy inference. |
| Private late-static method calls | 2 | Media DNS validation calls need separate inheritance/typing review. |
| Database error-state comparisons | 2 | Defensive checks of mutable `$wpdb->last_error` after database calls. |
| Revision error check and sitemap offset | 2 | One impossible-type diagnostic and one redundant null-coalescing diagnostic; assess separately before simplifying defensive code. |

A green run means no diagnostics beyond that reviewed debt, not that these findings are fixed or that all mixed input/output types are sound. The baseline matches counts per message/identifier/file, not line or expression identity: replacing a removed error with the same error elsewhere in the same file can evade the count ratchet and still needs human diff review. This tooling change does not validate ability schemas, enums, output contracts, or runtime permissions.

The PHPStan 2.2.15 update removes the now-unmatched sitemap offset entry. With `treatPhpDocTypesAsCertain: false`, the updated regex output inference no longer reports the null-coalescing fallback as redundant. The source fallback is unchanged; this is a baseline compatibility adjustment, not a runtime fix or proof that the sitemap debt was resolved.

The current-main integration also retains the branch's later post-extraction
debt reductions: the pinned 2.2.15 baseline remains exactly 17 entries / 30 errors.
The initial table above is historical, not the current ignore inventory. Neither
the removed sitemap offset nor removed posts entries are restored by the merge.

### Reducing debt

1. Fix the underlying issue with focused behavior/typing evidence. Do not add casts, assertions, inline ignores, broader types, or remove defensive checks solely to silence analysis.
2. Run **the complete** `composer phpstan`. `reportUnmatchedIgnoredErrors: true` rejects removed diagnostics and reduced counts until the corresponding baseline entry is removed or its count lowered. Passing individual filenames to PHPStan skips unmatched-entry checking and is not ratchet evidence.
3. Remove only resolved entries or reduce their counts. If regeneration is necessary, run `composer phpstan -- --generate-baseline=phpstan-baseline.neon`, then review the entire baseline diff. Reject unrelated additions, increased counts, wildcard paths, widened messages, and removed identifiers. Do not regenerate the baseline just to make a new error pass.
4. Run `composer phpstan` and `composer test:phpstan-baseline` again, followed by `composer qa`. Commit the fix and baseline reduction together. New baseline debt needs explicit separate review and rationale; normal changes must not grow it.

Zero debt is a valid endpoint: after fixing the final diagnostic, keep the baseline file with `ignoreErrors: []` nested under `parameters:`. The guard accepts an empty baseline while continuing to check every remaining entry and the full-project analysis policy.

`composer test:phpstan-baseline` checks the effective repository configuration and ignore scopes, then generates isolated synthetic PHP under a random `build/phpstan-tests-*` directory. It inherits the real rules and unmatched-ignore setting, proves existing fixture debt passes, and requires nonzero analysis with the expected diagnostics for new level-5 argument/return errors, identical errors in another path, different messages under the same identifier, excess counts, partially stale counts, and fully stale entries. Lowering a fixed count and removing a resolved entry must restore a clean run. Fixtures are analysed, never executed, and cleaned up on exit; they are not shipped in the plugin.

The guard runs in `composer qa:static` and in `composer test:ci-safeguards`, so CI exercises it on PHP 8.0 and 8.4 without changing the runtime test suites. PHPStan dependency upgrades remain a separate review because inference or diagnostic wording changes can alter baseline matching.

## Security QA policy

Security QA is a cross-cutting gate, not just a separate scanner. The permission issue fixed in PR #90 showed that valid syntax, WPCS, and broad ability coverage are not enough unless the tests explicitly prove the permission model.

For every new or changed `webmastery-site-toolkit-for-mcp/*` ability, review whether it is security-sensitive. An ability is security-sensitive when it does any of the following:

- reads private, draft, pending, scheduled, trashed, user, environment, plugin, security, database, backup, or performance data
- returns full object payloads, totals, author identity, login names, emails, backend versions, filesystem/plugin details, or other fingerprinting data
- creates, updates, deletes, publishes, schedules, privates, restores, activates, deactivates, uploads, or otherwise changes site state
- accepts object IDs, URLs, HTML, metadata keys, taxonomy terms, plugin identifiers, statuses, or role/capability-sensitive inputs

Security-sensitive abilities must include:

1. A positive manifest case for a role/capability that should be allowed.
2. A negative manifest case for a role/capability that should be denied, unless the ability intentionally returns public-safe data and that rationale is documented.
3. Object/status-aware assertions for list/query abilities that can return mixed authorization results.
4. `assert_missing_paths` for sensitive fields that must not appear for lower-privilege callers.
5. Failure-path assertions for status escalation, protected metadata, unsafe URLs, ambiguous identifiers, stale preconditions, and destructive actions where applicable.

`composer validate:security-qa` enforces the current high-risk policy:

- `permission_callback => '__return_true'` is blocked in plugin ability registrations unless intentionally allow-listed in the validator after explicit security review.
- The permission-hardening regression cases from PR #90 must keep negative manifest coverage.
- Comment update, approval, trash, and spam abilities must keep moderator-only denial cases with persisted content/status assertions, including an update that supplies a moderation status.
- Comment QA also requires mapped-CPT allowed/denied cases, the Author moderation floor, zero-ID no-write controls, and exact missing-object results for allowed/denied callers. A dedicated CLI-only runner compares direct, ability, and real HTTP behavior and retains comment table/metadata hashes and raw transport envelopes.
- Sensitive identity and fingerprinting absence assertions must remain in the manifest.

This validator is intentionally conservative. It should catch known dangerous patterns without replacing human review, WordPress.org review, or future deeper static analysis such as CodeQL or Semgrep.

## Ability Contract QA vs Full MCP E2E QA

These two checks both use Docker WordPress, but they prove different things.

Ability Contract QA is the plugin contract layer. It asks: "Inside WordPress, did this plugin register the abilities we expect, and do the manifest cases pass with the right permissions and response shapes?" It is broad and ability-driven.

Contract QA additionally runs `tests/e2e/trash-safety-runner.php` in two fresh PHP processes. Each defines and verifies `EMPTY_TRASH_DAYS` before loading actual WordPress core: `30` for normal trash/restore and `0` for refusal before mutation. These lanes exercise registered abilities, not stubs, and report separately from the ordinary manifest counts. See [`tests/e2e/README.md`](../tests/e2e/README.md#isolated-trash-safety-regressions) for assertions and coverage boundaries.

Full MCP E2E QA is the real transport layer. It asks: "Can an MCP client actually talk to WordPress through the MCP Adapter and perform real work?" It is narrower but deeper, because it uses Application Passwords, MCP session initialization, `tools/list`, ability discovery, and real CRUD calls over HTTP JSON-RPC.

Both layers matter. Contract QA catches broad ability drift and permission regressions. Full MCP E2E catches transport and integration problems that direct PHP execution cannot see.

## Trigger matrix

| Event or change type | 1 - Static QA | 2 - Unit Tests | 3 - Ability Contract QA | 4 - Full MCP E2E QA | 5 - Release Package QA | 6 - Compatibility QA |
| --- | --- | --- | --- | --- | --- | --- |
| Docs-only PR | Required | Required | Skipped unless manually dispatched | Skipped unless manually dispatched | Skipped | Skipped |
| Runtime PR | Required | Required | Required | Required | Skipped unless release-impacting | Manual when compatibility-risky |
| New or changed ability | Required | Required | Required | Required | Skipped unless release-impacting | Manual when dependency/version-risky |
| Security-sensitive PR | Required | Required | Required | Required | Required when release-bound | Manual before release when risk is broad |
| Push to `main` | Required | Required | Required when runtime files changed | Required when runtime files changed | Skipped | Scheduled/manual |
| Release PR | Required | Required | Required | Required | Required | Manual for release candidates |
| Tag `v*` | Required by release workflow | Required by release workflow | Covered by Release Package QA | Covered by Release Package QA | Required | Review current compatibility evidence before approval |
| `workflow_dispatch` | Runs selected workflow | Runs selected workflow | Runs | Runs | Runs | Runs |
| Weekly schedule | Skipped | Skipped | Skipped | Skipped | Skipped | Runs |

Runtime-impacting paths include plugin source, tests, scripts, Docker configuration, Composer files, workflow files, package metadata, assets, and `readme.txt`.

## Required main CI checks

The active `main-ci-gates` ruleset requires exactly these GitHub Actions checks before merging any PR into `main`:

- `1 - Static QA`
- `2 - Unit Tests`
- `Docker QA gate`

For runtime-impacting, ability, or security-sensitive PRs, `Docker QA gate` requires both runtime checks to pass; they are not separate required-check entries in the ruleset:

- `3 - Ability Contract QA`
- `4 - Full MCP E2E QA`

Require release/package QA before publishing a release:

- `5 - Release Package QA`

Use `6 - Compatibility QA` as a scheduled/manual maintainer gate at first. Promote individual compatibility jobs to required only after they are stable and low-noise.

The important GitHub concept is "required status checks." A workflow can run on many events, but branch protection decides which successful checks are required before a PR can merge.

Enforcement was activated and read back on September 17, 2026, after all five genuine PR-event workflows passed on bot PR #140. See the [dated setup evidence](../.github/SETUP-COMPLETE.md#rollout-evidence), which distinguishes the dispatched compatibility pipeline from the approved PR-event checks. `workflow_dispatch` job results do not satisfy branch-ruleset requirements. Future `GITHUB_TOKEN`-created PRs still need maintainer workflow approval; the initial rollout did not create an auto-approval bypass. The path-filtered Release Package QA workflow must not become a required PR check in its current form.

## Which command should I run?

For a docs-only change:

```bash
composer qa
```

For a normal code bugfix:

```bash
composer qa
E2E_MANAGE_COMPOSE=1 composer qa:contract
E2E_MANAGE_COMPOSE=1 composer qa:e2e
```

For a new or changed ability:

```bash
composer qa
E2E_MANAGE_COMPOSE=1 composer qa:contract
E2E_MANAGE_COMPOSE=1 composer qa:e2e
```

For a security-sensitive change:

```bash
composer qa
composer validate:security-qa
E2E_MANAGE_COMPOSE=1 composer qa:contract
E2E_MANAGE_COMPOSE=1 composer qa:e2e
```

For release preparation:

```bash
composer qa
composer qa:release
```

`composer qa:release` runs the Docker contract and transport checks before Plugin Check. If Docker is unavailable locally, use GitHub Actions for the authoritative release validation and document the local blocker in the PR.

Release QA builds once (or accepts `RELEASE_ZIP`) and validates exact source/ZIP allowlist hashes before extraction. Runtime QA mounts `build/release-runtime/webmastery-site-toolkit-for-mcp`, not the checkout, through `docker-compose.release.yml`. Only `tests/`, the two compatibility helpers, the baseline JSON, and `e2e-artifacts/` are additional binds; no checkout-wide or `vendor/` mount can supply an unpackaged dependency. Plugin Check copies a separate pristine `build/release-check` extraction, so nested runtime mount placeholders cannot affect its findings. After QA, the actual runtime production tree must still match the ZIP: only the exact empty host-side bind placeholders are permitted, not arbitrary extra files or directories. The original ZIP's SHA-256 must also remain unchanged.

Use a uniquely named disposable project, for example `COMPOSE_PROJECT_NAME=wstm-release-mytest MYSQL_PORT=0 WORDPRESS_PORT=0 REQUIRE_CURRENT_PLUGIN_CHECK=1 COMPOSER_PROCESS_TIMEOUT=0 composer qa:release`. The timeout override lets the full local runtime/checker sequence exceed Composer's default 300 seconds; GitHub release jobs invoke Bash directly under workflow time limits. Ordinary `composer qa:contract`/`qa:e2e` retain the checkout bind. Package mode explicitly selects the base and release Compose files for runtime, checker, and cleanup, rather than inheriting a caller's `COMPOSE_FILE`. Do not reuse a shared Compose project. `composer test:release-safeguards` exercises the real release/E2E orchestration with safe Docker stubs, malformed/missing package guards, a checkout-mode negative control, checker failures, and archive-identity mutation without Docker or network access.

PowerShell users can use the local wrapper:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release
```

Bash users can use the local wrapper:

```bash
scripts/qa-local.sh
scripts/qa-local.sh --contract
scripts/qa-local.sh --e2e
scripts/qa-local.sh --release
```

## WordPress.org release-readiness policy

Before publishing a WordPress.org-facing release:

1. Confirm version metadata matches across the plugin header, `readme.txt` stable tag, changelog, release notes, zip name, and tag.
2. Run `composer qa` and `composer qa:release`.
3. Confirm Plugin Check evaluates the built package under the canonical `webmastery-site-toolkit-for-mcp` slug.
4. Confirm no unexpected WordPress debug-log warnings, notices, deprecations, or errors appear during Docker QA.
5. Confirm security-sensitive changes have manifest evidence for allowed and denied access.
6. Review the official [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) for release-impacting changes, especially licensing, bundled assets, external services, tracking consent, executable remote code, readme content, default WordPress libraries, SVN discipline, version increments, and trademarks.
7. Run or review the latest `6 - Compatibility QA` result before approving WordPress.org SVN deployment.
8. Document any known Plugin Check warnings, guideline considerations, or unavailable local tooling in the PR.

Plugin Check supports the guideline review, but it does not replace maintainer responsibility for the final WordPress.org package contents and behavior.

## Compatibility policy

The default PR path should stay stable and reasonably fast. Compatibility QA starts as scheduled/manual coverage because upstream images, plugin versions, and dependency repositories can change independently of a PR.

The current compatibility workflow reads `.github/compatibility-versions.json`, discovers the latest stable releases, and runs:

- WordPress 6.9 on PHP 8.1 with the pinned MCP Adapter baseline
- the exact pinned WordPress and MCP Adapter baselines
- the pinned WordPress baseline with the latest stable MCP Adapter
- the latest stable WordPress release with the pinned MCP Adapter baseline
- both latest releases together when both changed during the same interval

All lanes pull fresh images, run both Ability Contract QA and Full MCP E2E QA, fail on WordPress debug-log warnings/notices/deprecations/errors, and record resolved dependency versions in the GitHub Actions job summary. After a successful scheduled run, newer versions are proposed in an automated PR that updates the baseline file and the concrete runtime pins. Maintainer approval and merge remain mandatory.

Additional coverage separates PHP 8.4, MySQL 8.4, floating SEO dependencies, and current Plugin Check package validation. Package-check lanes run the applicable package command; they are not interchangeable with the contract/transport lanes. Ordinary QA uses the reviewed dependency pins and executable-download digests in `.github/compatibility-versions.json`.

Only tested candidates may update baselines or `readme.txt` `Tested up to`. Missing images, failed discovery, stale source history, and unavailable dependencies are explicit failures that block promotion. Baseline updates preserve the full configuration and PHP image suffix. Approval of the bot PR's genuine PR-event workflows is required; a successful manual dispatch is not a substitute.

The checked-in primary baseline is WordPress 7.1 with MCP Adapter 0.6.1 and its verified SHA-256. This adapter update leaves WordPress, `Tested up to`, other dependency pins, and PHP support unchanged.

At the September 17, 2026 verification, `main` at `3dae8aa` pinned MCP Adapter 0.5.0. The passing compatibility pipeline proposed 0.6.1 in PR #140, which then remained open and unmerged. Those historical results do not replace current-source compatibility and genuine PR checks before promotion.

### Runtime coverage limitation

The advertised plugin minimum remains PHP 8.0. Syntax and unit tests exercise that version, but the `wp69-php81-compatibility` WordPress Docker lane uses PHP 8.1 and is not actual PHP 8.0 floor proof. The candidate verifier defaults to strict `php80-floor`; the hosted lane explicitly uses strict `php81-compatibility` and reports the remaining minimum-runtime requirement. Proof-tool hosts requiring `fsync` need PHP 8.1+, independently of the plugin minimum. This is an integration-coverage gap, not authorization to raise the minimum, weaken evidence checks or invent an unsupported Docker tag. Add a maintained genuine PHP 8.0 WordPress integration fixture with separately supported proof hosts before claiming that floor.

The exact-candidate job uses the sibling jobs' pinned PHP 8.4/POSIX proof host
and canonical interpreter binding independently of the WordPress image. Its
full E2E step supplies `runner.temp` to the existing authority bootstrap, which
still requires a canonical owned root, exclusive private reservation and actual
topology/custody admission. Wiring or synthetic checks do not establish those
runtime prerequisites or the separate PHP 8.0 floor.

PHPCompatibilityWP's available stable rules depend on the older PHPCompatibility engine. Installing those rules alone does not establish PHP 8.4 compatibility. Runtime evidence remains necessary; a future compatibility-sniff dependency must be reviewed for its actual supported language versions.

When compatibility failures occur, triage them as:

- **release blocker** when the failure affects the supported floor, current WordPress line, or another supported WordPress/PHP combination
- **upstream drift** when a floating WordPress or latest MCP Adapter dependency changed and the plugin needs an adjustment or documented compatibility boundary
- **workflow maintenance** when the failure is caused by runner/image/tooling changes unrelated to plugin behavior

## Environment notes

The QA layers are the same in every environment, but the safest entrypoint differs by shell.

### GitHub Actions

GitHub Actions is the authoritative validation gate before merge. The workflows install their own PHP and Composer runtime, use Ubuntu shell tools, and run Docker jobs on GitHub-hosted Linux runners.

Use branch protection to require the relevant workflow checks instead of relying only on a contributor's local machine.

### Windows PowerShell

PowerShell users should prefer the local wrapper because it checks prerequisites and gives Windows-specific hints:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Contract
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -E2E
powershell -ExecutionPolicy Bypass -File scripts/qa-local.ps1 -Release
```

Docker Desktop must be running for Docker QA. Native `php`, `composer`, and archive tooling may depend on Windows PATH setup. When the native PHP/Composer path is not available, the project can still run many checks through Docker-based PHP/Composer commands.

### Windows Git Bash

Git Bash users should prefer login-shell style commands for Docker QA:

```bash
bash -lc './scripts/e2e-test.sh contract'
bash -lc './scripts/e2e-test.sh e2e'
bash -lc 'bash scripts/release-qa.sh'
```

The Docker runner sets `MSYS_NO_PATHCONV=1` so Git Bash does not rewrite Linux container paths such as `/var/www/html/wp-load.php` into Windows paths. This matters because Docker commands in the runner execute inside Linux containers, even though the shell is running on Windows.

Depending on local PATH and Docker Desktop setup, `bash scripts/...` and `bash -lc './scripts/...'` can resolve Docker shims differently. If a plain Git Bash invocation fails locally but GitHub Actions passes, retry with the login-shell form above before assuming the repo script is broken.

### Local caveats

Docker Desktop must be running before Docker QA can start. WordPress, MCP Adapter, Yoast SEO, SEOPress, Plugin Check, and release package checks all depend on network access during fresh local runs.

Local Plugin Check output may include known warnings that are acceptable for this project. The release process should treat unexpected errors as blockers, while the documented warnings in `CONTRIBUTING.md` remain known review items.

## How to read failures

Static QA failures usually mean the code has a syntax, style, static-analysis, manifest-shape, security-policy, dependency-audit, or whitespace problem. Fix these first because they are the cheapest.

Unit test failures usually mean a small helper contract changed. Either fix the behavior or intentionally update the test if the contract changed.

Ability Contract QA failures usually mean the plugin and manifest disagree, a permission case is wrong, an ability response shape changed, an expected sensitive field appeared, or WordPress logged a problem.

Full MCP E2E failures usually mean the real MCP Adapter path broke: session setup, tool discovery, ability execution, WordPress side effects, denied-role behavior, or cleanup.

Release Package QA failures usually mean the package is not ready to ship: Docker contract/transport checks failed, version metadata is out of sync, release notes are missing, dev files leaked into the zip, or Plugin Check found a WordPress.org readiness issue.

Compatibility QA failures usually mean upstream WordPress, PHP, MySQL, MCP Adapter, Yoast SEO, SEOPress, Plugin Check, or GitHub runner behavior changed and needs maintainer triage.
