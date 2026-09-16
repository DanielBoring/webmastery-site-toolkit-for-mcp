---
name: Bug report
about: Something isn't working correctly
title: '[Bug] `upload-image` URL guard is IPv4-only, single-resolution, and downloads the full file before the size check'
labels: bug, priority: low
assignees: ''
---

**Describe the bug**
`validate_public_image_url()` resolves the host once with `gethostbynamel()` (A records only) and then `download_url()` resolves it again — so a dual-stack host with a public A record and a private/ULA AAAA record, or a DNS-rebinding host, can pass the plugin's check. The `wp_max_upload_size()` comparison runs only after the whole file has been written to temp, so an oversized URL costs full download time and disk.

Mitigations already in place (verified): `download_url()` uses `wp_safe_remote_get()` (`wp-admin/includes/file.php:1172`), so core's `wp_http_validate_url()` (`wp-includes/http.php:588-622`) blocks loopback, private, link-local and CGNAT IPv4 ranges, restricts ports to 80/443/8080, rejects names `gethostbyname()` cannot resolve (which covers AAAA-only hosts and bracketed IPv6 literals), and re-validates every redirect hop. Core deliberately allows the site's **own** host (`$same_host`), so an image URL pointing at the site itself is fetched; response bytes are never returned to the caller and the MIME type must resolve to `image/*`.

**Ability name**
`webmastery-site-toolkit-for-mcp/upload-image`

**Steps to reproduce**
1. As an Author call `webmastery-site-toolkit-for-mcp/upload-image` with `{ "image_url": "https://<host with public A and private AAAA>/x.png" }` (or a URL whose `Content-Length` is far above `wp_max_upload_size()`).
2. Expected: `invalid_url` before any request; or `file_too_large` before any download.
3. Got: a request may be issued over IPv6 if the resolver prefers it; the large body is fully downloaded to temp before `file_too_large` is returned.

**Relevant code**
- `includes/class-media.php:109-115` — `is_private_ip()` (IPv4 private/reserved flags only).
- `includes/class-media.php:117-150` — `validate_public_image_url()`; `gethostbynamel()` at `:140`.
- `includes/class-media.php:311` — `download_url( $image_url )`; `:316-329` size check after download.
- `includes/class-media.php:338-346` — MIME re-derived from bytes with `wp_check_filetype_and_ext()` (good; keep).
- Core: `wp-admin/includes/file.php:1172` (`wp_safe_remote_get`), `wp-includes/http.php:588-622` (`wp_http_validate_url`).

**Environment**
- WordPress version: 7.0.x on the reference site (plugin floor: 6.9+; E2E fixture: 7.0.1)
- PHP version: 8.4.x on the reference site (plugin floor: 8.0; CI runs 8.0)
- MCP Adapter version: 0.5.0
- MCP client (Claude Code / Claude Desktop / other): Claude Desktop via the MCP Adapter `execute-ability` gateway
- Plugin version: 2.5.0 released package on the reference site; findings verified against HEAD `b853411` (2.5.0 + Google Site Kit #103)

**Proposed fix**
1. Also resolve `dns_get_record( $host, DNS_AAAA )` and reject any answer that fails `FILTER_VALIDATE_IP` with `FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`.
2. Issue `wp_safe_remote_head()` first; refuse when `Content-Length` exceeds `wp_max_upload_size()` or `Content-Type` is not `image/*` (still re-check bytes after download).
3. Document in README that same-host URLs are permitted by core's `http_request_host_is_external` default, and that the plugin layer is defence-in-depth over `wp_safe_remote_get()`.
4. Do not attempt to pin IPs — core's HTTP API has no hook for it and a custom transport would cost more than it saves.
5. Unit tests for `validate_public_image_url()` behind a resolver seam (`tests/unit/bootstrap.php` already stubs WP functions); E2E already has three denial cases on the permission path.

**Additional context**
- Author-level surface; realistic impact is a disk/time denial-of-service from the size gap rather than data exposure.
- Verified from plugin and core source; not run live (uploads are writes).

- Assessment finding: **A-9** in `ASSESSMENT.md` (severity Low → label `priority: low`).
- Related: `19-close-test-coverage-gaps-ranked-by-blast-radius.md`.
