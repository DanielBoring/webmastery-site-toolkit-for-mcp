# Release and platform history

Dated records and retired procedures, kept for audit. Current policy is in the
numbered documents; start at [00 - SDLC overview](../00-sdlc-overview.md).
Names below are the names in use at the time.

## October 2026: workflows renumbered 01–10

Workflow files, workflow names and job names were aligned with the numbered
documents. The retired workflows were removed after v3.0.0 published.

| Before | After |
| --- | --- |
| `coding-standards.yml` (`1 - Static QA`) | `01-static-qa.yml` (`01 - Static QA`) |
| `workflow-lint.yml` | job `01 - Static QA / workflow lint` |
| `unit-tests.yml` (`2 - Unit Tests`) | `02-unit-tests.yml` (`02 - Unit Tests`) |
| `e2e-qa.yml` (`Docker QA gate`, `3 - Ability Contract QA`, `4 - Full MCP E2E QA`) | `03-04-docker-qa.yml` (`03-04 - Docker QA`, `03 - …`, `04 - …`) |
| `release-package-qa.yml` (`5 - Release Package QA`) | `05-release-package-qa.yml` |
| `minimum-runtime-qa.yml` | `06-minimum-runtime-qa.yml` |
| `compatibility-qa.yml` | `07-compatibility-qa.yml` |
| `release.yml` (`Validate Release`, `Publish release (GitHub approval required)`) | `08-release.yml` (`08 - Release / validate`, `08 - Release / publish (GitHub approval required)`) |
| `publishing-qa.yml` | `09-publishing-checks.yml` |
| `adapter-release-assessment.yml` | `10-adapter-assessment.yml` |
| `fresh-release-validation.yml`, `import-issue-backlog.yml` | removed |

