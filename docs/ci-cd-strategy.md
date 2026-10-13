# CI/CD strategy

Local coding sessions use local disposable containers for development QA.
GitHub cloud-agent sessions use containers in their execution environment or
GitHub Actions. Actions also runs independent pull request checks, real Docker
WordPress/MCP validation, scheduled compatibility checks and protected release
publishing. The
[QA strategy](qa-strategy.md) defines each check's evidence; the
[software development lifecycle](sdlc-overview.md) explains the review stages.

## Workflow inventory

| Workflow | Triggers | Purpose |
| --- | --- | --- |
| `1 - Static QA` | PR, main push, manual | PHP syntax, WPCS, PHPStan/baseline guard, locked audit, manifest/security policy and whitespace. |
| `2 - Unit Tests` | PR, main push, manual | Genuine PHP 8.0/8.4 PHPUnit and separate applicable safeguard matrices with one stable aggregate. |
| `3-4 - Docker QA` | PR, main push, manual | Runtime change detection, Ability Contract QA, Full MCP E2E QA, safe evidence and summaries. |
| `5 - Release Package QA` | Every PR, manual; product jobs are change-aware | Original-ZIP installed product checks and always-reporting `Package QA gate`; no directory checker requirement. |
| Minimum runtime QA | Every PR, main push, manual; product job is change-aware | Actual WordPress 6.9 / PHP 8.0.30 contract/HTTP execution and `Minimum runtime QA gate`. |
| WordPress.org publishing checks | Manual | Pristine original-ZIP Plugin Check, separately reported without product acceptance or publication. |
| `5 - Release` | Release tag, guarded recovery dispatch | Main ancestry, Static/Unit/package QA and protected serialized publication. Recovery reuses original validated evidence through a reviewed control tag. |
| `6 - Compatibility QA` | Weekly schedule, manual | Official upstream discovery, pinned/candidate combinations and reviewed baseline-update PRs. |
| `7 - Issue Backlog Import` | Manual | Serialized dry-run-first backlog preview/import. |
| Workflow lint | PR, main push, manual | Actions syntax, shell lint and workflow security using pinned tools. |

## Pull request policy

Every PR needs `1 - Static QA` and `2 - Unit Tests`. Runtime, ability and
security-sensitive changes also need actual `3 - Ability Contract QA` and
`4 - Full MCP E2E QA`. Release-impacting changes need `5 - Release Package QA`
and workflow lint before merge. Broad compatibility is scheduled/manual unless
a supported combination or dependency change makes it relevant to the PR.

Unit tests and safeguards run as separate PHP 8.0/8.4 matrices, each with a
60-minute limit and `fail-fast: false`. They retain applicable main/product
regressions, baseline controls, narrow permissions and interpreter selection.
The `2 - Unit Tests` aggregate requires every applicable matrix result to be
successful; failure, cancellation or unexpected skip cannot pass it. Separate
jobs prevent completed units from consuming the safeguard budget.

