## Closes
<!-- Link the issue being resolved: Closes #1 -->

## Summary
<!-- What does this PR change and why? -->

## Ability name (if new)
<!-- e.g. wp-mcp/list-media — leave blank for bug fixes -->

## Testing
<!-- How did you verify this works? List the ability calls you ran and what they returned. -->
<!-- E2E QA tests will run automatically on push. -->

## Checklist
- [ ] Capability check in `permission_callback` returns `WP_Error` on failure
- [ ] All inputs sanitized (`sanitize_text_field`, `absint`, `wp_kses_post`, or enum validation)
- [ ] Returns `['success' => true, 'data' => [...]]` on success and `['success' => false, 'error' => '...']` on failure
- [ ] Ability name uses `wp-mcp/` prefix
- [ ] `annotations` flags set correctly (`readonly`, `destructive`, `idempotent`)
- [ ] `readme.txt` changelog updated under `== Changelog ==`
- [ ] E2E QA tests passing (see CI/workflow status)
