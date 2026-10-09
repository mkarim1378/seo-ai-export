<?php

declare(strict_types=1);

class ProductMapper
{
    private ContentStructureExtractor $structureExtractor;
    private SeoMetaExtractor $seoMetaExtractor;

    public function __construct()
    {
        $this->structureExtractor = new ContentStructureExtractor();
        $this->seoMetaExtractor = new SeoMetaExtractor();
    }

    public function map(WC_Product $product): array
    {
        $rawDescription = (string) $product->get_description();
        $rawShort = (string) $product->get_short_description();

        return [
            'basic' => $this->basic($product),
            'content' => $this->content($rawDescription, $rawShort),
            'structure' => $this->structureExtractor->merge(
                $this->structureExtractor->extract($rawShort),
                $this->structureExtractor->extract($rawDescription)
            ),
            'pricing' => $this->pricing($product),
            'inventory' => $this->inventory($product),
            'shipping' => $this->shipping($product),
            'taxonomy' => $this->taxonomy($product),
            'attributes' => $this->attributes($product),
            'identifiers' => $this->identifiers($product),
            'variations' => $this->variations($product),
            'reviews' => $this->reviews($product),
            'breadcrumb' => $this->breadcrumb($product),
            'media' => $this->media($product),
            'seo' => $this->seoMetaExtractor->forPost(
                $product->get_id(),
                'product_cat',
                [
                    'title' => $product->get_name(),
                    'description' => ai_clean_text((string)$product->get_short_description()),
                ]
            ),
            'relations' => $this->relations($product),
            'ratings' => $this->ratings($product),
        ];
    }

    private function basic(WC_Product $product): array
    {
        return [
            'id' => $product->get_id(),
            'type' => $product->get_type(),
            'status' => get_post_status($product->get_id()),
            'title' => $product->get_name(),
            'slug' => $product->get_slug(),
            'sku' => $product->get_sku(),
            'url' => get_permalink($product->get_id()),
            'total_sales' => (int) $product->get_total_sales(),
            'stock_status' => (string) $product->get_stock_status(),
            'created_at' => $product->get_date_created()
                ? $product->get_date_created()->date('Y-m-d H:i:s')
                : '',
            'updated_at' => $product->get_date_modified()
                ? $product->get_date_modified()->date('Y-m-d H:i:s')
                : '',
        ];
    }

    private function content(string $rawDescription, string $rawShort): array
    {
        $description = ai_clean_text($rawDescription);
        $short = ai_clean_text($rawShort);
        $combined = trim($short . ' ' . $description);
        $metrics = TextMetrics::analyze($combined);

        return [
            'short_description' => $short,
            'description' => $description,
            'html_length' => strlen($rawShort) + strlen($rawDescription),
            'word_count' => $metrics['word_count'],
            'sentence_count' => $metrics['sentence_count'],
            'char_count' => $metrics['char_count'],
        ];
    }

    private function pricing(WC_Product $product): array
    {
        return [
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'on_sale' => $product->is_on_sale(),
        ];
    }

    private function inventory(WC_Product $product): array
    {
        return [
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'backorders' => $product->get_backorders(),
            'sold_individually' => $product->is_sold_individually(),
        ];
    }

    private function shipping(WC_Product $product): array
    {
        return [
            'weight' => $product->get_weight(),
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
            'shipping_class' => $product->get_shipping_class(),
        ];
    }

    private function taxonomy(WC_Product $product): array
    {
        return [
            'categories' => $this->mapTerms($product->get_id(), 'product_cat'),
            'tags' => $this->mapTerms($product->get_id(), 'product_tag'),
        ];
    }

    /**
     * @return list<array{id:int,name:string,slug:string}>
     */
    private function mapTerms(int $productId, string $taxonomy): array
    {
        $terms = wp_get_post_terms($productId, $taxonomy);

        if (!is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $result = [];

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $result[] = [
                'id' => (int)$term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

        return $result;
    }

    private function attributes(WC_Product $product): array
    {
        $result = [];

        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) {
                $values = wc_get_product_terms(
                    $product->get_id(),
                    $attribute->get_name(),
                    ['fields' => 'names']
                );
            } else {
                $values = $attribute->get_options();
            }

            $result[] = [
                'name' => wc_attribute_label(
                    $attribute->get_name()
                ),
                'slug' => $attribute->get_name(),
                'values' => $values,
            ];
        }

        return $result;
    }

