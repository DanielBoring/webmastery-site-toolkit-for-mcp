# 10 - Adapter Assessment

Workflow: [`.github/workflows/10-adapter-assessment.yml`](../.github/workflows/10-adapter-assessment.yml)
· Required: no (assessment) · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The released, unchanged **2.6.0** production ZIP still works with MCP Adapter
**0.6.1** and **0.7.0**: every native ability stays discoverable and
schema-valid, and representative execution, permission and error checks pass,
including Adapter 0.7.0's sessionless protocol. It tests a published package,
not the current source. The findings and limits are in the
[Adapter 0.7 assessment](reference/mcp-adapter-0.7-assessment.md).

## When it runs

- Manual dispatch.
- PRs that change the assessment itself: this workflow,
  `scripts/adapter-release-assessment.*`,
  `scripts/adapter-candidate-assessment.py`, `tests/e2e/adapter-release-*` or
  `tests/unit/test_adapter_release_assessment.py`.
- Not scheduled and not a merge or release gate.

## What the workflow does

One job, `10 - Adapter Assessment`, as a two-lane matrix (`fail-fast: false`,
20 minutes each). GitHub shows each lane as
`10 - Adapter Assessment (<label>, <image>)`.

| Lane | WordPress image |
| --- | --- |
| `pinned` | `wordpress:7.1.2-php8.2-apache` |
| `wordpress-floor` | `wordpress:6.9-php8.1-apache` |

Each lane:

1. Runs the Python guard and parser tests
   (`test_adapter_release_assessment.py`).
2. Runs `scripts/adapter-release-assessment.sh` in a fresh project
   (`mcp-release-<label>-<run>-<attempt>`): downloads the 2.6.0 ZIP from
   GitHub and WordPress.org and both Adapter ZIPs, verifying pinned SHA-256
   digests, installs them with `tests/e2e/adapter-release-compose.yml`, and
   compares discovery, schemas, execution, permissions and errors under each
   Adapter version.

Permissions: `contents: read` only. No artifacts.

The `pinned` lane's image (7.1.2) was pinned when the assessment was recorded
and is older than the current development baseline (7.1.3).

## Run locally

```bash
python -B -m unittest discover -s tests/unit -p test_adapter_release_assessment.py -v
COMPOSE_PROJECT_NAME=mcp-release-local-1 bash scripts/adapter-release-assessment.sh
```

The script refuses a project that already has resources; use a new name each
run. With two arguments (a clean checkout at the recorded snapshot commit and
its ZIP) it assesses that candidate package instead.

## Reading failures

A guard-test failure is in the assessment tooling. A runtime failure in one
Adapter version but not the other is a compatibility finding to record in the
assessment document, not a reason to change the released ZIP. Download digest
mismatches mean an upstream artifact changed; stop and investigate.
