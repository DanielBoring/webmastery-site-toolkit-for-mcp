# 08 - Release

Workflow: [`.github/workflows/08-release.yml`](../.github/workflows/08-release.yml)
· Gates: **production publication** · Overview: [00 - SDLC overview](00-sdlc-overview.md)

How reviewed changes become a GitHub Release and a WordPress.org plugin
update: versioning, readiness, the release workflow, publication, recovery,
hotfixes and rollback.

## What it proves

The tagged commit is on `main`, already passed checks 01–06 there, matches
its version metadata, and builds to a ZIP that passes Plugin Check with both
the pinned and current checker. Then, after a human approves, that exact ZIP
is what reaches WordPress.org SVN and the GitHub Release.

### Release goals

1. Ship only reviewed, tested, package-ready plugin files.
2. Keep GitHub release artifacts, WordPress.org SVN state, plugin headers, `readme.txt`, and changelogs aligned.
3. Avoid publishing a release until release package QA, Plugin Check, and security-sensitive validation pass.
4. Make hotfixes fast without bypassing release safety checks.

## When it runs

- **Push of a tag matching `v*`** (but not `v-release-recovery-*`): the normal
  release path.
- **Manual dispatch on a `v-release-recovery-*` control tag** with
  `original-run-id` and `original-artifact-id`: the guarded recovery path
  (see [Recovery](#recovery-when-the-frozen-workflow-itself-needs-a-fix)).
  Dispatch on a branch or ordinary release tag is rejected.

## What the workflow does

| Job | Timeout | Runs on | Does |
| --- | --- | --- | --- |
| `08 - Release / validate` | 30 min | Tag push | The validation steps below; `contents`, `actions` and `checks: read`. |
| `08 - Release / resolve recovery (read-only)` | 10 min | Recovery dispatch | Proves the control tag is annotated and in `main`, resolves the original successful validate job and its sealed artifact (`scripts/release-recovery.php resolve`), and writes the ready-for-approval summary. |
| `08 - Release / publish (GitHub approval required)` | 30 min | After either job above | Waits for approval in the `wordpress-org` environment, then the publish steps below. |

`08 - Release / validate`:

1. **Tag and ancestry.** `scripts/release-tag-check.sh` requires an exact
   `vX.Y.Z` tag whose commit is in freshly fetched `main`.
2. **Main evidence.** `scripts/release-checks-gate.php` requires that the
   tagged commit already passed `01 - Static QA`, `02 - Unit Tests`,
   `03-04 - Docker QA`, `05 - Release Package QA / package` and
   `06 - Minimum Runtime QA / WordPress 6.9 PHP 8.0`. Each result must come
   from its expected workflow file running on `main` from a push, the nightly
   schedule or a manual dispatch; PR runs of the same commit do not count.
   05 and 06 must have actually run, not been skipped. A missing or failed
   check stops the release and names the workflow to dispatch.
3. **Metadata.** The tag must equal `v` + the plugin version.
4. **Build and Plugin Check.** `scripts/release-qa.sh --publishing` with
   `REQUIRE_CURRENT_PLUGIN_CHECK=1` builds the ZIP once, validates source and
   archive metadata, requirements, the tested WordPress baseline and nonempty
   changelog and upgrade-notice entries, validates exact hashes, and runs
   Plugin Check on a pristine extraction with the pinned and current versions.
   The archive digest must not change. Product behavior of the ZIP was already
   proven by 05 on the same commit.
5. **Seal.** `release-tools.php bundle` seals the ZIP, scoped notes, listing
   assets, source SHA and integrity manifest; it is uploaded as
   `release-<run>-<attempt>` (30 days, uncompressed).
6. **Ready for approval.** The job summary names the release, source, run,
   attempt and artifact for the approver.

`08 - Release / publish`:

1. For recovery: recheck the control tag after approval, rerun the resolver
   (`verify-approved`) and require the identity to match what was approved.
2. Check out the validated source, install and verify host Subversion.
3. Download only the original validate job's artifact and verify every byte,
   the tag object and `main` ancestry again.
4. `scripts/release-publish-check.sh preflight` rejects a version that would
   regress the published one and decides whether to deploy, resume or stop.
5. Attest build provenance for the exact ZIP.
6. Deploy the extracted package to SVN with
   `10up/action-wordpress-plugin-deploy` (pinned), only if that SVN tag is not
   already published.
7. Verify SVN and, with bounded retries, the public listing.
8. Create the GitHub Release with the original ZIP if it does not exist yet.
9. On failure after preflight, report possible partial publication.

The publish job is serialized across all tags by one non-cancelling
concurrency group, `wordpress-org-production-publish`.

## Run locally

```bash
# Is main ready to tag? (read-only; uses the GitHub API)
php scripts/release-checks-gate.php DanielBoring/webmastery-site-toolkit-for-mcp "$(git rev-parse origin/main)"
composer qa:release              # package QA + Plugin Check, needs Docker
composer test:release-safeguards # release tooling regressions, no Docker
```

Run `composer test:ci-safeguards` and `composer lint:workflows` when changing
this workflow.

## Reading failures

- **Tag check:** the tag is not `vX.Y.Z` or not in `main`. Do not move it; fix
  forward with a new version.
- **Main evidence:** the message names the missing workflow. Dispatch it on
  `main`, wait for success, then rerun the failed job.
- **Plugin Check:** any ERROR, malformed report or unknown finding type
  blocks; warnings stay visible. See [09](09-publishing-checks.md).
- **Publish:** see [Partial failure recovery](#partial-failure-recovery).
  Production failures can involve approval, SVN or GitHub Release publication.

## Versioning policy

This project uses semantic versioning:

| Version part | Use for |
| --- | --- |
| Major | Breaking ability names, inputs, outputs, authentication/permission model, or MCP client compatibility. |
| Minor | Backward-compatible abilities, features, compatibility support, or user-visible enhancements. |
| Patch | Bug fixes, security fixes, packaging fixes, documentation corrections, and compatibility patches. |

Security fixes can be patch releases when the public API remains compatible.

GitHub release tags use `vX.Y.Z`. WordPress.org SVN release tags use `X.Y.Z` because WordPress.org recommends numeric Subversion tag names containing only numbers and periods. Every user-facing code release, including patch releases, should be eligible for WordPress.org SVN deployment after release QA passes.

Readme-only or Plugin Directory asset-only updates do not require a plugin version bump when they are cosmetic and do not ship code changes. Avoid using SVN commits as routine documentation churn; WordPress.org treats SVN as a release repository.

## Release cadence

Releases are readiness-based, not calendar-based:

| Release need | Policy |
| --- | --- |
| Security fix or serious user-impacting bug | Publish a patch release as soon as the fix passes release QA. |
| Normal bug fixes or backward-compatible features | Batch into intentional patch or minor releases when there is user-facing value. |
| Compatibility/readme-only update | Publish only when needed; do not force a code release for cosmetic readme or listing asset changes. |
| Internal repository, CI, or contributor workflow changes | Do not publish a plugin release unless packaged plugin behavior or user-facing documentation changes. |

## Release readiness checklist

Before tagging a release:

1. Choose the version bump.
2. Update the plugin header `Version`.
3. Update `readme.txt` `Stable tag`.
4. Move plugin-facing notes from `CHANGELOG.md` `## Unreleased` into the release section.
5. Add matching `readme.txt` changelog and upgrade notice entries.
6. Keep repository-only notes in `.github/REPOSITORY_CHANGELOG.md`.
7. Merge the release changes to `main` and let its push checks finish.
8. Make sure `05 - Release Package QA` and `06 - Minimum Runtime QA` have succeeded on
   that exact `main` commit. The nightly run covers this when nothing merged
   since; otherwise dispatch both on `main`:
   `gh workflow run 05-release-package-qa.yml --ref main` and
   `gh workflow run 06-minimum-runtime-qa.yml --ref main`.
   To check readiness before tagging, run
   `php scripts/release-checks-gate.php DanielBoring/webmastery-site-toolkit-for-mcp "$(git rev-parse origin/main)"`.
9. Review the latest `07 - Compatibility QA` result for upstream drift.
10. Confirm no unexpected Plugin Check findings remain.
11. Confirm the latest `11 - Ruleset Audit` passed (it checks the `wordpress-org` approval gate), and that the environment still lists the `SVN_USERNAME` and `SVN_PASSWORD` secrets (the audit cannot see secrets).
12. As an admin, run `python3 scripts/ruleset-drift.py` to also check ruleset bypass actors, which CI cannot see.

## Release budget

The validate job has a 30-minute budget and is expected to take about 10
minutes. Prefer a local Docker preflight of the exact final installable
package **before tagging**, then authoritative hosted validation and sealing
of the exact tag. A local pass finds failures cheaply but does not establish
GitHub run or artifact provenance or authorize production. Record actual PHP,
engine and checker versions and source and ZIP hashes; changes to final bytes
invalidate earlier acceptance. Review complete release-stage timings, and
investigate consumption above 80% of the budget rather than raising it. Do not
remove fault cases, share mutable evidence or cache authoritative state to get
a faster green result. History: [release history](reference/release-history.md).

Do not manually upload an unvalidated ZIP to GitHub Releases.

## WordPress.org release flow

WordPress.org uses SVN as the release repository. GitHub remains the development repository.

Release build and validation scripts require PHP with ZipArchive. The release workflow uses PHP 8.2 for tooling; this does not change the plugin's PHP 8.0 support floor. Run `composer test:release-safeguards` for package metadata, artifact integrity, recovery decisions, tag ancestry, and package-runtime orchestration regression tests without Docker. `composer qa:release` also requires Docker and runs the package through Plugin Check.

Plugin Check reports are parsed independently of the command exit status. Any ERROR, malformed report, or unknown finding type blocks release; existing warnings stay visible without being treated as errors. `REQUIRE_CURRENT_PLUGIN_CHECK=1` checks the same ZIP with both the pinned and current checker versions. The release workflow requires both; local `PLUGIN_CHECK_VERSION=latest` selects only the current checker.

Package reservations and teardown are described in
[05 - Release Package QA](05-release-package-qa.md#package-reservations).

Automated process:

1. The release workflow validates a curated package ZIP and transfers it to the publish job without rebuilding.
2. The protected `wordpress-org` environment gates access to the real SVN publish step.
3. The publish job verifies and extracts the artifact. `10up/action-wordpress-plugin-deploy` deploys the resulting `BUILD_DIR` to SVN `trunk`, with supported listing assets handled separately.
4. The deploy action copies `trunk` to the matching SVN `tags/X.Y.Z` path.
5. The workflow creates the GitHub Release after SVN deployment succeeds.
6. Bounded verification checks whether WordPress.org serves the expected version. Maintainers review the plugin page and retained evidence, especially after a partial failure.

Production concurrency uses one shared group across release tags, not one group per tag. An active publish must never be cancelled by a newer release. Queue order does not establish semantic-version order, so publishing must recheck version progression after acquiring the production slot.

Build provenance describes the actual GitHub ZIP and executing workflow; it is not proof of correctness or an attestation for a separately generated WordPress.org download. During recovery the attestation identifies the recovery control workflow, not the original QA run. The original run/artifact identity, sealed manifest and exact release-source verification remain the evidence for the package's original validation. Package validation, review, and post-deploy verification remain required.

Avoid frequent small SVN commits. WordPress.org guidance treats SVN as a release repository, not the day-to-day development repository.

WordPress.org SVN is production. There is no separate WordPress.org test SVN for this plugin. Use pull request QA, `05 - Release Package QA`, optional manual compatibility checks, staging WordPress installs, and the protected environment approval gate before publishing.

### WordPress.org deployment

- The SVN deploy step uses `10up/action-wordpress-plugin-deploy` pinned to an
  immutable commit.
- `SVN_USERNAME` and `SVN_PASSWORD` are stored only in the protected
  `wordpress-org` environment. Do not duplicate them as repository secrets.
- The workflow deploys the curated package and supported listing assets from
  `.wordpress-org/`. Unrecognized files such as `logo.png` are not Plugin
  Directory assets and must not enter the deploy artifact.

### GitHub environment and secrets

Use a protected GitHub Environment named `wordpress-org` for real SVN publishing.

Recommended settings:

1. Require DanielBoring's approval before jobs in the environment run. Self-review is allowed for solo maintenance; adding a second reviewer can support a future no-self-review policy.
2. Disallow administrator environment bypass and select only deployment tags matching `v*`.
3. Keep `SVN_USERNAME` and `SVN_PASSWORD` only in `wordpress-org`; they are already environment-scoped.
4. Restrict release-tag creation, updates, and deletion through the tag ruleset. Repository administrators have the explicit tag-rule bypass, not an environment-approval bypass.
5. Use the WordPress.org SVN-specific password, not the normal WordPress.org account password.

The environment's reviewer/self-review/admin-bypass settings, tag-only `v*` deployment policy, and release-tag creation/update/deletion restrictions were last read back by hand on October 19, 2026, and are now checked weekly by [11 - Ruleset Audit](11-ruleset-audit.md) against `.github/environments/wordpress-org.json`. Secret values were not read. Keep the environment ID `wordpress-org` unchanged: it holds the established secrets and protections, so that name may still appear under **Review deployments** even though the job is named **08 - Release / publish (GitHub approval required)**. Recheck effective settings before releases; documentation alone does not enforce them.

### Environment and publication gates

The `wordpress-org` environment protects **08 - Release / publish (GitHub
approval required)**. Normal validation or the recovery resolver identifies the
exact source, tag, run, attempt and artifact before approval. Approval is
repository production approval, not WordPress.org staff review.

The October 19, 2026 read-back (Settings → Environments → `wordpress-org`)
recorded DanielBoring as the required reviewer, **Prevent self-review** off
for solo maintenance, **Allow administrators to bypass** off, no wait timer,
and deployments limited to the tag rule `v*` (which also covers the
`v-release-recovery-*` control tags). `SVN_USERNAME` and `SVN_PASSWORD` are
the only environment secrets; there are no environment variables. Only `v*`
tags may deploy. Administrator control over tag creation does not
bypass production approval.

One shared publish concurrency group serializes normal and recovery
publication without cancelling an active deployment. There is no separate
WordPress.org test SVN; use PR checks, package QA and staging installations
first.

## Partial failure recovery

The workflow deploys to WordPress.org SVN before creating the GitHub Release. If SVN succeeds but GitHub Release creation or listing verification fails, the version may already be public. A verification timeout is not a rollback and must be reported as partial publication.

Re-run the failed publish job using its retained validated artifact where possible. Before accepting an existing SVN version, compare its contents with the approved package; the deploy action's "version already published" message alone is not sufficient. Before reusing a GitHub release, verify its tag and asset identity. An unexpected content mismatch must stop for investigation, not overwrite either destination.

Do not move/delete/recreate the tag or rebuild a replacement ZIP for recovery. If the trusted artifact has expired, recover it from a verified existing release or investigate with the maintainer; do not treat a new build as the artifact that was previously approved.

If GitHub Release creation somehow succeeds while WordPress.org deployment does not, treat the release as partially published: fix the SVN deployment issue, re-run the protected publish job when safe, or publish a follow-up patch release if the failed state could affect users.

## Recovery when the frozen workflow itself needs a fix

A rerun uses the original workflow revision. It cannot acquire a later prerequisite fix, such as installing host `svn` before publication preflight. Use the guarded `workflow_dispatch` path only after the CI fix has been reviewed and merged; it resumes an existing successful QA artifact without building or rerunning its QA.

| Entry | Workflow/control source | Package source | Production approval |
| --- | --- | --- | --- |
| Normal `vX.Y.Z` push | Original release tag in main history | That tag's newly validated, sealed artifact | GitHub `wordpress-org` environment |
| Recovery dispatch | Separately authorized annotated `v-release-recovery-*` tag on the reviewed merged fix | Exact source and immutable artifact of the original successful tag-push QA attempt | The same GitHub environment, reviewer and shared publish lock |
| Dispatch on `main` or an ordinary release tag | Rejected | None | No publication |
| Push of `v-release-recovery-*` | Excluded from normal release execution | None | No publication |

The recovery control tag is **not a plugin release** and never determines the plugin version, SVN destination tag, package contents or GitHub Release tag. It exists because the environment permits only deployment **tags** matching `v*`; checking out a tag in a job dispatched from `main` does not change that deployment ref. Do not broaden the environment policy or move the original release tag to work around this restriction.

Recovery runbook:

1. Preserve the failed run, successful `08 - Release / validate` job, original artifact ID/name/expiry, release tag object, source SHA and original package digest. An overall failed run is expected after failed publication; failed or absent QA is never acceptable.
2. Review and merge the source-only CI fix through normal PR checks. With separate explicit authorization, the coordinator creates and pushes a new annotated `v-release-recovery-*` tag identifying that merged commit. Tag creation remains subject to the existing release-tag ruleset. Never modify the original `vX.Y.Z` tag or tag unreviewed code.
3. The coordinator dispatches `08-release.yml` **on that control tag**, passing only `original-run-id` and `original-artifact-id` as decimal IDs. Both the dispatch actor and any rerun triggering actor must retain repository write access. No caller-selected source, package path or replacement version is accepted.
4. The read-only resolver proves same-repository push/release-workflow identity, source/tag identity and current main ancestry. It derives the original attempt from `release-RUN-ATTEMPT`, checks that attempt explicitly, requires exactly one successful `08 - Release / validate` job and its required successful QA/seal/upload steps, and checks artifact ownership, unique run membership, expiry and upload timing. It never substitutes the latest attempt or requires the failed publication run to have succeeded overall.
5. Review the resolver's ready-for-approval summary, including the control-tag object and original artifact archive digest. DanielBoring approves the production deployment under **Review deployments**; `wordpress-org` denotes the destination/environment, not an external WordPress.org review queue. Approval of the original failed run does not remove this fresh recovery approval gate.
6. Before switching away from the trusted control checkout, the one shared publish job compares the control-tag object with the preapproval object (even a recreated annotation on the same commit is rejected), and reruns the read-only resolver after approval. Original run/job/artifact provenance, expiry and actor authorization must still pass; source, release-tag object, version, run, attempt, artifact ID and archive digest must all match the approved identity. Only then does it check out the exact original release source, download that original run's artifact by ID, and verify its manifest against the **original** run ID, source and version. It rechecks the original remote tag object/peeled source and current main ancestry. Host Subversion is provisioned before preflight; the same stale-version, duplicate, attestation, exact SVN/public-package and original GitHub ZIP checks then apply. No download or SVN-secret use precedes these postapproval identity gates.

Any missing, expired, malformed, ambiguous, unauthorized or mismatched evidence stops recovery. Do not rebuild a replacement ZIP, manually upload one, weaken environment approval or retag to make a check pass.

Recorded identities from past recoveries are in the
[release history](reference/release-history.md).

## WordPress.org guideline governance

Before tagging or uploading a release, review the official Detailed Plugin Guidelines for the release diff and final package. For this repository, the highest-risk guideline areas are:

| Guideline area | Repository implication |
| --- | --- |
| GPL compatibility and responsibility for contents | Confirm all bundled code, screenshots, images, fonts, and libraries are GPL-compatible and intentionally included in the release zip. |
| Stable WordPress.org distribution | Keep the WordPress.org package current with released GitHub code; do not distribute a newer stable plugin only through alternate channels. |
| Human-readable code and build tooling | Do not ship obfuscated code. If build tooling becomes necessary, keep source/build instructions public and maintained. |
| External services and tracking consent | Document any external service dependency in `readme.txt` and require explicit consent before contacting external servers for tracking or non-essential functionality. |
| Executable third-party code | Do not load executable plugin/theme/update code from non-WordPress.org systems; bundle non-service JavaScript and CSS locally. |
| Readme and public-page hygiene | Keep `readme.txt` useful for people, avoid keyword stuffing, keep tags to five or fewer, and disclose any affiliate/service links. |
| WordPress default libraries | Use WordPress-bundled libraries instead of shipping duplicate copies of libraries WordPress already provides. |
| SVN release discipline | Treat SVN as the release repository, use descriptive commit messages, avoid rapid minor readme/package churn, and tag each release once ready. |
| Version increments | Increment the plugin version for every user-facing release and keep the plugin header, `Stable tag`, changelog, Git tag, and SVN tag aligned. |
| Trademarks and project names | Avoid names/slugs that imply ownership of WordPress, MCP Adapter, SEO plugins, or other third-party projects. |

## Hotfix policy

Use a hotfix release when a shipped version has a user-impacting bug, security issue, packaging error, or compatibility break.

Hotfix steps:

1. Branch from current `main`.
2. Make the smallest safe fix.
3. Add a regression test, manifest case, or security QA policy check when feasible.
4. Run the normal release readiness checklist.
5. Publish a patch version unless compatibility requires a larger version bump.
6. Document the issue clearly in `CHANGELOG.md` and `readme.txt`.
7. Use a GitHub security advisory when the fix addresses an exploitable vulnerability.

## Rollback policy

Prefer forward fixes over deleting or rewriting release history.

If a release is bad:

1. Stop promoting the release.
2. Open a hotfix issue/PR immediately.
3. If WordPress.org users are exposed to a severe issue, coordinate with WordPress.org plugin support/review channels.
4. Publish a corrected patch release.
5. Document what happened in maintainer-facing notes and, if user-facing, in release notes.

## Official references

- GitHub Docs: [About releases](https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases)
- GitHub Docs: [Repository security advisories](https://docs.github.com/en/code-security/concepts/vulnerability-reporting-and-management/repository-security-advisories)
- WordPress Plugin Handbook: [Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- WordPress Plugin Handbook: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- WordPress.org: [Plugin Check](https://wordpress.org/plugins/plugin-check/)
- WordPress Plugin Handbook: [Plugin readmes](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
