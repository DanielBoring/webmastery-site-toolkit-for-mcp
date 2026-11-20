# 06 - Minimum Runtime QA

Workflow: [`.github/workflows/06-minimum-runtime-qa.yml`](../.github/workflows/06-minimum-runtime-qa.yml)
· Required to merge: no · Required to release: **yes** · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The plugin works on its advertised minimums: WordPress 6.9 on genuine PHP
8.0.30 with security backports, through both WP-CLI and authenticated HTTP,
using the same source acceptance tests as 03-04.

## When it runs

- **Nightly** at 07:23 UTC on `main`, and **manual dispatch**.
- **PRs** only when the lane itself changes: this workflow,
  `scripts/qa-floor.sh`, `scripts/qa-build-legacy-image.sh` or `tests/fixtures/php80-floor/**` (workflow path
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
| `06 - Minimum Runtime QA / WordPress 6.9 PHP 8.0` | 60 min | `bash scripts/qa-floor.sh` with `WSTM_QA_LOCATION=cloud`: builds the PHP 8.0.30 / WordPress 6.9 runtime from digest-pinned bases and signed PHP packages with verified security revisions, selects maintained `wp69-seo` provider pins, verifies the actual CLI and HTTP interpreter lanes, and runs source acceptance. Uploads `minimum-runtime-product-summary`. |
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

The shared legacy fixture copies WordPress 6.9 and its startup files from the
pinned WordPress image. Debian Bookworm supplies the final image. Its Apache
PHP module and CLI come from the signed packages.sury.org PHP repository. The
build verifies the archive keyring checksum and requires each runtime package
to meet the maintainer's first revision with both CVE fixes.

| PHP series | First fixed package revision | Evidence |
| --- | --- | --- |
| 8.0 | `1:8.0.30-25` | [PHP 8.0 packaging sources](https://packages.sury.org/php/pool/main/p/php8.0/) |
| 8.1 | `8.1.34-9` | [PHP 8.1 packaging sources](https://packages.sury.org/php/pool/main/p/php8.1/) |

In each source archive's `debian/changelog`, the entry for that revision records
the PHP 8.2.35 backport for CVE-2026-109618 and CVE-2026-109619. The build
accepts later signed revisions in the same PHP series. The upstream PHP version
alone cannot establish this patch status. If the vendor replaces its archive
keyring package, verify the replacement before updating the checksum.

The launcher explicitly selects `DEPENDENCY_POLICY=wp69-seo`, using
`wp69_yoast` and `wp69_seopress` from the compatibility configuration. These
maintained floor pins survive global provider baseline promotion; see
[provider policy by runtime](07-compatibility-qa.md#provider-policy-by-runtime)
for maintenance and verification requirements. Current provider releases remain
covered by the latest-WordPress lanes in 07.
Genuine 8.0/8.4 syntax/unit evidence, actual CLI/HTTP observations and package
results remain distinct.
Do not raise a support floor, fabricate an image tag or label PHP 8.1 as 8.0
to close that gap.
