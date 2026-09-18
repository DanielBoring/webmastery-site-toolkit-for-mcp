# Software Development Lifecycle

This document explains how work moves from an idea or reported problem to a maintained WordPress.org release. It is the entry point for the repository's strategy documents; each linked guide owns the detailed policy for its part of the lifecycle.

## Lifecycle overview

```text
Idea, defect, or maintenance signal
                |
                v
     Issue and acceptance criteria
                |
                v
   Design and implementation planning
                |
                v
       Build on a feature branch
                |
                v
       Test and pull request review
                |
                v
        Merge, package, and release
                |
                v
      Monitor, maintain, and learn
                |
                +----> New or updated issue
```

## Lifecycle stages

| Stage | Purpose | Primary artifacts | Governing documentation |
| --- | --- | --- | --- |
| Plan | Define the problem, desired outcome, scope, constraints, and evidence of success. | GitHub issue or an imported `ISSUES/*.md` source document | [Issue templates](../.github/ISSUE_TEMPLATE), [Contributing guide](../CONTRIBUTING.md) |
| Design | Decide the behavior, permissions, risks, compatibility boundaries, and technical approach before implementation. | Issue design notes, acceptance criteria, and implementation plan | [Security Strategy](security-strategy.md), [Contributing guide](../CONTRIBUTING.md) |
| Build | Implement the accepted approach on an issue-specific branch and keep code, tests, documentation, and changelogs aligned. | Branch, commits, code, tests, and documentation | [Contributing guide](../CONTRIBUTING.md), [Copilot instructions](../.github/copilot-instructions.md) |
| Test | Prove behavior, security, compatibility, transport, and package quality at the appropriate layers. | Local test results, CI check runs, and retained QA artifacts | [QA Strategy](qa-strategy.md) |
| Deploy | Review, approve, package, publish, and verify the exact validated artifact. | Pull request, release ZIP, approval record, GitHub Release, and WordPress.org SVN tag | [CI/CD Strategy](ci-cd-strategy.md), [Release Strategy](release-strategy.md) |
| Maintain | Detect compatibility drift, respond to defects and vulnerabilities, recover safely, and feed findings into planning. | Compatibility PR, issue, security advisory, hotfix, and incident evidence | [QA Strategy](qa-strategy.md), [Security Strategy](security-strategy.md), [Release Strategy](release-strategy.md) |

Security applies across every stage. CI/CD connects the stages by automating validation, reporting, approvals, and publication without replacing human responsibility.

## Strategy documents

### [QA Strategy](qa-strategy.md)

Defines the validation layers, what each check proves, when each check runs, and how to interpret failures.

### [Security Strategy](security-strategy.md)

Defines the threat model, permission and privacy expectations, dependency and workflow security, and vulnerability response.

### [CI/CD Strategy](ci-cd-strategy.md)

Defines how GitHub Actions, branch protection, workflow permissions, schedules, artifacts, and failure handling automate the lifecycle.

### [Release Strategy](release-strategy.md)

Defines versioning, release readiness, approval, WordPress.org publication, partial-failure recovery, hotfixes, and rollback.

## How a change moves through the lifecycle

1. Open or select a GitHub issue and describe the problem or desired outcome.
2. Define scope, constraints, acceptance criteria, and open questions.
3. Add design decisions and an implementation plan when the change's risk or complexity warrants them.
4. Implement the change on an issue-specific branch, including the tests and documentation needed to prove it.
5. Open a pull request that links the issue, explains the change, and records validation evidence.
6. Pass the required automated checks and human review gates.
7. Merge the reviewed change to `main`.
8. Publish through the protected release process when the change belongs in a plugin release.
9. Turn defects, vulnerabilities, and compatibility findings into issues that re-enter the lifecycle.

## Planning depth

Planning should be proportional to risk rather than identical for every change.

| Change type | Expected planning |
| --- | --- |
| Typo or routine repository maintenance | A clear pull request summary; link an issue when one exists. |
| Contained bug fix | Reproduction, expected behavior, affected surface, and the regression evidence that will prove the fix. |
| Feature or ability change | Problem, desired outcome, constraints, acceptance criteria, permission implications, and an implementation plan. |
| Breaking, destructive, cross-cutting, or security-sensitive change | Design decisions, alternatives, threat and permission analysis, compatibility impact, implementation and test plans, and explicit human review before release. |

## Human decision points

Humans remain responsible for:

- accepting an issue and its scope
- resolving open product and design questions
- approving significant risk or compatibility decisions
- reviewing whether a pull request satisfies its intent
- authorizing production publication
- deciding whether maintenance findings should be fixed, scheduled, or dismissed

Automation and AI can prepare evidence and execute approved steps, but they do not replace these decisions.

## Sources of truth

| Record | Source of truth |
| --- | --- |
| Intent, requirements, acceptance criteria, and planning | GitHub Issues; `ISSUES/*.md` files are import sources until converted to issues |
| Recurring policy | This overview and the linked strategy documents |
| Implementation and review | Pull requests, commits, review threads, and required checks |
| Automated validation | GitHub Actions check runs and their retained artifacts |
| Published plugin release | GitHub Release and the corresponding WordPress.org SVN tag |
| Vulnerability coordination | GitHub private vulnerability reporting and security advisories |
