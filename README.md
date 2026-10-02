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

1. ~~Content structure extraction (headings, internal links with anchors)~~ — branch `feat/content-structure-extraction`
2. Full SEO meta (Yoast + Rank Math)
3. Enriched internal link graph + orphan detection
4. `seo_audit.json` for AI agents

### Phase 1 entity shape

Each post/page/product/category now includes:

- `content.html_length`, Persian-aware `word_count` / `sentence_count` / `char_count`
- `structure.headings`, `internal_links` (url + anchor + target_post_id), `external_links`
- `structure.faq_candidates`, `lists_count`, `tables_count`, paragraph metrics

Products are also written to `output/markdown/products/{id}.md`.
