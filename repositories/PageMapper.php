<?php

declare(strict_types=1);

class PageMapper
{
    public function map(WP_Post $page): array
    {
        return [

            'basic' => $this->basic($page),

            'content' => $this->content($page),

            'parent' => $this->parent($page),

            'author' => $this->author($page),

            'media' => $this->media($page),

            'seo' => $this->seo($page),

            'custom_fields' => $this->customFields($page)

        ];
    }

    private function basic(WP_Post $page): array
    {
        return [

            'id' => $page->ID,

            'title' => get_the_title($page),

            'slug' => $page->post_name,

            'status' => $page->post_status,

            'menu_order' => $page->menu_order,

            'url' => get_permalink($page),

            'created_at' => $page->post_date,

            'updated_at' => $page->post_modified

        ];
    }

    private function content(WP_Post $page): array
    {
        return [

            'excerpt' => ai_clean_text($page->post_excerpt),

            'content' => ai_clean_text($page->post_content),

            'word_count' => str_word_count(
                wp_strip_all_tags($page->post_content)
            )

        ];
    }

    private function parent(WP_Post $page): array
    {
        if (!$page->post_parent) {

            return [

                'parent_id' => 0,

                'parent_title' => ''

            ];

        }

        $parent = get_post($page->post_parent);

        return [

            'parent_id' => $page->post_parent,

            'parent_title' => $parent
                ? $parent->post_title
                : ''

        ];
    }

    private function author(WP_Post $page): array
    {
        $user = get_userdata($page->post_author);

        return [

            'id' => $page->post_author,

            'name' => $user
                ? $user->display_name
                : ''

        ];
    }

    private function media(WP_Post $page): array
    {
        return [

            'featured' => ai_attachment(
                get_post_thumbnail_id($page->ID)
            )

        ];
    }

    private function seo(WP_Post $page): array
    {
        return [

            'title' => get_post_meta(
                $page->ID,
                '_yoast_wpseo_title',
                true
            ),

            'description' => get_post_meta(
                $page->ID,
                '_yoast_wpseo_metadesc',
                true
            ),

            'canonical' => get_post_meta(
                $page->ID,
                '_yoast_wpseo_canonical',
                true
            ),

            'focus_keyword' => get_post_meta(
                $page->ID,
                '_yoast_wpseo_focuskw',
                true
            )

        ];
    }

    private function customFields(WP_Post $page): array
    {
        $meta = get_post_meta($page->ID);

        $result = [];

        foreach ($meta as $key => $values) {

            if (
                str_starts_with($key, '_yoast_') ||
                $key === '_edit_lock' ||
                $key === '_edit_last'
            ) {
                continue;
            }

            $result[$key] = maybe_unserialize(
                $values[0] ?? ''
            );

        }

        return $result;
    }
}