# Release Strategy

Release strategy explains how reviewed repository changes become a GitHub release and a WordPress.org plugin update. CI/CD strategy explains the automation mechanics; QA strategy explains the validation layers.

See the [Software Development Lifecycle](sdlc-overview.md) for where release fits in the complete development process.

## Release goals

1. Ship only reviewed, tested, package-ready plugin files.
2. Keep GitHub release artifacts, WordPress.org SVN state, plugin headers, `readme.txt`, and changelogs aligned.
3. Avoid publishing a release until release package QA, Plugin Check, and security-sensitive validation pass.
4. Make hotfixes fast without bypassing release safety checks.

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
7. Run or confirm `composer qa`.
8. Run or confirm `composer qa:release`.
9. Review the latest `6 - Compatibility QA` result for upstream drift.
10. Confirm no unexpected Plugin Check findings remain.
11. Confirm the `wordpress-org` GitHub Environment approval gate and SVN secrets are configured before pushing a release tag that should publish to WordPress.org.

## Tag release flow

The tag workflow owns release validation, WordPress.org SVN deployment, and GitHub release creation:

1. Maintainer pushes `vX.Y.Z`.
2. `.github/workflows/release.yml` requires an exact `vX.Y.Z` tag in fetched `main` history and runs Static QA plus Unit Tests.
3. Release validation checks source/archive metadata, requirements, the tested WordPress baseline, and nonempty version entries in the changelog and upgrade-notice sections.
4. `scripts/release-qa.sh` builds the release ZIP once, validates exact source/archive hashes, and mounts an extraction of that original ZIP as the production plugin root for contract/transport QA. Explicit harness-only binds do not expose the checkout or its development dependencies. WordPress Plugin Check receives a separate pristine extraction of the same ZIP; both the runtime production bytes and original archive digest must remain unchanged after QA. Only the exact empty host-side nested-bind placeholders are allowed in the runtime tree. The release path also checks the current Plugin Check version rather than relying only on an older pin.
5. The validated ZIP, notes, listing assets, source SHA, and integrity manifest are retained as a run-scoped artifact.
6. The validation job writes a **Ready for GitHub production approval** summary with the release/source/run/attempt/artifact identity. **Publish release (GitHub approval required)** then waits for DanielBoring's GitHub approval in the protected `wordpress-org` environment. This is not WordPress.org staff review.
7. The publish job explicitly installs and verifies host Subversion before its first preflight, verifies the original artifact and tag object/source, serializes production updates across all release tags, and rejects a version that would regress the published release.
8. SVN receives the contents extracted from the validated ZIP; GitHub receives that original ZIP and build provenance. WordPress.org publication is checked with bounded retries.

Do not manually upload an unvalidated zip to GitHub releases.

## WordPress.org release flow

WordPress.org uses SVN as the release repository. GitHub remains the development repository.

Release build and validation scripts require PHP with ZipArchive. The release workflow uses PHP 8.2 for tooling; this does not change the plugin's PHP 8.0 support floor. Run `composer test:release-safeguards` for package metadata, artifact integrity, recovery decisions, tag ancestry, and package-runtime orchestration regression tests without Docker. `composer qa:release` also requires Docker and runs the package through Plugin Check.

Plugin Check reports are parsed independently of the command exit status. Any ERROR, malformed report, or unknown finding type blocks release; existing warnings stay visible without being treated as errors. `REQUIRE_CURRENT_PLUGIN_CHECK=1` checks the same ZIP with both the pinned and current checker versions. The release workflow requires both; local `PLUGIN_CHECK_VERSION=latest` selects only the current checker.

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

WordPress.org SVN is production. There is no separate WordPress.org test SVN for this plugin. Use pull request QA, `5 - Release Package QA`, optional manual compatibility checks, staging WordPress installs, and the protected environment approval gate before publishing.

### GitHub environment and secrets

Use a protected GitHub Environment named `wordpress-org` for real SVN publishing.

Recommended settings:

1. Require DanielBoring's approval before jobs in the environment run. Self-review is allowed for solo maintenance; adding a second reviewer can support a future no-self-review policy.
2. Disallow administrator environment bypass and select only deployment tags matching `v*`.
3. Keep `SVN_USERNAME` and `SVN_PASSWORD` only in `wordpress-org`; they are already environment-scoped.
4. Restrict release-tag creation, updates, and deletion through the tag ruleset. Repository administrators have the explicit tag-rule bypass, not an environment-approval bypass.
5. Use the WordPress.org SVN-specific password, not the normal WordPress.org account password.

The environment's reviewer/self-review/admin-bypass settings, tag-only `v*` deployment policy, and release-tag creation/update/deletion restrictions were read back again on September 21, 2026 during recovery planning. Secret values were not read. Keep the environment ID `wordpress-org` unchanged: it holds the established secrets and protections, so that name may still appear under **Review deployments** even though the job is named **Publish release (GitHub approval required)**. Recheck effective settings before releases; documentation alone does not enforce them.

