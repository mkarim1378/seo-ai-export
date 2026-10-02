<?php

declare(strict_types=1);

class CategoryMapper
{
    public function map(WP_Term $term): array
    {
        return [

            'basic' => $this->basic($term),

            'content' => $this->content($term),

            'taxonomy' => $this->taxonomy($term),

            'seo' => $this->seo($term),

            'media' => $this->media($term),

            'custom_fields' => $this->customFields($term)

        ];
    }

    private function basic(WP_Term $term): array
    {
        return [

            'id' => $term->term_id,

            'name' => $term->name,

            'slug' => $term->slug,

            'url' => get_term_link($term),

            'count' => $term->count

        ];
    }

    private function content(WP_Term $term): array
    {
        return [

            'description' => ai_clean_text(
                term_description($term)
            )

        ];
    }

    private function taxonomy(WP_Term $term): array
    {
        $parentName = '';

        if ($term->parent) {

            $parent = get_term($term->parent);

            if ($parent instanceof WP_Term) {

                $parentName = $parent->name;

            }

        }

        return [

            'taxonomy' => $term->taxonomy,

            'parent_id' => $term->parent,

            'parent_name' => $parentName

        ];
    }

    private function seo(WP_Term $term): array
    {
        $id = $term->term_id;

        return [

            'title' => get_term_meta(
                $id,
                'wpseo_title',
                true
            ),

            'description' => get_term_meta(
                $id,
                'wpseo_desc',
                true
            ),

            'canonical' => get_term_meta(
                $id,
                'wpseo_canonical',
                true
            )

        ];
    }

    private function media(WP_Term $term): array
    {
        $thumbnail = get_term_meta(
            $term->term_id,
            'thumbnail_id',
            true
        );

        return [

            'thumbnail' => ai_attachment(
                (int)$thumbnail
            )

        ];
    }

    private function customFields(WP_Term $term): array
    {
        $meta = get_term_meta($term->term_id);

        $result = [];

        foreach ($meta as $key => $value) {

            $result[$key] = maybe_unserialize(
                $value[0] ?? ''
            );

        }

        return $result;
    }

}