<?php

declare(strict_types=1);

class PageMapper
{
    private ContentStructureExtractor $structureExtractor;
    private SeoMetaExtractor $seoMetaExtractor;

    public function __construct()
    {
        $this->structureExtractor = new ContentStructureExtractor();
        $this->seoMetaExtractor = new SeoMetaExtractor();
    }

    public function map(WP_Post $page): array
    {
        $rawContent = (string) $page->post_content;

        return [
            'basic' => $this->basic($page),
            'content' => $this->content($page, $rawContent),
            'structure' => $this->structureExtractor->extract($rawContent),
            'parent' => $this->parent($page),
            'author' => $this->author($page),
            'media' => $this->media($page),
            'seo' => $this->seoMetaExtractor->forPost($page->ID, 'category'),
            'custom_fields' => $this->customFields($page),
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
            'updated_at' => $page->post_modified,
        ];
    }

    private function content(WP_Post $page, string $rawContent): array
    {
        $plain = ai_clean_text($rawContent);
        $metrics = TextMetrics::analyze($plain);

        return [
            'excerpt' => ai_clean_text($page->post_excerpt),
            'content' => $plain,
            'html_length' => strlen($rawContent),
            'word_count' => $metrics['word_count'],
            'sentence_count' => $metrics['sentence_count'],
            'char_count' => $metrics['char_count'],
        ];
    }

    private function parent(WP_Post $page): array
    {
        if (!$page->post_parent) {
            return [
                'parent_id' => 0,
                'parent_title' => '',
            ];
        }

        $parent = get_post($page->post_parent);

        return [
            'parent_id' => $page->post_parent,
            'parent_title' => $parent
                ? $parent->post_title
                : '',
        ];
    }

    private function author(WP_Post $page): array
    {
        $user = get_userdata($page->post_author);

        return [
            'id' => $page->post_author,
            'name' => $user
                ? $user->display_name
                : '',
        ];
    }

    private function media(WP_Post $page): array
    {
        return [
            'featured' => ai_attachment(
                get_post_thumbnail_id($page->ID)
            ),
        ];
    }

    private function customFields(WP_Post $page): array
    {
        $meta = get_post_meta($page->ID);
        $result = [];

        foreach ($meta as $key => $values) {
            if (
                SeoMetaExtractor::shouldSkipPostMetaKey($key) ||
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
