<?php

declare(strict_types=1);

class MediaRepository
{
    private MediaMapper $mapper;

    public function __construct()
    {
        $this->mapper = new MediaMapper();
    }

    /**
     * همه فایل‌های رسانه
     */
    public function all(): array
    {
        $media = [];

        $query = new WP_Query([

            'post_type' => 'attachment',

            'post_status' => 'inherit',

            'posts_per_page' => -1,

            'orderby' => 'ID',

            'order' => 'ASC',

            'no_found_rows' => true,

            'cache_results' => false,

            'update_post_meta_cache' => true

        ]);

        while ($query->have_posts()) {

            $query->the_post();

            $attachment = get_post(get_the_ID());

            if (!$attachment instanceof WP_Post) {
                continue;
            }

            $media[] = $this->mapper->map($attachment);

        }

        wp_reset_postdata();

        return $media;
    }

    /**
     * دریافت یک فایل رسانه
     */
    public function find(int $id): ?array
    {
        $attachment = get_post($id);

        if (!$attachment instanceof WP_Post) {
            return null;
        }

        if ($attachment->post_type !== 'attachment') {
            return null;
        }

        return $this->mapper->map($attachment);
    }

    /**
     * تعداد فایل‌های رسانه
     */
    public function count(): int
    {
        $query = new WP_Query([

            'post_type' => 'attachment',

            'post_status' => 'inherit',

            'posts_per_page' => 1,

            'fields' => 'ids',

            'no_found_rows' => false

        ]);

        return (int) $query->found_posts;
    }

    /**
     * Generator
     */
    public function generator(): Generator
    {
        $query = new WP_Query([

            'post_type' => 'attachment',

            'post_status' => 'inherit',

            'posts_per_page' => -1,

            'orderby' => 'ID',

            'order' => 'ASC',

            'no_found_rows' => true,

            'cache_results' => false

        ]);

        while ($query->have_posts()) {

            $query->the_post();

            $attachment = get_post(get_the_ID());

            if (!$attachment instanceof WP_Post) {
                continue;
            }

            yield $this->mapper->map($attachment);

        }

        wp_reset_postdata();
    }

    /**
     * فقط تصاویر
     */
    public function images(): array
    {
        $result = [];

        foreach ($this->generator() as $media) {

            if (
                str_starts_with(
                    $media['basic']['mime_type'] ?? '',
                    'image/'
                )
            ) {

                $result[] = $media;

            }

        }

        return $result;
    }

    /**
     * فقط فایل‌های غیرتصویری
     */
    public function files(): array
    {
        $result = [];

        foreach ($this->generator() as $media) {

            if (
                !str_starts_with(
                    $media['basic']['mime_type'] ?? '',
                    'image/'
                )
            ) {

                $result[] = $media;

            }

        }

        return $result;
    }
}