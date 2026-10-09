<?php

declare(strict_types=1);

class PageMapper
{
    private SeoMetaExtractor $seoMetaExtractor;
    private ContentPipeline $pipeline;

    public function __construct()
    {
        $this->seoMetaExtractor = new SeoMetaExtractor();
        $this->pipeline = new ContentPipeline();
    }

    public function map(WP_Post $page): array
    {
        $rawContent = (string) $page->post_content;
        $seo = $this->seoMetaExtractor->forPost($page->ID, 'category', [
            'title' => get_the_title($page),
            'description' => ai_clean_text($page->post_excerpt),
        ]);

        $processed = $this->pipeline->process(
            $rawContent,
            $seo,
            $page,
            ContentPipeline::modeFromConfig()
        );

        return [
            'basic' => $this->basic($page),
            'content' => $this->content($page, $rawContent, $processed['rendered_html']),
            'structure' => $processed['structure'],
            'parent' => $this->parent($page),
            'author' => $this->author($page),
            'media' => $this->media($page),
            'seo' => $processed['seo'],
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

    private function content(WP_Post $page, string $rawContent, string $renderedHtml): array
    {
        $plainRendered = ai_clean_text($renderedHtml);
        $plainRaw = ai_clean_text($rawContent);
        $plain = mb_strlen($plainRendered, 'UTF-8') >= mb_strlen($plainRaw, 'UTF-8')
            ? $plainRendered
            : $plainRaw;
        $metrics = TextMetrics::analyze($plain);

        return [
            'excerpt' => ai_clean_text($page->post_excerpt),
            'content' => $plain,
            'html_length' => strlen($rawContent),
            'rendered_html_length' => strlen($renderedHtml),
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
        $authorId = (int)$page->post_author;

        return [
            'id' => $authorId,
            'name' => $user
                ? $user->display_name
                : '',
            'published_pages' => $authorId > 0
                ? (int) count_user_posts($authorId, 'page', true)
                : 0,
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
        return CustomFieldsFilter::filterMetaMap(get_post_meta($page->ID));
    }
}
