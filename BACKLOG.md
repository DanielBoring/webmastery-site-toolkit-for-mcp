# Unlock MCP Potential — Backlog

Open feature requests and bugs are tracked in [GitHub Issues](https://github.com/DanielBoring/unlock-mcp-potential/issues).

---

## Open

### Features

- [ ] **SEO/Webmaster:** Add a read-only `unlock-mcp-potential/webmaster-verification-status` ability that checks public Google/Bing webmaster setup: Google Site Kit installed/active status, homepage `google-site-verification` meta tag, Bing `msvalidate.01` meta tag, `BingSiteAuth.xml`, visible DNS TXT verification records where available, `robots.txt` sitemap declarations, and sitemap reachability. Start with WordPress-visible/public proof only; consider a later admin-only API-backed version for confirmed Google Search Console and Bing Webmaster Tools account status, which would require OAuth/API credentials.

## Completed

### Bugs

- [x] **SEO:** `seo-site-overview` hardcodes `/sitemap_xml` — Yoast's actual URL is `/sitemap_index.xml`; the accessibility check returns false on every Yoast site (`class-seo.php`)
- [x] **Posts:** `create-post` and `update-post` don't allow `future` status — can't schedule a post (`class-posts.php`)

### Docs

- [x] **README:** Update ability count from 22 → 24; add `delete-category` and `delete-tag` to the Taxonomy table; fix verification line (`3 + 22 = 25` → `3 + 24 = 27`)