`main-ci-gates` had its required checks removed on October 16, 2026 so the
renamed checks could report, and ran empty until they were restored with the
new names. That gap is why rulesets became code with a weekly drift check
(`11 - Ruleset Audit`, issue #192). The same change renamed
`main-pr-no-required-approvals` to `main-pull-requests`.

Recovery tooling resolves releases validated under `08-release.yml`; 2.6.0 and
3.0.0 were already published under the old names.

The documentation moved at the same time:

| Before | After |
| --- | --- |
| `docs/sdlc-overview.md` | `docs/00-sdlc-overview.md` |
| `docs/qa-strategy.md` | split across `00` (checks, scopes, budgets, execution, environment) and `01`/`02` (PHPStan ratchet, security QA policy, suites) |
| `docs/qa-harness-reference.md` | `03-04` (harness, product acceptance, local commands), `05` (original-package QA), `06`, `07`, `09`; checklist to [review-checklist.md](review-checklist.md) |
| `docs/ci-cd-strategy.md` | `91-ci-cd-platform.md`, with scheduling to `07` and environment gates to `08` |
| `docs/release-strategy.md` | `08-release.md`; history to this file |
| `docs/security-strategy.md` | `90-security-strategy.md` |
| `.github/AUTOMATION.md` | the matching numbered documents |
| `.github/SETUP-COMPLETE.md` | `91-ci-cd-platform.md`; rollout evidence below |
| `docs/shared-helpers.md`, `docs/3.0-migration.md`, `docs/mcp-adapter-0.7-assessment.md` | `docs/reference/` |
| `ISSUES/`, `BACKLOG.md` | removed; see [Backlog](#backlog-sources) |

## v3.0.0: first release attempt and budget

The first `v3.0.0` attempt (run `38273601311`) exhausted its former 65-minute
budget after static/unit QA and safeguards passed, while package QA was still
running. It produced no sealed bundle and reached no production approval.
That immutable tag retains its original workflow: merging a later workflow fix
does not change a rerun. The release job was later reworked to rely on main's
QA results instead of re-running them serially. Existing-artifact recovery requires a successful
original validation and sealed artifact; neither local/PR packages nor partial
tag-run results qualify. A new validation/publication path requires separate
maintainer authorization. No release retry, new tag or publication is implied
by this budget fix.

Measurements from that period (planning evidence, not controlled benchmarks):

Use `scripts/qa-profile.py` to inspect completed hosted job steps and local
per-test/stage diagnostics; separate setup, execution and cleanup, and retain
failed/partial runs rather than estimating their unfinished duration. The failed
65-minute tag run recorded roughly 1,799 seconds static/unit, 474 safeguards,
and 1,630 **partial** package/checker seconds. Later independent PR package jobs
took about 36--39 minutes; PHP 8.0 full units took about 29--30 minutes and PHP
8.4 about 15--16 minutes. These different-source/lane observations are planning
evidence, not additive controlled release benchmarks. The 120-minute budget
adds headroom but does not guarantee completion. Review complete repeat
release-stage timings before tagging; investigate consumption above 80% of
the outer budget rather than merely raising it or assuming a partial pass.

The measured replay hotspot spent about 96% of selected elapsed time exporting
independent evidence; authoritative journal read/persist guards dominated.
Do not remove fault cases, share mutable evidence or cache authoritative state
to obtain a faster green result. Split independent slow tooling and production
units only after a reviewed dependency/provenance design and repeat comparisons
show a worthwhile reduction while every failure still blocks the release.
For now retain the existing comprehensive gates, diagnostic inventory and
required-check names; no broad framework rewrite is necessary.

The release job was then reworked to promote main's evidence (01–06) instead
of re-running it; see [08 - Release](../08-release.md).

## v3.0.0: fresh validation (retired)

Fresh validation of immutable `v3.0.0` is a separate input-free manual workflow,
not a new version or rerun of the frozen tag workflow. Its reviewed 120-minute
control job first admits the annotated control tag/current-main ancestry and
locked original source, then runs that source's complete acceptance and creates
a newly identified immutable artifact. Existing successful-artifact recovery
stays unchanged. Neither workflow is dispatched as part of PR checks.
Both publication paths retain the same protected `wordpress-org` environment,
shared non-cancelling publish lock, postapproval provenance/version checks and
same-ZIP destinations. Main required-check names and full discovery are unchanged.

Security framing at the time: existing-artifact recovery still had to reject
failed, cancelled, incomplete or missing original QA evidence. Input-free fresh
validation was separately reviewed and locked an immutable release source and
tag before execution; it sealed a **new** successful run and attempt and a
reviewed control identity, and never claimed the failed run validated the ZIP.
Both paths independently rechecked actor authorization, tag and `main`
ancestry, artifact integrity and version progression after the same protected
`wordpress-org` approval. Neither local diagnostics nor a control-tag push
authorized publication.

`fresh-release-validation.yml` is a distinct, input-free recovery entry point
for **v3.0.0 only**. It does not change the original tag, rerun its frozen
workflow, reuse partial evidence, or pretend a new ZIP is the failed run's ZIP.
Its reviewed policy locks the annotated tag object
`4bcc5bae61b7867bcb6f186713beeaeb5ed9b722` and original source
`2f9842bb943fb27030cabe8060ad0b10c007ac14`. Future versions require a separately
reviewed policy/workflow change with exact immutable identities and regressions;
there is no caller-controlled version, source, path, artifact or workflow.

After normal PR review, exact-head gates and a separately authorized merge,
authorize an annotated `v-release-recovery-*` control tag on that merged control
commit, then explicitly dispatch this workflow on that tag. Do not dispatch on
`main` or `v3.0.0`. The existing tag-only environment policy remains intact;
control-tag pushes are excluded from the normal release workflow. Creating or
dispatching a control tag is not implied by reviewing this implementation.

The trusted control checks both actor permissions, the remote control tag,
original annotated release tag and current-main ancestry **before** original
source execution. A separate checkout of the locked source installs its own
locked dependencies and runs its complete static/unit and release safeguard
commands. Its package builder runs once; original-ZIP runtime, cleanup proof,
safe evidence export and pinned/current Plugin Check remain mandatory. The
**control workflow's 120-minute job budget**, not the old tag's 65-minute YAML,
governs this execution. No test or source byte is upgraded from current main.

The original source's bundle helper seals package/notes/assets/source hashes.
The trusted control adds an exact `fresh_validation` manifest identity:
original release source/tag, reviewed workflow/control source/tag, repository,
workflow and **new** run/attempt. The immutable upload is named
`fresh-release-RUN-ATTEMPT`. After the user's `wordpress-org` approval, the
publish job independently rechecks authorization, both remote tags, ancestry,
the successful fresh-validation job and every mandatory step, upload timestamps,
artifact membership/expiry/digest and the sealed manifest. It downloads only
that artifact and uses the original source's existing package verifier,
version-progression, SVN/public-listing and GitHub checks under the same shared
publish lock. It never builds after approval. GitHub attestation identifies the
actual control workflow; the manifest identifies the original package source.

Existing `08-release.yml` artifact recovery still requires a successful original
tag-push validation and retained sealed artifact: failed/cancelled/no-artifact
runs remain rejected. Fresh validation does not weaken those semantics.
Interrupted fresh publication may be partial: retain the new artifact and
inspect destination hashes. Rerunning only publish retains the successful QA
attempt via the validation job output; expired/deleted artifacts or changed
tags/permissions block it. Do not substitute a local ZIP or start another build
without a separately reviewed recovery decision.

## 2.6.0 recovery identities

For the failed 2.6.0 publication, the approved recovery inputs are run `35604575508` and artifact `10641392617` (`release-35604575508-1`). Preserve source `1503b88750d1c0b9534ee5ecaf661b844d1f7ce3`, annotated `v2.6.0` object `5b7edb3fc7f4a83e66c432f2cb6c45186a8ef44a`, and package SHA-256 `577f4f2a5f1a6ca2c9ac32d3cb24d3600a8aecfedb2e11dcd812d768c73de188`. Current main contains unreleased 3.0 work and must not be packaged as 2.6.0. These recorded identities are an operator verification checklist, not authorization to create a control tag or dispatch automatically.

## September 17, 2026: main CI enforcement rollout

The [`main-ci-gates` ruleset (23522901)](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/rules/23522901)
was activated. Under explicit maintainer authorization, enforcement was activated
only after the bot PR evidence below passed. Only enforcement changed from `disabled` to `active`;
the rule configuration was preserved:

- Include only `refs/heads/main`, with no excluded refs and no bypass actors.
- Require exactly `1 - Static QA`, `2 - Unit Tests`, and `Docker QA gate`,
  each bound to the GitHub Actions integration (15368).
- Keep `strict_required_status_checks_policy=false` (no up-to-date-branch
  requirement from this rule) and `do_not_enforce_on_create=false`.

The ruleset and the effective rules for `main` were read back from GitHub.
`gh pr checks 140 --required` also confirmed all three required checks passed.
The September 16 disabled state was staging.

The September 17 preflight also read back Actions workflow permissions:
`default_workflow_permissions=read` and `can_approve_pull_request_reviews=true`.
Actions tokens default to read-only; individual jobs request necessary writes.
Actions-created pull requests are enabled, but their PR workflows still need
maintainer approval. No permission setting changed during activation.

### Rollout evidence

The compatibility CLI parser repair in
[PR #139](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/pull/139)
merged as `54926de`; the CPT taxonomy update fix in
[PR #138](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/pull/138)
merged as `3dae8aa`. Both are already in the verified `main` commit,
`3dae8aa35ce36dcbdfbd0271ffd72fc50985ceef`.

| Evidence | Event / source | Verified result |
| --- | --- | --- |
| [Compatibility run 35234831249](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35234831249) | `workflow_dispatch` on `main` at `3dae8aa` | Discovery, all eight runtime lanes, current Plugin Check, and the actual proposed-commit full E2E/package QA passed; opened bot PR #140. |
| [Static QA run 35235423507](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423507) | `pull_request`, PR #140 | Passed, including `1 - Static QA`. |
| [Unit Tests run 35235423478](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423478) | `pull_request`, PR #140 | Passed, including `2 - Unit Tests`. |
| [Docker QA run 35235423350](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423350) | `pull_request`, PR #140 | Contract, MCP E2E, and `Docker QA gate` passed. The conditional skip-summary job correctly skipped because runtime jobs ran. |
| [Workflow lint run 35235423374](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423374) | `pull_request`, PR #140 | Passed. |
| [Release Package QA run 35235423398](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423398) | `pull_request`, PR #140 | Passed without publishing. |

All five genuine PR-event runs used bot head
`4de69ccfcc57e22d4ee1433ab507c879b06aa90e`. They initially required approval
(`action_required`), received maintainer-authorized workflow approvals through
the REST API, then passed. The dispatched compatibility run was candidate
evidence, not a substitute for those PR checks.

At this September 17 verification,
[PR #140](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/pull/140)
was **open and unmerged**, authored by `github-actions`. Its original one-file diff changed
only MCP Adapter 0.5.0 to 0.6.1 and the matching verified SHA-256 in
[`compatibility-versions.json`](../../.github/compatibility-versions.json). Main at `3dae8aa` used
MCP Adapter **0.5.0**. WordPress, `readme.txt` `Tested up to`, other dependency
pins, and PHP support are unchanged.

### Compatibility baseline at the time

The checked-in configuration pins WordPress 7.1 and MCP Adapter 0.6.1 with its
verified download digest. The September 17 rollout record above describes the
earlier 0.5.0 baseline, not the current configuration. This adapter update leaves
WordPress, `Tested up to`, other dependency pins, and PHP support unchanged.
Baseline promotion still requires current-source compatibility and genuine PR

At that verification, PR #140's MCP Adapter 0.6.1 candidate had passed
compatibility and PR package QA but remained open and unmerged; `main` at
`3dae8aa` used 0.5.0. The adapter baseline later moved to 0.6.1 and then
0.7.0. Baseline validation or adoption does not authorize a production
release.

## Backlog sources

- `ISSUES/01`–`ISSUES/24` were import sources for GitHub issues #105–#128,
  all closed. The dry-run-first importer workflow that converted them was
  removed with the files.
- `BACKLOG.md` listed one open feature, the read-only
  `webmaster-verification-status` ability, which has shipped, and three
  completed items. GitHub Issues is the only backlog now.
