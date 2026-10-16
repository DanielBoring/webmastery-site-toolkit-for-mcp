# 09 - Publishing Checks

Workflow: [`.github/workflows/09-publishing-checks.yml`](../.github/workflows/09-publishing-checks.yml)
· Required: no (diagnostic) · Overview: [00 - SDLC overview](00-sdlc-overview.md)

## What it proves

The built ZIP passes WordPress Plugin Check with both the pinned and the
current checker version. It is a publishing check, not product acceptance: it
does not run the contract or transport tests, and a pass is not WordPress.org
directory approval.

## When it runs

Manual dispatch only. Use it to see checker findings before tagging, or after
a new Plugin Check release. The release itself runs the same checks inside
`08 - Release / validate`.

## What the workflow does

| Job | Timeout | Does |
| --- | --- | --- |
| `09 - Publishing Checks` | 30 min | With `REQUIRE_CURRENT_PLUGIN_CHECK=1`: `scripts/qa-run.sh publishing` (`release-qa.sh --publishing`) builds and validates the ZIP, prepares an owned stack, runs Plugin Check against a pristine extraction with the pinned and current versions, and reports that product acceptance did not run. Uploads `publishing-checker-verdicts`. |

Permissions: `contents: read` only. Nothing is published.

## Run locally

```bash
composer qa:publishing               # Plugin Check only
composer qa:release                  # package QA + Plugin Check
PLUGIN_CHECK_VERSION=latest composer qa:publishing   # current checker only
```

## Reading failures

Plugin Check reports are parsed independently of the command's exit status.
Any ERROR, malformed report or unknown finding type fails; existing warnings
stay visible without failing. Report these findings under publishing,
separately from product results.

## Details

Plugin Check can find defects relevant to users, but its directory-specific
requirements are publishing checks, not evidence that plugin behavior works.

For a WordPress.org submission or publication, separately review Plugin Check
findings and the
[WordPress.org guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
Licensing, bundled assets, external services and consent, executable code,
readme claims, default libraries, SVN and version discipline and trademarks
remain maintainer responsibilities. Known warnings must be documented. Plugin
Check does not establish directory approval or listing, and a product-QA pass
does not authorize publication. See also
[08 - Release](08-release.md#wordpressorg-guideline-governance).
