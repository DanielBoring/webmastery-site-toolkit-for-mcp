# 01 - Static QA

Workflow: [`.github/workflows/01-static-qa.yml`](../.github/workflows/01-static-qa.yml)
· Required to merge: **yes** · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The source parses on PHP 8.0 and 8.4, follows WordPress Coding Standards,
passes PHPStan level 5 without new baseline debt, has no locked-dependency
advisories, keeps the E2E manifest and security policy valid, has no
whitespace errors, and every workflow and shell script passes the pinned
linters. It does not run WordPress; runtime behavior is 03-04.

## When it runs

- Every PR (`opened`, `synchronize`, `reopened`), every push to `main`, and
  manual dispatch.
- Never skipped by change detection.
- A newer push to the same PR cancels the older run.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `01 - Static QA / PHP 8.0` | 15 min | Installs Composer dependencies and runs `composer qa:static`. |
| `01 - Static QA / PHP 8.4` | 15 min | Runs `composer lint:php` (syntax only). |
| `01 - Static QA / workflow lint` | 10 min | Downloads actionlint 1.7.12, ShellCheck 0.11.0 and zizmor 1.30.1, verifies each SHA-256, then runs `php scripts/workflow-lint.php`. |
| `01 - Static QA` | 2 min | Summary: passes only if both PHP jobs and workflow lint succeeded. |

`composer qa:static` runs, in order:

| Step | Command | Checks |
| --- | --- | --- |
| Syntax | `composer lint:php` | `php -l` on every PHP file |
| Coding standards | `composer phpcs` | WPCS via `phpcs.xml.dist` (short `[]` arrays enforced) |
| Static analysis | `composer phpstan` | Level 5, PHP 8.0 target, reviewed baseline |
| Baseline guard | `composer test:phpstan-baseline` | The ratchet below cannot be loosened |
| Dependency audit | `composer audit:locked` | Advisories in `composer.lock` |
| E2E manifest | `composer validate:e2e-manifest` | `tests/e2e/abilities-manifest.json` is well formed: known roles, required fields, valid expected results (coverage of live registrations is checked at runtime in 03) |
| Security policy | `composer validate:security-qa` | The [security QA policy](#security-qa-policy) |
| Whitespace | `composer diff-check` | `git diff --check` |

Workflow lint runs actionlint and zizmor (offline) over every workflow, and
ShellCheck over every `.sh` file under `scripts/` and `tests/`. The tools are
not Composer dependencies; `scripts/workflow-lint.php` refuses to run if a
tool is missing or the wrong version.

Permissions: `contents: read` only. No artifacts.

## Run locally

```bash
composer qa:static        # everything the PHP 8.0 job runs
composer lint:workflows   # needs the three pinned linters on PATH
composer qa               # static + product unit tests, the usual pre-PR check
```

## Reading failures

Fix these before any runtime work. Syntax or PHPCS failures point at the file
and line. PHPStan failures are new diagnostics: fix them rather than adding
baseline entries. A manifest failure names the malformed case in
`tests/e2e/abilities-manifest.json`. A security-policy failure
names the ability and the missing case or unreviewed public callback. An audit
failure needs a dependency update, not a suppression.

## Details

### PHPStan level 5 and baseline ratchet

`composer phpstan` analyzes the plugin entry point and all of `includes/` at
level 5 with a PHP 8.0 analysis target. Each reviewed baseline entry has an
anchored message, identifier, positive count and individual path. There are no
excluded production paths or global identifier-only ignores.
`treatPhpDocTypesAsCertain: false` and the WordPress stubs bootstrap remain.

A clean analysis means no diagnostics beyond reviewed debt; it does not prove
runtime safety or the soundness of every mixed value. Matching is by message,
identifier, file and count, so moving an equivalent error within a file still
needs diff review. PHPStan's target is not genuine PHP 8.0 execution.

To reduce debt:

1. Fix the underlying behavior or type issue with focused evidence. Do not
   remove defensive checks, broaden types or add casts or ignores only to
   satisfy analysis.
2. Run the complete `composer phpstan`. Unmatched-ignore reporting rejects
   stale entries and counts; per-file analysis is not full ratchet evidence.
3. Remove only resolved entries or lower their counts. Review any regeneration
   for increased counts, widened messages, wildcard paths or unrelated debt.
4. Run `composer phpstan`, `composer test:phpstan-baseline` and `composer qa`.
   Keep the fix and baseline reduction together.

An empty `parameters.ignoreErrors: []` is valid once the last debt is fixed.
The baseline guard generates isolated, non-executed fixtures and verifies
existing debt, new errors, other paths, different messages, excess or stale
counts and corresponding successful reductions. It runs here and in the CI
safeguards (02). Tool upgrades need review when inference or message wording
changes.

### Security QA policy

An ability needs explicit security coverage when it accesses private content,
users, environment, plugin, database or security data, full payloads or
identifiers; changes site state; or accepts object IDs, URLs, HTML, metadata
keys, taxonomy terms, plugin identifiers, statuses or capability-sensitive
input.

Require an allowed role and a denied role unless the response is intentionally
public-safe with documented rationale. Lists need object- and status-aware
filtering; lower-privilege responses need sensitive-field absence assertions.
Test invalid input, protected keys, unsafe URLs, stale preconditions and
no-write failures.

`composer validate:security-qa` rejects unreviewed public permission callbacks
(`permission_callback => '__return_true'` must be explicitly allow-listed) and
preserves the permission-hardening, comment moderation and sensitive-field
regressions. Comment cases retain mapped-CPT behavior, the Author moderation
floor, zero-ID controls and exact missing-object results. Permission denial
must use otherwise valid input rather than an unrelated schema rejection.
Static policy checks supplement runtime evidence and human review; see
[90 - Security strategy](90-security-strategy.md).
