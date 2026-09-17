# Security Strategy

This repository is a public WordPress.org plugin that exposes MCP abilities. Security strategy focuses on preventing unsafe access to WordPress data and site-changing actions, while QA strategy defines the checks that prove the policy.

## Security goals

1. Use the narrowest relevant WordPress capability for every ability.
2. Avoid exposing private content, trashed content, backend environment details, user identity fields, plugin details, or other sensitive data to lower-privilege users.
3. Prevent status escalation such as publishing, scheduling, or making content private without the required capability.
4. Keep ability `permission_callback` behavior aligned with execute-time checks.
5. Treat dependency, workflow, and release automation as part of the security boundary.

## Threat model

Primary risks for this plugin:

- authenticated low-privilege users invoking MCP abilities that reveal data they could not access in wp-admin
- broad list abilities leaking private/trash content or unauthorized totals
- write abilities bypassing object, publish, delete, plugin-management, or administrator capabilities
- sensitive response fields exposing logins, emails, version fingerprints, plugin state, or environment details
- unsafe URLs, uploads, protected metadata, or unvalidated identifiers crossing trust boundaries
- compromised workflow tokens, leaked secrets, or vulnerable dependencies affecting release integrity

## Ability permission policy

Every ability must have an explicit `permission_callback`. Public `__return_true` callbacks are blocked by `composer validate:security-qa` unless intentionally allow-listed after explicit review.

Use these defaults:

| Ability type | Permission expectation |
| --- | --- |
| Public-safe read-only data | `read`, with documentation explaining why the response is safe. |
| Object reads | Object-aware checks such as `read_post`, `edit_post`, or mapped CPT capabilities before returning full object payloads. |
| List/query abilities | Query-level capability plus per-object/status filtering before normalizing results and totals. |
| Writes and deletes | Object-specific `edit_*` / `delete_*` checks and status-specific publish/private/schedule checks. |
| Plugin, environment, database, backup, performance, security, or runtime details | Administrator-level capabilities such as `manage_options` or plugin-management capabilities. |
| User identity data | Restrict login/email fields to callers with user-management capabilities; use `assert_missing_paths` for lower-privilege cases. |

## Trash safety

Post, page, generated CPT, and bulk post trash abilities must refuse before calling `wp_trash_post()` when `EMPTY_TRASH_DAYS` is falsy: core otherwise falls back to permanent deletion. Validate existence/type and object-delete permission first; retain existing errors and use `trash_disabled` only for authorized, existing items. Bulk operations keep per-ID failures and existing totals, not a new global-error contract. This safety fix adds no force/permanent-delete route.

Comment moderation uses `wp_set_comment_status( ..., 'trash' )`, not `wp_trash_comment()`. Its row-preserving behavior with trash disabled is characterized separately; do not assume the post-trash fallback applies to this different API. This does not change retention policies or other plugins' hooks.

## Security-sensitive test policy

Security-sensitive abilities must have E2E manifest coverage showing both allowed and denied behavior. Add `assert_missing_paths` when a response should omit sensitive fields for lower-privilege users.

Current static enforcement:

- `composer validate:security-qa`
- `composer validate:e2e-manifest`
- `composer audit --locked`
- `composer phpcs`
- PHPStan at the configured project level

Current runtime enforcement:

- Ability Contract QA executes all manifest cases in WordPress.
- Contract QA also boots fresh PHP processes with `EMPTY_TRASH_DAYS=30` and `0`, asserting the actual constant, registered-ability results, persisted rows, and zero permanent-deletion API filters/hooks. Enabled trash/restore and disabled comment-status behavior are checked independently. These configuration-specific checks are direct PHP ability calls, not HTTP MCP coverage.
- Full MCP E2E verifies real MCP Adapter authentication, discovery, execution, and subscriber denial.
- Docker QA fails when the WordPress debug log contains warnings, notices, deprecations, or errors.

## Dependency and supply-chain policy

- Composer dependencies are audited with `composer audit --locked`.
- Dependency changes should be reviewed carefully in PRs.
- Dependabot checks Composer and GitHub Actions dependencies weekly. Alerts and automatic security-update PRs are enabled; updates still require normal QA and review.
- Do not suppress a Composer advisory to restore a green merge gate. Update compatible tooling while preserving the plugin's declared PHP floor.
- Pin routine test dependencies and verify executable downloads before use. Floating compatibility lanes must record exact tested versions before proposing pin changes.
- Release workflows should build from the reviewed repository state and validate package contents before publishing.

## Secrets and workflow security

- Do not commit credentials, Application Passwords, tokens, SVN passwords, or service keys.
- Prefer GitHub Actions `GITHUB_TOKEN` with explicit job-level `permissions`.
- Do not expose secrets to workflows running untrusted PR code.
- Rotate any secret that appears in logs or repository history.
- Keep release credentials outside repository files. `SVN_USERNAME` and `SVN_PASSWORD` are stored only in the protected `wordpress-org` environment and become available after required review. Do not duplicate them as repository-level secrets.
- Secret scanning and push protection are enabled. They are additional detection controls, not proof that a change contains no credentials.
- Production tags are restricted, release commits must be in `main` history, and the approved artifact must be the artifact deployed. Administrator tag-rule bypass does not bypass the environment approval.

## WordPress.org guideline security and privacy expectations

The Detailed Plugin Guidelines make plugin developers responsible for all plugin contents and actions. For this MCP plugin, guideline review should be part of security review when a PR changes:

- **External communication** — document required third-party services in `readme.txt`; do not contact external servers for tracking or non-essential functionality without explicit consent.
- **Executable remote code** — do not load executable plugin, theme, update, JavaScript, or CSS code from third-party systems unless it is a documented service use that the guidelines allow.
- **Public credits and links** — do not add public-facing "powered by" links, credits, or promotional output unless it is opt-in and disabled by default.
- **Admin notices** — keep dashboard notices scoped, actionable, dismissible, and self-removing when resolved.
- **Bundled assets and libraries** — ensure included code/assets are GPL-compatible and use WordPress-provided libraries instead of duplicating default libraries.
- **Readme claims** — avoid unsupported security, compliance, legal, traffic, SEO, or automation guarantees in public-facing copy.

## Vulnerability response

1. Use [private vulnerability reporting](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/security/advisories/new) for suspected exploitable vulnerabilities. The feature is enabled; the public policy is [SECURITY.md](../SECURITY.md).
2. Triage severity, affected versions, exploitability, and whether WordPress.org users are exposed.
3. Fix on a private branch or security advisory fork when appropriate.
4. Add regression tests or manifest cases that would have caught the issue.
5. Release a patched version and publish advisory notes when needed.
6. If the vulnerability affects a released package, coordinate WordPress.org plugin update timing and user communication.

Security fixes target the latest stable release, without separate backports for older releases. Response and resolution are best-effort, without a fixed deadline. Reports should use disposable reproductions and omit credentials or private site data.

## Official references

- GitHub Docs: [Repository security advisories](https://docs.github.com/en/code-security/concepts/vulnerability-reporting-and-management/repository-security-advisories)
- GitHub Docs: [Secret scanning](https://docs.github.com/en/code-security/concepts/secret-security/secret-scanning)
- GitHub Docs: [Dependency review](https://docs.github.com/en/code-security/concepts/supply-chain-security/dependency-review)
- GitHub Docs: [Code scanning](https://docs.github.com/en/code-security/concepts/code-scanning/code-scanning)
- GitHub Docs: [Secure use reference](https://docs.github.com/en/actions/reference/security/secure-use)
- WordPress Developer Resources: [Security](https://developer.wordpress.org/apis/security/)
- WordPress Plugin Handbook: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
