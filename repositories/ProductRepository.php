<?php

declare(strict_types=1);

class ProductRepository
{
    private ProductMapper $mapper;

    private ProductMetaExtractor $metaExtractor;

    public function __construct()
    {
        $this->mapper = new ProductMapper();

        $this->metaExtractor = new ProductMetaExtractor();
    }

    /**
     * همه محصولات
     */
    public function all(): array
    {
        $products = [];

        $query = new WP_Query([

            'post_type' => 'product',

            'post_status' => [
                'publish',
                'private',
                'draft'
            ],

            'posts_per_page' => -1,

            'orderby' => 'ID',

            'order' => 'ASC',

            'no_found_rows' => true,

            'cache_results' => false,

            'update_post_meta_cache' => true,

            'update_post_term_cache' => true

        ]);

        while ($query->have_posts()) {

            $query->the_post();

            $product = wc_get_product(get_the_ID());

            if (!$product instanceof WC_Product) {
                continue;
            }

            $products[] = $this->build($product);

        }

        wp_reset_postdata();

        return $products;
    }

    /**
     * یک محصول بر اساس ID
     */
    public function find(int $id): ?array
    {
        $product = wc_get_product($id);

        if (!$product instanceof WC_Product) {
            return null;
        }

        return $this->build($product);
    }

    /**
     * ساخت Knowledge Object
     */
    private function build(WC_Product $product): array
    {
        $knowledge = $this->mapper->map($product);

        $knowledge['custom_fields'] =

            $this->metaExtractor->extract(
                $product->get_id()
            );

        return $knowledge;
    }

    /**
     * تعداد محصولات
     */
    public function count(): int
    {
        $count = wp_count_posts('product');

        return (int)($count->publish ?? 0);
    }

    /**
     * خروجی Generator
     * مناسب سایت‌های بزرگ
     */
    public function generator(): Generator
    {
        $query = new WP_Query([

            'post_type' => 'product',

            'post_status' => [
                'publish',
                'private',
                'draft'
            ],

            'posts_per_page' => -1,

            'orderby' => 'ID',

            'order' => 'ASC',

            'no_found_rows' => true,

            'cache_results' => false

        ]);

        while ($query->have_posts()) {

            $query->the_post();

            $product = wc_get_product(get_the_ID());

            if (!$product instanceof WC_Product) {
                continue;
            }

            yield $this->build($product);

        }

        wp_reset_postdata();
    }

    /**
     * فقط محصولات منتشر شده
     */
    public function published(): array
    {
        $result = [];

        foreach ($this->generator() as $product) {

            if (($product['basic']['status'] ?? '') === 'publish') {

                $result[] = $product;

            }

        }

        return $result;
    }

    /**
     * فقط محصولات موجود
     */
    public function inStock(): array
    {
        $result = [];

        foreach ($this->generator() as $product) {

            if (($product['inventory']['stock_status'] ?? '') === 'instock') {

                $result[] = $product;

            }

        }

        return $result;
    }

    /**
     * فقط محصولات دارای تخفیف
     */
    public function onSale(): array
    {
        $result = [];

        foreach ($this->generator() as $product) {

            if (!empty($product['pricing']['on_sale'])) {

                $result[] = $product;

            }

        }

        return $result;
    }

}