Main CI enforcement is **active**, verified on September 17, 2026: `main-ci-gates` requires `1 - Static QA`, `2 - Unit Tests`, and `Docker QA gate` from GitHub Actions. The [setup evidence](../.github/SETUP-COMPLETE.md#rollout-evidence) records the completed initial rollout, including all five approved bot PR workflows. Activation does not replace per-release package QA or protected production approval.

At that verification, PR #140's MCP Adapter 0.6.1 candidate had passed compatibility and PR package QA but remained open and unmerged. Main at `3dae8aa` used 0.5.0. The checked-in adapter baseline is now 0.6.1; baseline validation or adoption does not authorize a production release. Future Actions-created PR workflows still require maintainer approval before their genuine PR checks can satisfy the ruleset.

### Partial failure recovery

The workflow deploys to WordPress.org SVN before creating the GitHub Release. If SVN succeeds but GitHub Release creation or listing verification fails, the version may already be public. A verification timeout is not a rollback and must be reported as partial publication.

Re-run the failed publish job using its retained validated artifact where possible. Before accepting an existing SVN version, compare its contents with the approved package; the deploy action's "version already published" message alone is not sufficient. Before reusing a GitHub release, verify its tag and asset identity. An unexpected content mismatch must stop for investigation, not overwrite either destination.

Do not move/delete/recreate the tag or rebuild a replacement ZIP for recovery. If the trusted artifact has expired, recover it from a verified existing release or investigate with the maintainer; do not treat a new build as the artifact that was previously approved.

If GitHub Release creation somehow succeeds while WordPress.org deployment does not, treat the release as partially published: fix the SVN deployment issue, re-run the protected publish job when safe, or publish a follow-up patch release if the failed state could affect users.

### Recovery when the frozen workflow itself needs a fix

A rerun uses the original workflow revision. It cannot acquire a later prerequisite fix, such as installing host `svn` before publication preflight. Use the guarded `workflow_dispatch` path only after the CI fix has been reviewed and merged; it resumes an existing successful QA artifact without building or rerunning its QA.

| Entry | Workflow/control source | Package source | Production approval |
| --- | --- | --- | --- |
| Normal `vX.Y.Z` push | Original release tag in main history | That tag's newly validated, sealed artifact | GitHub `wordpress-org` environment |
| Recovery dispatch | Separately authorized annotated `v-release-recovery-*` tag on the reviewed merged fix | Exact source and immutable artifact of the original successful tag-push QA attempt | The same GitHub environment, reviewer and shared publish lock |
| Dispatch on `main` or an ordinary release tag | Rejected | None | No publication |
| Push of `v-release-recovery-*` | Excluded from normal release execution | None | No publication |

The recovery control tag is **not a plugin release** and never determines the plugin version, SVN destination tag, package contents or GitHub Release tag. It exists because the environment permits only deployment **tags** matching `v*`; checking out a tag in a job dispatched from `main` does not change that deployment ref. Do not broaden the environment policy or move the original release tag to work around this restriction.

Recovery runbook:

1. Preserve the failed run, successful Validate Release job, original artifact ID/name/expiry, release tag object, source SHA and original package digest. An overall failed run is expected after failed publication; failed or absent QA is never acceptable.
2. Review and merge the source-only CI fix through normal PR checks. With separate explicit authorization, the coordinator creates and pushes a new annotated `v-release-recovery-*` tag identifying that merged commit. Tag creation remains subject to the existing release-tag ruleset. Never modify the original `vX.Y.Z` tag or tag unreviewed code.
3. The coordinator dispatches `release.yml` **on that control tag**, passing only `original-run-id` and `original-artifact-id` as decimal IDs. Both the dispatch actor and any rerun triggering actor must retain repository write access. No caller-selected source, package path or replacement version is accepted.
4. The read-only resolver proves same-repository push/release-workflow identity, source/tag identity and current main ancestry. It derives the original attempt from `release-RUN-ATTEMPT`, checks that attempt explicitly, requires exactly one successful Validate Release job and its required successful QA/seal/upload steps, and checks artifact ownership, unique run membership, expiry and upload timing. It never substitutes the latest attempt or requires the failed publication run to have succeeded overall.
5. Review the resolver's ready-for-approval summary. DanielBoring approves the production deployment under **Review deployments**; `wordpress-org` denotes the destination/environment, not an external WordPress.org review queue. Approval of the original failed run does not remove this fresh recovery approval gate.
6. The one shared publish job rechecks the control tag after approval, checks out the exact original release source, downloads that original run's artifact by ID, and verifies its manifest against the **original** run ID, source and version. It rechecks the original remote tag object/peeled source and current main ancestry. Host Subversion is provisioned before preflight; the same stale-version, duplicate, attestation, exact SVN/public-package and original GitHub ZIP checks then apply.

Any missing, expired, malformed, ambiguous, unauthorized or mismatched evidence stops recovery. Do not rebuild a replacement ZIP, manually upload one, weaken environment approval or retag to make a check pass.

For the failed 2.6.0 publication, the approved recovery inputs are run `35604575508` and artifact `10641392617` (`release-35604575508-1`). Preserve source `1503b88750d1c0b9534ee5ecaf661b844d1f7ce3`, annotated `v2.6.0` object `5b7edb3fc7f4a83e66c432f2cb6c45186a8ef44a`, and package SHA-256 `577f4f2a5f1a6ca2c9ac32d3cb24d3600a8aecfedb2e11dcd812d768c73de188`. Current main contains unreleased 3.0 work and must not be packaged as 2.6.0. These recorded identities are an operator verification checklist, not authorization to create a control tag or dispatch automatically.

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
