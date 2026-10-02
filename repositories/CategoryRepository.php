<?php

declare(strict_types=1);

class CategoryRepository
{
    private CategoryMapper $mapper;

    public function __construct()
    {
        $this->mapper = new CategoryMapper();
    }

    /**
     * همه دسته‌بندی‌های محصولات
     */
    public function all(): array
    {
        $categories = [];

        $terms = get_terms([

            'taxonomy' => 'product_cat',

            'hide_empty' => false,

            'orderby' => 'term_id',

            'order' => 'ASC'

        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        foreach ($terms as $term) {

            if (!$term instanceof WP_Term) {
                continue;
            }

            $categories[] = $this->mapper->map($term);

        }

        return $categories;
    }

    /**
     * دریافت یک دسته‌بندی
     */
    public function find(int $id): ?array
    {
        $term = get_term($id, 'product_cat');

        if (!$term instanceof WP_Term) {
            return null;
        }

        return $this->mapper->map($term);
    }

    /**
     * تعداد دسته‌بندی‌ها
     */
    public function count(): int
    {
        $terms = get_terms([

            'taxonomy' => 'product_cat',

            'hide_empty' => false,

            'fields' => 'ids'

        ]);

        if (is_wp_error($terms)) {
            return 0;
        }

        return count($terms);
    }

    /**
     * Generator
     */
    public function generator(): Generator
    {
        $terms = get_terms([

            'taxonomy' => 'product_cat',

            'hide_empty' => false,

            'orderby' => 'term_id',

            'order' => 'ASC'

        ]);

        if (is_wp_error($terms)) {
            return;
        }

        foreach ($terms as $term) {

            if (!$term instanceof WP_Term) {
                continue;
            }

            yield $this->mapper->map($term);

        }
    }

    /**
     * فقط دسته‌بندی‌های دارای محصول
     */
    public function notEmpty(): array
    {
        $result = [];

        foreach ($this->generator() as $category) {

            if (($category['basic']['count'] ?? 0) > 0) {

                $result[] = $category;

            }

        }

        return $result;
    }

    /**
     * فقط دسته‌بندی‌های بدون محصول
     */
    public function empty(): array
    {
        $result = [];

        foreach ($this->generator() as $category) {

            if (($category['basic']['count'] ?? 0) == 0) {

                $result[] = $category;

            }

        }

        return $result;
    }
}