    private function identifiers(WC_Product $product): array
    {
        $id = $product->get_id();

        return [
            'brand' => $this->firstNonEmpty([
                $this->attributeValue($product, ['pa_brand', 'brand', 'pa_برند', 'برند']),
                $this->metaString($id, '_brand'),
                $this->metaString($id, 'brand'),
                $this->metaString($id, '_wc_brand'),
                $this->metaString($id, 'rank_math_snippet_product_brand'),
            ]),
            'gtin' => $this->firstNonEmpty([
                $this->metaString($id, '_global_unique_id'),
                $this->metaString($id, '_gtin'),
                $this->metaString($id, 'gtin'),
                $this->metaString($id, '_wpm_gtin_code'),
                $this->metaString($id, '_alg_ean'),
                $this->attributeValue($product, ['pa_gtin', 'gtin', 'pa_ean', 'ean']),
            ]),
            'ean' => $this->firstNonEmpty([
                $this->metaString($id, '_ean'),
                $this->metaString($id, 'ean'),
                $this->metaString($id, '_alg_ean'),
                $this->attributeValue($product, ['pa_ean', 'ean']),
            ]),
            'mpn' => $this->firstNonEmpty([
                $this->metaString($id, '_mpn'),
                $this->metaString($id, 'mpn'),
                $this->metaString($id, '_wc_mpn'),
                $this->attributeValue($product, ['pa_mpn', 'mpn']),
            ]),
        ];
    }

    private function variations(WC_Product $product): array
    {
        if (!$product->is_type('variable')) {
            return [];
        }

        /** @var WC_Product_Variable $product */
        $result = [];

        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product((int)$variationId);

            if (!$variation instanceof WC_Product_Variation) {
                continue;
            }

            $attrs = [];
            foreach ($variation->get_attributes() as $key => $value) {
                $attrs[str_replace('attribute_', '', (string)$key)] = $value;
            }

            $result[] = [
                'id' => $variation->get_id(),
                'sku' => $variation->get_sku(),
                'attributes' => $attrs,
                'price' => $variation->get_price(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
                'stock_status' => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'url' => get_permalink($variation->get_id()),
                'gtin' => $this->firstNonEmpty([
                    $this->metaString($variation->get_id(), '_global_unique_id'),
                    $this->metaString($variation->get_id(), '_gtin'),
                    $this->metaString($variation->get_id(), '_wpm_gtin_code'),
                ]),
            ];
        }

        return $result;
    }

    private function reviews(WC_Product $product): array
    {
        $comments = get_comments([
            'post_id' => $product->get_id(),
            'status' => 'approve',
            'type' => 'review',
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
            'number' => 0,
        ]);

        $result = [];

        foreach ($comments as $comment) {
            $result[] = [
                'id' => (int)$comment->comment_ID,
                'rating' => (int)get_comment_meta($comment->comment_ID, 'rating', true),
                'author' => $comment->comment_author,
                'date' => $comment->comment_date,
                'content' => ai_clean_text($comment->comment_content),
            ];
        }

        return $result;
    }

    private function breadcrumb(WC_Product $product): array
    {
        $terms = wp_get_post_terms($product->get_id(), 'product_cat');

        if (is_wp_error($terms) || $terms === []) {
            return [
                'path' => [],
                'trail' => $product->get_name(),
            ];
        }

        $deepest = $terms[0];
        $maxDepth = $this->termDepth($deepest);

        foreach ($terms as $term) {
            $depth = $this->termDepth($term);
            if ($depth > $maxDepth) {
                $maxDepth = $depth;
                $deepest = $term;
            }
        }

        $path = [];
        $current = $deepest;

        while ($current instanceof WP_Term) {
            array_unshift($path, [
                'id' => (int)$current->term_id,
                'name' => $current->name,
                'slug' => $current->slug,
                'url' => get_term_link($current),
            ]);

            if (!$current->parent) {
                break;
            }

            $parent = get_term($current->parent, 'product_cat');
            $current = $parent instanceof WP_Term ? $parent : null;
        }

        $names = array_column($path, 'name');
        $names[] = $product->get_name();

        return [
            'path' => $path,
            'trail' => implode(' > ', $names),
        ];
    }

    private function termDepth(WP_Term $term): int
    {
        $depth = 0;
        $current = $term;

        while ($current instanceof WP_Term && $current->parent) {
            $depth++;
            $parent = get_term($current->parent, $current->taxonomy);
            if (!$parent instanceof WP_Term) {
                break;
            }
            $current = $parent;
        }

        return $depth;
    }

    private function media(WC_Product $product): array
    {
        $gallery = [];

        foreach ($product->get_gallery_image_ids() as $id) {
            $gallery[] = ai_attachment((int)$id);
        }

        return [
            'featured' => ai_attachment(
                (int)$product->get_image_id()
            ),
            'gallery' => $gallery,
        ];
    }

    private function relations(WC_Product $product): array
    {
        return [
            'upsells' => $product->get_upsell_ids(),
            'cross_sells' => $product->get_cross_sell_ids(),
        ];
    }

    private function ratings(WC_Product $product): array
    {
        return [
            'average' => $product->get_average_rating(),
            'review_count' => $product->get_review_count(),
            'rating_count' => $product->get_rating_counts(),
        ];
    }

    private function attributeValue(WC_Product $product, array $slugs): string
    {
        foreach ($slugs as $slug) {
            if ($product->get_attribute($slug)) {
                $value = ai_clean_text($product->get_attribute($slug));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function metaString(int $postId, string $key): string
    {
        $value = get_post_meta($postId, $key, true);

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string)$value);
    }

    /**
     * @param list<string> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
