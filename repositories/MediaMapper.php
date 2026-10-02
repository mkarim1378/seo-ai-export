<?php

declare(strict_types=1);

class MediaMapper
{
    public function map(WP_Post $media): array
    {
        return [

            'basic' => $this->basic($media),

            'image' => $this->image($media),

            'author' => $this->author($media),

            'parent' => $this->parent($media),

            'seo' => $this->seo($media),

            'metadata' => $this->metadata($media),

            'custom_fields' => $this->customFields($media)

        ];
    }

    private function basic(WP_Post $media): array
    {
        return [

            'id' => $media->ID,

            'title' => $media->post_title,

            'slug' => $media->post_name,

            'status' => $media->post_status,

            'mime_type' => get_post_mime_type($media),

            'url' => wp_get_attachment_url($media->ID),

            'created_at' => $media->post_date,

            'updated_at' => $media->post_modified

        ];
    }

    private function image(WP_Post $media): array
    {
        $metadata = wp_get_attachment_metadata($media->ID);

        return [

            'alt' => get_post_meta(
                $media->ID,
                '_wp_attachment_image_alt',
                true
            ),

            'caption' => wp_get_attachment_caption($media->ID),

            'description' => ai_clean_text(
                $media->post_content
            ),

            'width' => $metadata['width'] ?? null,

            'height' => $metadata['height'] ?? null,

            'filesize' => filesize(
                get_attached_file($media->ID)
            ) ?: null

        ];
    }

    private function author(WP_Post $media): array
    {
        $user = get_userdata($media->post_author);

        return [

            'id' => $media->post_author,

            'name' => $user
                ? $user->display_name
                : ''

        ];
    }

    private function parent(WP_Post $media): array
    {
        if (!$media->post_parent) {

            return [

                'parent_id' => 0,

                'parent_type' => '',

                'parent_title' => ''

            ];

        }

        $parent = get_post($media->post_parent);

        return [

            'parent_id' => $media->post_parent,

            'parent_type' => $parent
                ? $parent->post_type
                : '',

            'parent_title' => $parent
                ? $parent->post_title
                : ''

        ];
    }

    private function seo(WP_Post $media): array
    {
        return (new SeoMetaExtractor())->forPost($media->ID, 'category');
    }

    private function metadata(WP_Post $media): array
    {
        return wp_get_attachment_metadata(
            $media->ID
        ) ?: [];
    }

    private function customFields(WP_Post $media): array
    {
        $meta = get_post_meta($media->ID);

        $result = [];

        foreach ($meta as $key => $values) {

            if (
                SeoMetaExtractor::shouldSkipPostMetaKey($key) ||
                $key === '_edit_lock' ||
                $key === '_edit_last' ||
                $key === '_wp_attachment_image_alt' ||
                $key === '_wp_attachment_metadata'
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