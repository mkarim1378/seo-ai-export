<?php

declare(strict_types=1);

class PostMapper
{
    private ContentStructureExtractor $structureExtractor;

    public function __construct()
    {
        $this->structureExtractor = new ContentStructureExtractor();
    }

    public function map(WP_Post $post): array
    {
        $rawContent = (string) $post->post_content;

        return [
            'basic' => $this->basic($post),
            'content' => $this->content($post, $rawContent),
            'structure' => $this->structureExtractor->extract($rawContent),
            'taxonomy' => $this->taxonomy($post),
            'author' => $this->author($post),
            'media' => $this->media($post),
            'seo' => $this->seo($post),
            'custom_fields' => $this->customFields($post),
        ];
    }

    private function basic(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'url' => get_permalink($post),
            'created_at' => $post->post_date,
            'updated_at' => $post->post_modified,
        ];
    }

    private function content(WP_Post $post, string $rawContent): array
    {
        $plain = ai_clean_text($rawContent);
        $metrics = TextMetrics::analyze($plain);

        return [
            'excerpt' => ai_clean_text($post->post_excerpt),
            'content' => $plain,
            'html_length' => strlen($rawContent),
            'word_count' => $metrics['word_count'],
            'sentence_count' => $metrics['sentence_count'],
            'char_count' => $metrics['char_count'],
        ];
    }

    private function taxonomy(WP_Post $post): array
    {
        return [
            'categories' => wp_get_post_terms(
                $post->ID,
                'category',
                ['fields' => 'names']
            ),
            'tags' => wp_get_post_terms(
                $post->ID,
                'post_tag',
                ['fields' => 'names']
            ),
        ];
    }

    private function author(WP_Post $post): array
    {
        $user = get_userdata($post->post_author);

        return [
            'id' => $post->post_author,
            'name' => $user ? $user->display_name : '',
        ];
    }

    private function media(WP_Post $post): array
    {
        return [
            'featured' => ai_attachment(
                get_post_thumbnail_id($post->ID)
            ),
        ];
    }

    private function seo(WP_Post $post): array
    {
        return [
            'title' => get_post_meta(
                $post->ID,
                '_yoast_wpseo_title',
                true
            ),
            'description' => get_post_meta(
                $post->ID,
                '_yoast_wpseo_metadesc',
                true
            ),
            'canonical' => get_post_meta(
                $post->ID,
                '_yoast_wpseo_canonical',
                true
            ),
            'focus_keyword' => get_post_meta(
                $post->ID,
                '_yoast_wpseo_focuskw',
                true
            ),
        ];
    }

    private function customFields(WP_Post $post): array
    {
        $meta = get_post_meta($post->ID);
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
