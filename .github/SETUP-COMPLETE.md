# GitHub automation setup

The maintained workflow inventory and operating procedures are in
[AUTOMATION.md](AUTOMATION.md). Do not use the former two-workflow setup summary
as a release checklist.

## GitHub controls verified on September 16, 2026

- `wordpress-org` requires DanielBoring's approval, permits self-review for solo
  maintenance, disallows administrator bypass, and accepts only `v*` tags.
- SVN credentials remain environment-scoped; they are not repository secrets.
- `main` requires a pull request and resolved review conversations. Its history
  cannot be force-pushed or deleted, including by an administrator.
- Release-tag creation, updates, and deletion are restricted to repository
  administrators through the tag ruleset's explicit bypass.
- Actions tokens default to read-only; individual jobs request necessary writes.
  Actions-created pull requests are enabled, but their PR workflows need approval.
- Private vulnerability reporting, Dependabot alerts and security updates, secret
  scanning, and push protection are enabled.
- Automatic deletion of merged head branches was already enabled. No branch,
  environment, or secret cleanup was performed.

## Required-check activation

Require `1 - Static QA`, `2 - Unit Tests`, and `Docker QA gate` only after the
updated workflows have successful real PR-event runs, including a bot PR.
The `main-ci-gates` ruleset (23522901) is staged **disabled**, bound to the GitHub
Actions app (15368), with no bypass actors. It must not be treated as enforced
until it is activated and read back from GitHub. `workflow_dispatch` results do
not satisfy required branch-ruleset checks. Activate it before merging a passing
compatibility baseline PR.

Repository files describe intended automation; they cannot prove live settings
remain unchanged. Read back the effective rules and environment policy before
the next production release.
