# 02 - Unit Tests

Workflow: [`.github/workflows/02-unit-tests.yml`](../.github/workflows/02-unit-tests.yml)
· Required to merge: **yes** · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

Plugin code behaves as its unit tests expect on genuine PHP 8.0 and 8.4, and
the QA, release and evidence tooling still works when it changes. Unit tests
use doubles, not WordPress; they do not replace 03-04.

## When it runs

- Every PR, every push to `main`, nightly at 06:41 UTC, and manual dispatch.
- Product unit tests always run.
- QA-tooling tests and CI safeguards run only when `scripts/qa-changes.sh`
  (`default` scope) reports a QA-relevant change, and always on schedule and
  dispatch. The nightly run catches tooling regressions within a day even when
  a PR skipped them.
- A newer push to the same PR cancels the older run.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `02 - Unit Tests / detect changes` | 5 min | Runs `scripts/qa-changes.sh` and outputs `should-run`. |
| `02 - Unit Tests / product PHP 8.0` and `/ product PHP 8.4` | 15 min each | Verifies the interpreter version, then `composer qa:unit` (PHPUnit `product` suite). `fail-fast: false`. |
| `02 - Unit Tests / QA tooling PHP 8.4` | 45 min | If `should-run`: `composer qa:tooling` (PHPUnit `qa-tooling` suite). |
| `02 - Unit Tests / CI safeguards PHP 8.4` | 45 min | If `should-run`: `composer test:ci-safeguards`. |
| `02 - Unit Tests` | 5 min | Summary: product jobs must succeed; each tooling job must have succeeded, or been skipped by a successful detector (`scripts/qa-check-gate.php`). |

`composer test:ci-safeguards` runs the Python guard tests
(`test_rescoped_workflow_gates.py`, `test_qa_execution.py`,
`test_catalog_ordering.py`, `test_qa_profile.py`), the shell stage tests under
`tests/`, the PHPStan baseline guard, `composer test:release-safeguards`, and
the compatibility download and dependency-policy tests. These mock external
boundaries; they do not replace real WordPress, HTTP or package acceptance.

Permissions: `contents: read` only. No artifacts.

## Run locally

```bash
composer qa:unit      # product suite; part of composer qa
composer qa:tooling   # when you change scripts/ or tests/ tooling
composer test:ci-safeguards
```

Running PHPUnit without `--testsuite` runs both suites.

## Reading failures

Product failures usually mean helper or error-contract drift; the failing test
names the ability or helper. Tooling failures mean a QA, release or evidence
script changed behavior. Safeguard failures name the workflow or script
policy that no longer holds; update the guard only when the policy itself
changed, in the same PR.

## Details

### Suite classification and measurement

PHPUnit has two suites, mirrored from `tests/qa-suites.json`: `product`
(30 files that test plugin code; seconds to run) and `qa-tooling` (79 files
that test QA, release and evidence tooling; about 98% of unit runtime).
`python3 -B scripts/qa-profile.py inventory` fails if a test file is
unclassified or `phpunit.xml.dist` drifts from the catalog. Mixed files keep
all their tests in their primary category.

`scripts/qa-profile.py` also turns a PHPUnit `--log-junit` report or a GitHub
Actions jobs listing into diagnostic timings. Its output never selects tests
or counts as acceptance. Do not upload raw JUnit, fixture output or
data-provider labels. Earlier detailed measurements are in the git history of
the former `docs/qa-strategy.md`.

### Compatibility-baseline updater tests

`composer qa:tooling -- --filter CompatibilityBaselinesTest` exercises
workflow-shaped arguments, promotion and no-op behavior, and unchanged files
after invalid, duplicate or unknown options and invalid references. These
regressions do not update live pins or replace the runtime evidence in 07.
