<?php

declare(strict_types=1);

class PostMapper
{
    private SeoMetaExtractor $seoMetaExtractor;
    private ContentPipeline $pipeline;

    public function __construct()
    {
        $this->seoMetaExtractor = new SeoMetaExtractor();
        $this->pipeline = new ContentPipeline();
    }

    public function map(WP_Post $post): array
    {
        $rawContent = (string) $post->post_content;
        $seo = $this->seoMetaExtractor->forPost($post->ID, 'category', [
            'title' => get_the_title($post),
            'description' => ai_clean_text($post->post_excerpt),
        ]);

        $processed = $this->pipeline->process(
            $rawContent,
            $seo,
            $post,
            ContentPipeline::modeFromConfig()
        );

        return [
            'basic' => $this->basic($post),
            'content' => $this->content($post, $rawContent, $processed['rendered_html']),
            'structure' => $processed['structure'],
            'taxonomy' => $this->taxonomy($post),
            'author' => $this->author($post),
            'media' => $this->media($post),
            'seo' => $processed['seo'],
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

    private function content(WP_Post $post, string $rawContent, string $renderedHtml): array
    {
        // Metrics from rendered plain text when richer, else raw
        $plainRendered = ai_clean_text($renderedHtml);
        $plainRaw = ai_clean_text($rawContent);
        $plain = mb_strlen($plainRendered, 'UTF-8') >= mb_strlen($plainRaw, 'UTF-8')
            ? $plainRendered
            : $plainRaw;
        $metrics = TextMetrics::analyze($plain);

        return [
            'excerpt' => ai_clean_text($post->post_excerpt),
            'content' => $plain,
            'html_length' => strlen($rawContent),
            'rendered_html_length' => strlen($renderedHtml),
            'word_count' => $metrics['word_count'],
            'sentence_count' => $metrics['sentence_count'],
            'char_count' => $metrics['char_count'],
        ];
    }

    private function taxonomy(WP_Post $post): array
    {
        return [
            'categories' => $this->mapTerms($post->ID, 'category'),
            'tags' => $this->mapTerms($post->ID, 'post_tag'),
        ];
    }

    /**
     * @return list<array{id:int,name:string,slug:string}>
     */
    private function mapTerms(int $postId, string $taxonomy): array
    {
        $terms = wp_get_post_terms($postId, $taxonomy);

        if (!is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $result = [];

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $result[] = [
                'id' => (int)$term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

        return $result;
    }

    private function author(WP_Post $post): array
    {
        $user = get_userdata($post->post_author);
        $authorId = (int)$post->post_author;

        return [
            'id' => $authorId,
            'name' => $user ? $user->display_name : '',
            'published_posts' => $authorId > 0
                ? (int) count_user_posts($authorId, 'post', true)
                : 0,
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

    private function customFields(WP_Post $post): array
    {
        return CustomFieldsFilter::filterMetaMap(get_post_meta($post->ID));
    }
}
