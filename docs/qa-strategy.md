# QA strategy

QA checks the plugin itself: correctness, permissions, response privacy,
WordPress compatibility, MCP interoperability, data integrity and the behavior
of the installed release package. Permissions, response privacy and cleanup
require explicit assertions; a successful request alone is insufficient.

WordPress.org approval and directory listing are outside product QA. Plugin
Check and directory guideline review belong to the release process and are
reported separately from plugin behavior.

Related: [CI/CD strategy](ci-cd-strategy.md), [release strategy](release-strategy.md),
[security strategy](security-strategy.md), and the detailed
[QA harness reference](qa-harness-reference.md).

## QA checks

| Check | Command | Required evidence |
| --- | --- | --- |
| `1 - Static QA` | `composer qa:static` | PHP syntax, WPCS, PHPStan level 5 and baseline ratchet, locked dependency audit, manifest/security policy and whitespace checks. |
| `2 - Unit Tests` | `composer qa:unit`, `composer qa:tooling`, `composer test:ci-safeguards` | Product unit tests on genuine PHP 8.0 and 8.4 on every run; QA-tooling unit tests and CI safeguards on PHP 8.4 when tooling-relevant files change, nightly and on dispatch. The stable aggregate requires product units plus every applicable tooling job. |
| `3 - Ability Contract QA` | `composer qa:contract` | Actual WordPress registration and manifest execution, allowed/denied roles, response shape, persisted no-write checks and clean debug log. |
| `4 - Full MCP E2E QA` | `composer qa:e2e` | Actual HTTP JSON-RPC authentication, sessions, discovery, CRUD, authorization and cleanup through MCP Adapter. |
| `5 - Release Package QA` / `Package QA gate` | `composer qa:package` | Original-ZIP layout/metadata/hash validation and contract/transport/product checks against installed production contents; no directory checker requirement. |
| Minimum runtime / `Minimum runtime QA gate` | `bash scripts/qa-floor.sh` | Digest-pinned WordPress 6.9 on genuine PHP 8.0.30, actual CLI/HTTP lane observations and the same source acceptance tests. |
| WordPress.org publishing checks | `composer qa:publishing` | Plugin Check against a pristine original-ZIP extraction. Setup is not product acceptance or directory approval. |
| Combined release verification | `composer qa:release` | Both original-ZIP product QA and publishing checks; existing publication controls remain unchanged. |
| `6 - Compatibility QA` | `.github/workflows/compatibility-qa.yml` | Scheduled/manual upstream discovery, supported combinations, exact resolved versions and reviewed baseline promotion. |
| Workflow lint | `.github/workflows/workflow-lint.yml` | Actionlint, ShellCheck and zizmor using the maintained pinned lint tools. |

The primary runtime baseline is WordPress 7.1.3, PHP 8.2 and MCP Adapter 0.7.0,
pinned in `.github/compatibility-versions.json`. The advertised minimums remain
WordPress 6.9 and PHP 8.0. Static QA runs its full toolchain on PHP 8.0 and
syntax checks on PHP 8.4.

## Trigger and merge policy

Static QA and product unit tests run on every PR and main push. Everything else
runs when its inputs change, decided by `scripts/qa-changes.sh`:

| Scope | Used by | Runs when |
| --- | --- | --- |
| `default` | QA-tooling units and CI safeguards | Any QA-relevant file changes (source, tests, scripts, workflows, Composer, Compose). |
| `runtime` | Docker QA (Contract + Full MCP E2E) | Any change except a known non-runtime path: docs and Markdown, `tests/unit/`, `.github/` metadata and other workflows, and release-only scripts. Unknown or quoted paths always run. |
| `package` | Release Package QA | ZIP inputs change: main plugin file, `readme.txt`, `.gitattributes`, `assets/`, `.wordpress-org/`, and the build and package scripts. |

The minimum-runtime lane runs on PRs only when its own workflow, `qa-floor.sh`
or the PHP 8.0 fixture changes. `schedule` and `workflow_dispatch` always run
every scope in full. Unit tooling, package QA and the minimum-runtime lane run
nightly on `main`, so plugin changes reach the ZIP and WordPress 6.9 / PHP 8.0
checks within a day.

The `main-ci-gates` ruleset requires `1 - Static QA`, `2 - Unit Tests` and
`Docker QA gate`. Each is an aggregate job: it passes only when change detection
succeeded and every applicable job succeeded, or was skipped because detection
said it did not apply. Failure, cancellation or an unexpected skip fails it.
`Package QA gate` and `Minimum runtime QA gate` follow the same rule but are not
merge requirements. Instead, a release requires both suites to have actually
run and passed on the tagged commit; see [release strategy](release-strategy.md).

## CI budgets and pruning

Keep QA proportionate to what it protects. These budgets are targets for the
wall-clock time of GitHub Actions on a typical change:

| Path | Budget | Today |
| --- | --- | --- |
| PR checks for a plugin-code change | 20 min | About 16 min; Docker QA and the tooling lane are the longest jobs |
| PR checks for docs-only changes | 5 min | About 2 min |
| Release job, excluding approval wait | 15 min | About 10 min expected; first measured on the next release |

When a change would push a path over its budget, raise it in the PR and
propose what to move off the critical path (nightly, change-scoped, or
parallel) before adding more serial work.

Every gate, safeguard or test that tests QA tooling must name the failure it
prevents, in its docstring, comment or PR description. Review the gates
quarterly: propose removing any that have not failed in that period, or that
guard against a failure other checks already catch. Prefer one readable policy
test over byte-for-byte freezes of workflow files.

## Suite classification and measurement

PHPUnit has two suites, mirrored from `tests/qa-suites.json`: `product` (30
files that test plugin code; seconds to run) and `qa-tooling` (79 files that
test QA, release and evidence tooling; about 98% of unit runtime).
`python3 -B scripts/qa-profile.py inventory` fails if a test file is
unclassified or `phpunit.xml.dist` drifts from the catalog. Mixed files keep all
their tests in their primary category.

`scripts/qa-profile.py` also turns a PHPUnit `--log-junit` report or a GitHub
Actions jobs listing into diagnostic timings. Its output never selects tests
or counts as acceptance. Earlier detailed measurements are in this file's git
history.

## Execution location

Run development QA where the coding session runs: a local session uses the
local Docker engine through `scripts/qa-run.sh` and the `qa-local` wrappers; a
cloud session uses its own containers or GitHub Actions. Do not dispatch a
cloud workflow merely to replace an available local run. The location changes,
not the assertions: both use the same manifests, runners, pins and package
checks. PR checks still validate the submitted commit. If the environment
cannot run a required check, report it as blocked rather than switching
engines or weakening tests.

## Environment notes

- Docker is the default engine. The Podman API adapter is experimental and its
  runtime behavior is unverified; no Podman machine is provisioned
  automatically.
- Native Windows PHP cannot execute the POSIX ownership and mode assertions.
  Run those unit tests in a disposable Linux environment instead of weakening
  them.
- Git Bash container commands keep `MSYS_NO_PATHCONV=1`; Docker Desktop must
  be running, and fresh image setup needs network access.
- Keep the full unit suite within its 128 MiB memory limit.
- Report missing tools or failed setup; never convert them into skipped
  acceptance.

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
