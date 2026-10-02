<?php

declare(strict_types=1);

class PageRepository
{
    private PageMapper $mapper;

    public function __construct()
    {
        $this->mapper = new PageMapper();
    }

    /**
     * همه صفحات
     */
    public function all(): array
    {
        $pages = [];

        $query = new WP_Query([

            'post_type' => 'page',

            'post_status' => [
                'publish',
                'private',
                'draft'
            ],

            'posts_per_page' => -1,

            'orderby' => 'menu_order title',

            'order' => 'ASC',

            'no_found_rows' => true,

            'cache_results' => false,

            'update_post_meta_cache' => true

        ]);

        while ($query->have_posts()) {

            $query->the_post();

            $page = get_post(get_the_ID());

            if (!$page instanceof WP_Post) {
                continue;
            }

            $pages[] = $this->mapper->map($page);

        }

        wp_reset_postdata();

        return $pages;
    }

    /**
     * دریافت یک صفحه
     */
    public function find(int $id): ?array
    {
        $page = get_post($id);

        if (!$page instanceof WP_Post) {
            return null;
        }

        if ($page->post_type !== 'page') {
            return null;
        }

        return $this->mapper->map($page);
    }

    /**
     * تعداد صفحات
     */
    public function count(): int
    {
        $count = wp_count_posts('page');

        return (int) ($count->publish ?? 0);
    }

    /**
     * Generator
     */
    public function generator(): Generator
    {
        $query = new WP_Query([

            'post_type' => 'page',

            'post_status' => [
                'publish',
                'private',
                'draft'
            ],

            'posts_per_page' => -1,

            'orderby' => 'menu_order title',

            'order' => 'ASC',

            'no_found_rows' => true,

            'cache_results' => false

        ]);

        while ($query->have_posts()) {

            $query->the_post();

            $page = get_post(get_the_ID());

            if (!$page instanceof WP_Post) {
                continue;
            }

            yield $this->mapper->map($page);

        }

        wp_reset_postdata();
    }

    /**
     * فقط صفحات منتشر شده
     */
    public function published(): array
    {
        $result = [];

        foreach ($this->generator() as $page) {

            if (($page['basic']['status'] ?? '') === 'publish') {

                $result[] = $page;

            }

        }

        return $result;
    }

    /**
     * صفحات دارای والد
     */
    public function children(): array
    {
        $result = [];

        foreach ($this->generator() as $page) {

            if (($page['parent']['parent_id'] ?? 0) > 0) {

                $result[] = $page;

            }

        }

        return $result;
    }

    /**
     * صفحات ریشه
     */
    public function roots(): array
    {
        $result = [];

        foreach ($this->generator() as $page) {

            if (($page['parent']['parent_id'] ?? 0) === 0) {

                $result[] = $page;

            }

        }

        return $result;
    }
}