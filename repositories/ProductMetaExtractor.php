<?php

declare(strict_types=1);

class ProductMetaExtractor
{
    public function extract(int $productId): array
    {
        $meta = get_post_meta($productId);

        $result = [];

        foreach ($meta as $key => $values) {

            if ($this->shouldSkip($key)) {
                continue;
            }

            $value = maybe_unserialize($values[0] ?? '');

            if (is_array($value)) {
                $value = $this->normalizeArray($value);
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function shouldSkip(string $key): bool
    {
        static $skip = [
            '_edit_lock',
            '_edit_last',
            '_thumbnail_id',
            '_price',
            '_regular_price',
            '_sale_price',
            '_stock',
            '_stock_status',
            '_manage_stock',
            '_backorders',
            '_sku',
            '_virtual',
            '_downloadable',
            '_download_limit',
            '_download_expiry',
            '_downloadable_files',
            '_upsell_ids',
            '_crosssell_ids',
            '_product_attributes',
            '_default_attributes',
            '_product_image_gallery',
            '_wc_average_rating',
            '_wc_rating_count',
            '_wc_review_count',
            '_brand',
            'brand',
            '_wc_brand',
            '_gtin',
            'gtin',
            '_ean',
            'ean',
            '_mpn',
            'mpn',
            '_wc_mpn',
            '_global_unique_id',
            '_wpm_gtin_code',
            '_alg_ean',
        ];

        if (in_array($key, $skip, true)) {
            return true;
        }

        return SeoMetaExtractor::shouldSkipPostMetaKey($key);
    }

    private function normalizeArray(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {

            if (is_array($value)) {
                $result[$key] = $this->normalizeArray($value);
                continue;
            }

            if (is_object($value)) {
                $result[$key] = json_decode(
                    wp_json_encode($value),
                    true
                );
                continue;
            }

            if (is_bool($value)) {
                $result[$key] = $value;
                continue;
            }

            $result[$key] = is_string($value)
                ? ai_clean_text($value)
                : $value;
        }

        return $result;
    }
}
