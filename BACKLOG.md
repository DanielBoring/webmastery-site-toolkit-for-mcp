# WP MCP Abilities — Backlog

Items are ordered by priority. Work through them top-to-bottom.

---

## Bugs

- [x] **SEO:** `seo-site-overview` hardcodes `/sitemap_xml` — Yoast's actual URL is `/sitemap_index.xml`; the accessibility check returns false on every Yoast site (`class-seo.php`)
- [x] **Posts:** `create-post` and `update-post` don't allow `future` status — can't schedule a post (`class-posts.php`)

---

## Docs

- [x] **README:** Update ability count from 22 → 24; add `delete-category` and `delete-tag` to the Taxonomy table; fix verification line (`3 + 22 = 25` → `3 + 24 = 27`)

---

## Features — High Priority

- [ ] **Media** (`class-media.php`): `list-media`, `get-media`, `update-media` (alt text, title, caption), `delete-media`
- [ ] **Users** (`class-users.php`): `list-users`, `get-user` — needed to resolve the author ID returned by post normalize
- [ ] **Plugins** (`class-plugins.php`): `list-plugins`, `activate-plugin`, `deactivate-plugin`
- [ ] **Posts — featured image** (`class-posts.php`): `set-featured-image`, `remove-featured-image`
- [ ] **Posts — restore** (`class-posts.php`): `restore-post`, `restore-page` (untrash)
- [ ] **Posts — author name** (`class-posts.php`): add `author_name` and `author_login` to `normalize()` so post listings are human-readable

---

## Features — Medium Priority

- [ ] **Taxonomy — update** (`class-taxonomy.php`): `update-category`, `update-tag` (rename, re-slug, change description/parent)
- [ ] **Taxonomy — get** (`class-taxonomy.php`): `get-category`, `get-tag` by ID
- [ ] **Post meta** (`class-postmeta.php`): `get-post-meta`, `update-post-meta`, `delete-post-meta`
- [ ] **Site info** (`class-siteinfo.php`): `get-site-info` — blog name, tagline, URL, WP version, active theme

---

## Features — Lower Priority

- [ ] **Custom post types** (`class-posts.php` or new file): dynamically register list/get/create/update/delete for each registered public CPT
- [ ] **Revisions**: `list-revisions`, `restore-revision`
- [ ] **WooCommerce** (conditional on WC being active): `list-products`, `get-product`, `list-orders`, `get-order`
- [ ] **Bulk operations**: `bulk-trash-posts`, `bulk-publish-posts`

---

## Meta

- [ ] **GitHub Issues:** Authenticate `gh auth login` and migrate this backlog into GitHub Issues for proper tracking (labels: `bug`, `enhancement`, `docs`)
