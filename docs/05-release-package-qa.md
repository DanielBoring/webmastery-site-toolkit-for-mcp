# 05 - Release Package QA

Workflow: [`.github/workflows/05-release-package-qa.yml`](../.github/workflows/05-release-package-qa.yml)
· Required to merge: no · Required to release: **yes** · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The ZIP users install behaves like the source. It builds the release ZIP,
validates its layout, metadata and hashes against the production allowlist,
installs exactly that ZIP in a disposable WordPress stack, and runs the
contract, transport and product checks against the installed files. It does
not run Plugin Check; that is 08 and 09.

## When it runs

- **PRs:** the workflow starts on every PR, but the package job runs only when
  ZIP inputs change (`package` scope: main plugin file, `readme.txt`,
  `LICENSE`, `.gitattributes`, `assets/`, `.wordpress-org/`, the build,
  archive and package scripts, `docker-compose.release.yml` and this
  workflow).
- **Nightly** at 07:23 UTC on `main`, and **manual dispatch**; both always run
  the package job. Not run on push to `main`.
- A newer run for the same PR or ref cancels the older one.

`08 - Release` requires the job `05 - Release Package QA / package` to have
actually run and passed on the tagged commit, from a `main` schedule or
dispatch. If nothing merged since the last nightly run, that run counts;
otherwise dispatch it: `gh workflow run 05-release-package-qa.yml --ref main`.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `05 - Release Package QA / detect changes` | 5 min | `scripts/qa-changes.sh` with `QA_SCOPE=package`. |
| `05 - Release Package QA / package` | 55 min | If in scope: runs the release safeguard regressions (the `composer test:release-safeguards` scripts), then `scripts/qa-run.sh package` (`release-qa.sh --product`) in project `wstm-package-qa-<run>-<attempt>`, then uploads evidence. |
| `05 - Release Package QA` | 2 min | Summary: `scripts/qa-check-gate.php` requires a successful detector and a successful package job, or a positive skip. |

`release-qa.sh --product`, step by step:

1. Build the ZIP with `scripts/build-release.sh` (or use `RELEASE_ZIP`).
2. Validate it with `scripts/validate-release-package.php` and record its SHA-256.
3. Reserve a fresh `build/release-qa-<owner>/` with separate `checker/` and
   `runtime/` extractions.
4. Start an owned stack with the runtime extraction installed read-only.
5. Run contract, transport and product acceptance against the installed package.
6. Recheck production hashes and the original ZIP digest, then tear down.

**Artifacts** (7-day retention): `product-qa-package-summary`,
`destructive-safety-package-evidence`, `input-schema-package-evidence`,
`post-meta-authorization-package-evidence`, `error-contract-package-evidence`,
`metadata-boundary-package-evidence`, `database-privacy-package-evidence`.

Permissions: `contents: read` only. Nothing is published.

## Run locally

```bash
composer qa:package                    # scripts/qa-run.sh package
scripts/qa-local.sh --contract --e2e --package
composer test:release-safeguards       # the mocked safeguards, no Docker
```

## Reading failures

Package failures concern metadata, the production allowlist and hashes, or the
installed runtime behavior. If source Docker QA (03-04) passes but this fails,
suspect something the ZIP adds, omits or changes. Release-safeguard failures
are in the release tooling, not the plugin.

## Details

### Original-package QA

Build once or use `RELEASE_ZIP`, validate the production allowlist and hashes,
then reserve `build/release-qa-<opaque owner>/{checker,runtime}`. Neither path
overwrites a prior extraction. Runtime uses the installed original package,
read-only test/compatibility support and a private artifact volume, without a
checkout-wide or vendor overlay on production contents.

Plugin Check reads a separate pristine extraction at `/qa-package-pristine`;
nested test-bind placeholders cannot alter its findings. Recheck installed
production hashes, the finite allowed empty placeholders and original ZIP
SHA256 after runtime QA. Source-only success does not establish package behavior.
Release safeguards mock external orchestration/failure boundaries; they do not
replace real WordPress, HTTP, benchmark or package acceptance.

`composer qa:package` runs product checks alone. `composer qa:publishing` and
`composer qa:release` add Plugin Check; see
[09 - Publishing Checks](09-publishing-checks.md). Report product and checker
findings separately.

### Package reservations

Each package QA invocation reserves fresh `build/release-qa-<owner>` checker
and runtime extractions. Plugin Check copies the pristine ZIP contents into a
separate, exclusively created directory for each checker version; it never
deletes an occupied destination or reuses existing reports. The owned outer
package trap handles teardown, including failed runtime runs. Missing product
cleanup proof keeps retention armed and blocks that teardown. Raw product
evidence stays private; only the validated closed product summary is uploaded.
