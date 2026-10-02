# SEO AI Export

WordPress / WooCommerce exporter that builds AI-ready site intelligence datasets (JSON, CSV, Markdown) for SEO workflows.

## Requirements

- WordPress with WooCommerce
- Place this folder next to `wp-load.php` (or adjust the require path in `index.php`)
- PHP 8+

## Usage

Run via browser or CLI after WordPress bootstrap:

```bash
php index.php
```

Outputs land in `output/` (gitignored):

- `output/json/` — structured datasets + `site_brain.json`
- `output/csv/` — flattened tables
- `output/markdown/` — per-entity markdown files

## Roadmap

1. ~~Content structure extraction (headings, internal links with anchors)~~
2. ~~Full SEO meta (Yoast + Rank Math)~~
3. ~~Enriched internal link graph + orphan detection~~
4. ~~`seo_audit.json` for AI agents~~

### Phase 1 entity shape

Each post/page/product/category now includes:

- `content.html_length`, Persian-aware `word_count` / `sentence_count` / `char_count`
- `structure.headings`, `internal_links` (url + anchor + target_post_id), `external_links`
- `structure.faq_candidates`, `lists_count`, `tables_count`, paragraph metrics

Products are also written to `output/markdown/products/{id}.md`.

### Phase 2 SEO + commerce shape

Normalized `seo` block (Yoast + Rank Math merge, Rank Math preferred when active):

- `title`, `description`, `canonical`, `focus_keyword`, `secondary_keywords`
- `robots.index` / `robots.follow`, `primary_category`, `breadcrumb_title`
- Open Graph + Twitter fields, plus `plugin` and per-field `sources`

Products also include:

- `identifiers.brand` / `gtin` / `ean` / `mpn`
- `variations` summary for variable products
- full approved `reviews` text
- category `breadcrumb` path

### Phase 3 link graph

`output/json/internal_link_graph.json` plus `site_brain.link_analysis`:

- nodes with `outgoing_links` (target_id / url / anchor), incoming, orphan, weak-hub flags
- dead internal links, duplicate anchors, links-by-category distribution
- navigation comes from a single `NavigationBuilder` source

### Phase 4 SEO audit

`output/json/seo_audit.json` with severity summary, actionable findings, and indexes:

- missing/duplicate titles & meta descriptions, keyword cannibalization
- heading issues, thin categories, stale posts, product image/alt/brand/GTIN/reviews
- orphans, weak hubs, dead internal links, unexpected noindex
- each finding includes `ai_action` for agent workflows
