<?php

declare(strict_types=1);

class ProductMetaExtractor
{
    public function extract(int $productId): array
    {
        $filtered = CustomFieldsFilter::filterMetaMap(get_post_meta($productId));

        foreach (array_keys($filtered) as $key) {
            if ($this->shouldSkipCommerceKey((string)$key)) {
                unset($filtered[$key]);
            }
        }

        return $filtered;
    }

    private function shouldSkipCommerceKey(string $key): bool
    {
        static $skip = [
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

        return in_array($key, $skip, true);
    }
}
