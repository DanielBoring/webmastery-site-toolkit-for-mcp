# WordPress MCP Abilities — Backlog

Open feature requests and bugs are tracked in [GitHub Issues](https://github.com/DanielBoring/wordpress-mcp-abilities/issues).

---

## Completed

### Bugs

- [x] **SEO:** `seo-site-overview` hardcodes `/sitemap_xml` — Yoast's actual URL is `/sitemap_index.xml`; the accessibility check returns false on every Yoast site (`class-seo.php`)
- [x] **Posts:** `create-post` and `update-post` don't allow `future` status — can't schedule a post (`class-posts.php`)

### Docs

- [x] **README:** Update ability count from 22 → 24; add `delete-category` and `delete-tag` to the Taxonomy table; fix verification line (`3 + 22 = 25` → `3 + 24 = 27`)
