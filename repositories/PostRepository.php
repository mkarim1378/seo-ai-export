<?php

declare(strict_types=1);

class PostRepository
{
    private PostMapper $mapper;

    public function __construct()
    {
        $this->mapper = new PostMapper();
    }

    /**
     * همه نوشته‌ها
     */
    public function all(): array
    {
        $posts = [];

        $query = new WP_Query([

            'post_type' => 'post',

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

            $post = get_post(get_the_ID());

            if (!$post instanceof WP_Post) {
                continue;
            }

            $posts[] = $this->mapper->map($post);

        }

        wp_reset_postdata();

        return $posts;
    }

    /**
     * دریافت یک نوشته
     */
    public function find(int $id): ?array
    {
        $post = get_post($id);

        if (!$post instanceof WP_Post) {
            return null;
        }

        if ($post->post_type !== 'post') {
            return null;
        }

        return $this->mapper->map($post);
    }

    /**
     * تعداد نوشته‌ها
     */
    public function count(): int
    {
        $count = wp_count_posts('post');

        return (int) ($count->publish ?? 0);
    }

    /**
     * Generator
     */
    public function generator(): Generator
    {
        $query = new WP_Query([

            'post_type' => 'post',

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

            $post = get_post(get_the_ID());

            if (!$post instanceof WP_Post) {
                continue;
            }

            yield $this->mapper->map($post);

        }

        wp_reset_postdata();
    }

    /**
     * فقط نوشته‌های منتشرشده
     */
    public function published(): array
    {
        $result = [];

        foreach ($this->generator() as $post) {

            if (($post['basic']['status'] ?? '') === 'publish') {

                $result[] = $post;

            }

        }

        return $result;
    }

    /**
     * فقط نوشته‌های دارای تصویر شاخص
     */
    public function withFeaturedImage(): array
    {
        $result = [];

        foreach ($this->generator() as $post) {

            if (!empty($post['media']['featured']['id'])) {

                $result[] = $post;

            }

        }

        return $result;
    }

    /**
     * فقط نوشته‌های بدون تصویر شاخص
     */
    public function withoutFeaturedImage(): array
    {
        $result = [];

        foreach ($this->generator() as $post) {

            if (empty($post['media']['featured']['id'])) {

                $result[] = $post;

            }

        }

        return $result;
    }
}