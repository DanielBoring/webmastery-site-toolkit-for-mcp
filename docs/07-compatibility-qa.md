# 07 - Compatibility QA

Workflow: [`.github/workflows/07-compatibility-qa.yml`](../.github/workflows/07-compatibility-qa.yml)
· Required: no (gates baseline promotion) · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The current `main` still works against new upstream releases. It discovers
the latest stable WordPress and MCP Adapter, runs the full contract and MCP
transport suites across eight version lanes, runs the current Plugin Check,
and, when newer versions pass everywhere, opens a reviewed PR to promote them
in `.github/compatibility-versions.json`. Fresh images and exact resolved
versions make upstream drift visible.

## When it runs

- **Weekly**, Mondays at 09:17 UTC, and **manual dispatch** on `main`.
  Running on any other branch fails on purpose.
- Manual runs open a baseline PR only when `open_update_pr` is selected.
- Runs are serialized (`compatibility-baseline-promotion`) and never cancelled.
- Not part of PR checks. Dispatch it for release candidates, dependency-sensitive
  changes, or WordPress and PHP support changes.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `07 - Compatibility QA / discover versions` | 10 min | Requires the run to be on current `main`, then `scripts/discover-compatibility.sh` queries the WordPress version API and the latest stable MCP Adapter release, verifies digests, rejects version regressions, builds the lane matrix and uploads `compatibility-proposal` (30 days). Also runs the download and dependency-policy rejection tests. |
| `07 - Compatibility QA / <lane>` | 45 min each | For each lane below: applies the proposed tool and plugin pins, pulls images (three tries, then fails as infrastructure), runs `scripts/qa-run.sh all` (contract, MCP transport, debug log), records versions, uploads `compatibility-lane-<lane>`. |
| `07 - Compatibility QA / current Plugin Check` | 45 min | After every lane passes: generates candidate metadata with `scripts/update-compatibility-baselines.php`, then `scripts/release-qa.sh` with `PLUGIN_CHECK_VERSION=latest`. Uploads `compatibility-current-plugin-check`. |
| `07 - Compatibility QA / open baseline PR` | 60 min | Only if all lanes and the checker passed, an update is needed, and the run is scheduled or `open_update_pr` is set. Rebuilds the candidate commit, runs `scripts/release-qa.sh` on it, refuses if `main` moved, pushes a new branch (never overwriting one) and opens "Update tested compatibility baselines". |
| `07 - Compatibility QA` | 10 min | Report: writes the lane table to the job summary and fails if any required job failed or a requested promotion did not happen. After **two consecutive** failed scheduled runs it opens, reopens or updates one tracking issue, "Compatibility QA: persistent scheduled failure". No checkout. |

Lanes (from `scripts/compatibility-matrix.php`; PHP 8.2 and MySQL 8.0.36
unless noted):

| Lane | WordPress | MCP Adapter | Other |
| --- | --- | --- | --- |
| `wp69-php81-compatibility` | 6.9 | pinned | PHP 8.1 |
| `current-baseline` | pinned | pinned | |
| `latest-mcp-adapter` | pinned | latest | |
| `latest-wordpress` | latest | pinned | |
| `combined-latest` | latest | latest | |
| `php-8-4` | pinned | pinned | PHP 8.4 |
| `mysql-8-4` | pinned | pinned | MySQL 8.4 |
| `latest-seo` | latest | latest | Current Yoast SEO and SEOPress |

All eight lanes run every time, even when there is no new upstream release;
then the `latest` lanes repeat the pinned versions.

Permissions: `contents: read` for discovery and lanes; the PR job alone has
`contents: write` and `pull-requests: write`; the report job has issue access
and no checkout.

## Run locally

The simplest reproduction is a manual dispatch on `main` (no PR is opened
unless you tick `open_update_pr`):

```bash
gh workflow run 07-compatibility-qa.yml --ref main
```

Each lane is `scripts/qa-run.sh all` with these variables set from the matrix:
`WORDPRESS_IMAGE`, `MYSQL_IMAGE`, `MCP_ADAPTER_ZIP`, `MCP_ADAPTER_SHA256` and
`DEPENDENCY_POLICY`, plus a unique `COMPOSE_PROJECT_NAME`. Setting the same
variables locally reproduces one lane in a fresh project.

## Reading failures

Triage each failure as a supported-version regression, upstream drift, or
workflow and infrastructure maintenance before changing pins or merge policy.
Supported-version failures can block a release; transient infrastructure or
unsupported upstream combinations need separate triage, not silent skips. An
unavailable candidate image is an infrastructure failure, not permission to
promote an untested baseline. Reproduce scheduled failures before acting.

## Details

### Baseline promotion

Only passing candidates may update `.github/compatibility-versions.json`,
concrete image pins or `readme.txt` `Tested up to`. Missing images, failed
discovery, stale history and unavailable dependencies fail explicitly. Newer
passing versions produce maintainer-reviewed PRs, never automatic merges.

Metadata generation uses the maintained updater CLI with all eight version
and digest options, including `--mcp-adapter-sha256`, `--wp-cli-sha512` and
`--confirmed-wordpress`. A metadata parser failure is not a Plugin Check result
or a successful promotion. Changed `main` history, stale candidate branches
and closed PRs need explicit handling rather than overwriting earlier
decisions.

### Bot PRs need real PR checks

The repository lets Actions create PRs. A PR created with `GITHUB_TOKEN`
starts approval-required PR-event runs: a maintainer must select **Approve
workflows to run**. Those PR-event checks, not dispatched runs, must satisfy
the ruleset. If `main` changes, update and retest the PR through real PR
events; older matrix results are not evidence for another head. A GitHub App
is an alternative if automatic check start-up becomes necessary; the current
flow needs no long-lived token.

### Promotion to required checks

Keep 07 scheduled and manual until the matrix is stable enough to promote
selected lanes to the ruleset. PHP 8.0 is the advertised minimum; the PHP 8.1
lane here is not WordPress integration evidence for it (see
[06](06-minimum-runtime-qa.md)).
