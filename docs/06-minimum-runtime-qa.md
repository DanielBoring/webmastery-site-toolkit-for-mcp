# 06 - Minimum Runtime QA

Workflow: [`.github/workflows/06-minimum-runtime-qa.yml`](../.github/workflows/06-minimum-runtime-qa.yml)
· Required to merge: no · Required to release: **yes** · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The plugin works on its advertised minimums: WordPress 6.9 on genuine PHP
8.0.30, through both WP-CLI and authenticated HTTP, using the same source
acceptance tests as 03-04. The PHP 8.1 lane in 07 does not prove the PHP 8.0
minimum; this check does.

The WordPress 6.9 / PHP 8.1 compatibility lane does not substitute for this
genuine PHP 8.0 run.

## When it runs

- **Nightly** at 07:23 UTC on `main`, and **manual dispatch**.
- **PRs** only when the lane itself changes: this workflow,
  `scripts/qa-floor.sh` or `tests/fixtures/php80-floor/**` (workflow path
  filter, then the `default` change scope).
- Not run on push to `main`.

`08 - Release` requires the job `06 - Minimum Runtime QA / WordPress 6.9 PHP 8.0`
to have actually run and passed on the tagged commit, from a `main` schedule
or dispatch. Dispatch it when needed:
`gh workflow run 06-minimum-runtime-qa.yml --ref main`.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `06 - Minimum Runtime QA / detect changes` | 5 min | `scripts/qa-changes.sh` (`default` scope). |
| `06 - Minimum Runtime QA / WordPress 6.9 PHP 8.0` | 60 min | `bash scripts/qa-floor.sh` with `WSTM_QA_LOCATION=cloud`: builds the PHP 8.0.30 / WordPress 6.9 runtime from digest-pinned bases and signed, frozen Debian packages, verifies the actual CLI and HTTP interpreter lanes, and runs source acceptance. Uploads `minimum-runtime-product-summary`. |
| `06 - Minimum Runtime QA` | 2 min | Summary via `scripts/qa-check-gate.php`. |

Permissions: `contents: read` only.

## Run locally

```bash
bash scripts/qa-floor.sh
```

## Reading failures

A failure here but not in 03-04 points at PHP 8.0 or WordPress 6.9
behavior: syntax or functions newer than the floor, or core APIs added after
6.9. An image-build failure is infrastructure, not runtime evidence either way.

## Details

The PHP 8.1 WordPress compatibility lane does not prove the advertised PHP 8.0
minimum. `06-minimum-runtime-qa.yml` separately builds actual PHP 8.0.30 with
WordPress 6.9 from digest-pinned bases and signed frozen Debian packages.
This EOL stack is a disposable compatibility fixture, not a production
recommendation. An image build alone is not runtime acceptance.
Genuine 8.0/8.4 syntax/unit evidence, actual CLI/HTTP observations and package
results remain distinct.
Do not raise a support floor, fabricate an image tag or label PHP 8.1 as 8.0
to close that gap.