Suite classification and optional timing reports are diagnostics, not change
selection or gate inputs. `composer qa:unit` still discovers the complete
`tests/unit` directory, and `composer test:ci-safeguards` retains every existing
command plus the profiler/inventory regressions. Required check names,
interpreter lanes, memory limits and failure/skip handling are unchanged.
Do not upload raw JUnit, fixture output or data-provider labels. The
[measurement procedure](qa-strategy.md#suite-classification-and-measurement)
defines provenance, repeated comparisons and unresolved composite-stage timings.

`Docker QA gate` is a stable required result for runtime and non-runtime PRs.
It rejects failed/cancelled detection, malformed outputs and missing/failed
runtime results. Skipped runtime jobs are accepted only when successful
detection explicitly identifies non-runtime changes.

For local app, CLI and editor work, run development QA against the local source
using disposable Docker through `scripts/qa-run.sh` and the maintained wrappers.
The explicit Podman local-API adapter remains experimental until source/package
runtime and ownership/cleanup verification pass.
For cloud-agent work, run the same applicable tests in the cloud environment;
use Actions when that environment cannot run them. Missing prerequisites must
produce an explicit blocked result, not a silent local-to-cloud fallback.
See the [execution policy and implementation plan](qa-strategy.md#execution-location).

Then publish the reviewed candidate and require genuine checks on that final
PR head. Local, previous-head and manual-dispatch successes do not substitute
protected PR-event checks. Keep original failures and reproduce defects locally
before changing code or test expectations.

## Branch protection

`main-ci-gates` requires exactly `1 - Static QA`, `2 - Unit Tests` and
`Docker QA gate`, bound to GitHub Actions. Stable aggregate names avoid
ambiguous matrix-expanded checks. Do not weaken protection or bypass a failed
check to merge. The new package and minimum-runtime aggregates always report,
but are not yet activated in this live ruleset. Activate them only after merged
workflow availability and successful PR-event verification. Release-bound
changes still require original-ZIP checks under repository policy.

The September 17, 2026 rollout activated ruleset 23522901 only for
`refs/heads/main`, with no exclusions or bypass actors and GitHub Actions
integration 15368. Its `strict_required_status_checks_policy=false` and
`do_not_enforce_on_create=false` settings were unchanged. This is dated setup
evidence, not a current PR result or permission to change repository rules.
See [setup and rollout evidence](../.github/SETUP-COMPLETE.md#rollout-evidence).

Require normal PR review/conversation resolution and the repository's supported
linear-history merge method. Never force-push main, delete unrelated branches
or use environment approval as a branch-protection bypass. Bot-created PRs may
still need maintainer approval before their real workflows run.

## Runtime environment

Hosted Linux runners and the local Docker engine/images are trusted execution
prerequisites. The product checks do not claim whole-host/kernel/loader
attestation. Runtime bootstrap uses the maintained main-supported setup and
verified executable downloads without changing plugin support floors.

Every Compose call uses `scripts/qa-compose.sh`: fixed files, explicit unique
project, ownership-labelled resources and checked teardown. Candidate PHP and
WP-CLI run as UID/GID 33:33. Only a fixed trusted directory initialization runs
as root. HTTP is loopback-only, the database has no host port, source/package
mounts are read-only and no engine socket enters the application containers.

The product context binds actual CLI/HTTP interpreter lanes and expected
source/package identity. PHP 8.1 compatibility, PHP 8.2 primary runtime and
PHP 8.0/8.4 unit evidence are separate claims. A bootstrap smoke, modeled mock
or successful dependency install does not establish product/package acceptance.

## Workflow permissions

Request the smallest scope per job:

- Validation defaults to `contents: read`; fork/bot checks need no release secrets.
- PR reporting uses `pull-requests: write` only where necessary and does not run
  PR-controlled code with privileged reporting credentials. Read-only contexts
  use job summaries instead of requiring a writable comment token.
- The compatibility failure reporter has issue-write access without source
  checkout or release credentials.
- Recovery resolution is read-only with `contents: read` and `actions: read`.
- Protected publication alone uses `contents: write`, necessary cross-run
  artifact read permission and WordPress.org environment secrets.

`SVN_USERNAME` and `SVN_PASSWORD` exist only in the protected `wordpress-org`
environment. Do not duplicate them as repository secrets or expose them to PR
code. Actions remain pinned; dependency/tool updates need ordinary review.

## Environment and publication gates

The `wordpress-org` environment protects **Publish release (GitHub approval
required)**. Normal QA or the recovery resolver identifies the exact
source/tag/run/attempt/artifact before approval. The environment ID, reviewer,
secrets and deployment tag policy remain unchanged. Approval is repository
production approval, not WordPress.org staff review.

The September 21, 2026 read-back recorded DanielBoring as reviewer, self-review
allowed for solo maintenance and administrator environment bypass disabled.
Only `v*` tags may deploy. Administrator control over tag creation does not
bypass production approval.

Recovery requires a separately authorized annotated `v-release-recovery-*`
control tag on reviewed main history. Branch dispatch and ordinary release-tag
dispatch are rejected; control-tag pushes do not publish a new release.
Publication rechecks the approved identity, uses the original successful
source/run/artifact and installs/verifies Subversion before preflight. See the
[release recovery runbook](release-strategy.md#recovery-when-the-frozen-workflow-itself-needs-a-fix).

One shared publish concurrency group serializes normal and recovery publication
without cancelling an active deployment. There is no separate WordPress.org
test SVN; use PR checks, package QA and staging installations first.

## Scheduling and baseline promotion

Compatibility QA discovers official stable WordPress and MCP Adapter releases
and separates supported-floor, pinned, latest and combined-update combinations.
Other lanes cover PHP 8.4, MySQL 8.4, floating SEO plugins and current Plugin
Check. Fresh images and exact resolved versions make upstream drift visible.

Only passing candidates may update `.github/compatibility-versions.json`,
concrete image pins or `Tested up to`. Missing images, failed discovery, stale
history and unavailable dependencies fail explicitly. Newer passing versions
produce maintainer-reviewed PRs, never automatic merges. Genuine PR-event
workflows and merge approval remain required after scheduled success.

Metadata generation uses the maintained updater CLI with all eight
version/digest options, including `--mcp-adapter-sha256`, `--wp-cli-sha512`
and `--confirmed-wordpress`. A metadata parser failure is not a Plugin Check
result or successful promotion.

`composer qa:unit -- --filter CompatibilityBaselinesTest` exercises
workflow-shaped arguments, promotion/no-op behavior and unchanged files after
invalid/duplicate/unknown options and invalid references. These regressions do
not update live pins or replace real runtime evidence.

## Artifacts and reporting

Retain inherited main contract/MCP and dedicated parent-assignment evidence for
the configured bounded retention period. Missing required evidence is an upload
failure, not a successful summary. Report the tested commit/run, actual versions,
coverage and failure counts, debug-log state and cleanup outcome.

Product raw requests/responses, headers, SQL, credentials, context, wire frames,
resource ledgers and exception logs remain private. Upload only the closed
validated product summary, never a broad raw product-directory glob. Formatting
or exporting data cannot override a stage's original nonzero exit. Missing or
partial evidence cannot generate success-shaped fallback summaries.

Preserve failed local captures and ownership/retention records. Clear retention
only after independently verified restoration and live owned-resource absence;
otherwise refuse restart and teardown. Docker cleanup success does not turn
a failed product run into acceptance.

PR workflow concurrency may cancel superseded runs. Required aggregates
explicitly evaluate prerequisite results instead of treating implicit skips as
success. Pipeline lint is separate from Composer's local PHP QA and does not
prove live runtime or production approval.

## Failure handling

Fix static/baseline/manifest/security-policy failures first, then helper/unit
regressions. Distinguish actual Docker behavior from bootstrap failures and
mock safeguards. Package failures may concern metadata, production hashes,
runtime checks or Plugin Check; production failures additionally involve
approval, SVN or GitHub Release publication.

Reproduce scheduled failures before changing pins or merge policy. Supported
version failures can block release; transient infrastructure or unsupported
upstream combinations need separate triage, not silent skips.

## Official references

- [GitHub continuous integration](https://docs.github.com/en/actions/get-started/continuous-integration)
- [GitHub workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
- [GitHub protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
- [GitHub deployment environments](https://docs.github.com/en/actions/reference/deployments-and-environments)
