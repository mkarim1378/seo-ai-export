<?php

declare(strict_types=1);

class CategoryMapper
{
    private ContentStructureExtractor $structureExtractor;
    private SeoMetaExtractor $seoMetaExtractor;

    public function __construct()
    {
        $this->structureExtractor = new ContentStructureExtractor();
        $this->seoMetaExtractor = new SeoMetaExtractor();
    }

    public function map(WP_Term $term): array
    {
        $rawDescription = (string) ($term->description ?? '');

        return [
            'basic' => $this->basic($term),
            'content' => $this->content($rawDescription),
            'structure' => $this->structureExtractor->extract($rawDescription),
            'taxonomy' => $this->taxonomy($term),
            'seo' => $this->seoMetaExtractor->forTerm($term->term_id),
            'media' => $this->media($term),
            'custom_fields' => $this->customFields($term),
        ];
    }

    private function basic(WP_Term $term): array
    {
        return [
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'url' => get_term_link($term),
            'count' => $term->count,
            'parent' => (int)$term->parent,
        ];
    }

    private function content(string $rawDescription): array
    {
        $plain = ai_clean_text($rawDescription);
        $metrics = TextMetrics::analyze($plain);

        return [
            'description' => $plain,
            'html_length' => strlen($rawDescription),
            'word_count' => $metrics['word_count'],
            'sentence_count' => $metrics['sentence_count'],
            'char_count' => $metrics['char_count'],
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
            'parent_name' => $parentName,
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
            ),
        ];
    }

    private function customFields(WP_Term $term): array
    {
        return CustomFieldsFilter::filterMetaMap(
            get_term_meta($term->term_id),
            true
        );
    }
}
