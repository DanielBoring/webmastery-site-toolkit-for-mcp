# GitHub automation setup

The maintained workflow inventory and operating procedures are in
[AUTOMATION.md](AUTOMATION.md). Do not use the former two-workflow setup summary
as a release checklist.

## Main CI enforcement verified on September 17, 2026

The [`main-ci-gates` ruleset (23522901)](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/rules/23522901)
is **active**. Under explicit maintainer authorization, enforcement was activated
only after the bot PR evidence below passed. Only enforcement changed from `disabled` to `active`;
the rule configuration was preserved:

- Include only `refs/heads/main`, with no excluded refs and no bypass actors.
- Require exactly `1 - Static QA`, `2 - Unit Tests`, and `Docker QA gate`,
  each bound to the GitHub Actions integration (15368).
- Keep `strict_required_status_checks_policy=false` (no up-to-date-branch
  requirement from this rule) and `do_not_enforce_on_create=false`.

The ruleset and the effective rules for `main` were read back from GitHub.
`gh pr checks 140 --required` also confirmed all three required checks passed.
The September 16 disabled state was staging, not the current enforcement state.

The September 17 preflight also read back Actions workflow permissions:
`default_workflow_permissions=read` and `can_approve_pull_request_reviews=true`.
Actions tokens default to read-only; individual jobs request necessary writes.
Actions-created pull requests are enabled, but their PR workflows still need
maintainer approval. No permission setting changed during activation.

### Rollout evidence

The compatibility CLI parser repair in
[PR #139](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/pull/139)
merged as `54926de`; the CPT taxonomy update fix in
[PR #138](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/pull/138)
merged as `3dae8aa`. Both are already in the verified `main` commit,
`3dae8aa35ce36dcbdfbd0271ffd72fc50985ceef`.

| Evidence | Event / source | Verified result |
| --- | --- | --- |
| [Compatibility run 35234831249](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35234831249) | `workflow_dispatch` on `main` at `3dae8aa` | Discovery, all eight runtime lanes, current Plugin Check, and the actual proposed-commit full E2E/package QA passed; opened bot PR #140. |
| [Static QA run 35235423507](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423507) | `pull_request`, PR #140 | Passed, including `1 - Static QA`. |
| [Unit Tests run 35235423478](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423478) | `pull_request`, PR #140 | Passed, including `2 - Unit Tests`. |
| [Docker QA run 35235423350](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423350) | `pull_request`, PR #140 | Contract, MCP E2E, and `Docker QA gate` passed. The conditional skip-summary job correctly skipped because runtime jobs ran. |
| [Workflow lint run 35235423374](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423374) | `pull_request`, PR #140 | Passed. |
| [Release Package QA run 35235423398](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/actions/runs/35235423398) | `pull_request`, PR #140 | Passed without publishing. |

All five genuine PR-event runs used bot head
`4de69ccfcc57e22d4ee1433ab507c879b06aa90e`. They initially required approval
(`action_required`), received maintainer-authorized workflow approvals through
the REST API, then passed. The dispatched compatibility run was candidate
evidence, not a substitute for those PR checks.

At this September 17 verification,
[PR #140](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/pull/140)
is **open and unmerged**, authored by `github-actions`. Its one-file diff changes
only MCP Adapter 0.5.0 to 0.6.1 and the matching verified SHA-256 in
[`compatibility-versions.json`](compatibility-versions.json). Main still uses
MCP Adapter **0.5.0**. WordPress, `readme.txt` `Tested up to`, other dependency
pins, and PHP support are unchanged.

## Other GitHub controls last verified on September 16, 2026

These settings were not reverified by the September 17 CI activation check:

- `wordpress-org` requires DanielBoring's approval, permits self-review for solo
  maintenance, disallows administrator bypass, and accepts only `v*` tags.
- SVN credentials remain environment-scoped; they are not repository secrets.
- `main` requires a pull request and resolved review conversations. Its history
  cannot be force-pushed or deleted, including by an administrator.
- Release-tag creation, updates, and deletion are restricted to repository
  administrators through the tag ruleset's explicit bypass.
- Private vulnerability reporting, Dependabot alerts and security updates, secret
  scanning, and push protection are enabled.
- Automatic deletion of merged head branches was already enabled. No branch,
  environment, or secret cleanup was performed.

## Routine maintainer approvals

Initial main CI rollout and activation are complete. This does not approve a
baseline merge, a production release, or unrelated security work.

Future PRs created with `GITHUB_TOKEN` still need a maintainer to approve their
genuine PR-event workflows using **Approve workflows to run** or authorized
REST approvals. The rollout approvals are not a permanent auto-approval bypass.
`workflow_dispatch` results do not satisfy required branch-ruleset checks.
Review and merge passing baseline PRs explicitly; approve each production
release separately through the protected `wordpress-org` environment.

Repository files describe intended automation; they cannot prove live settings
remain unchanged. Read back the effective rules and environment policy before
the next production release.
