<?php

declare(strict_types=1);

/**
 * Shared post/page/product pipeline: render HTML → structure + schema detection.
 */
class ContentPipeline
{
    private ContentRenderer $renderer;
    private ContentStructureExtractor $structure;
    private SchemaExtractor $schema;

    public function __construct()
    {
        $this->renderer = new ContentRenderer();
        $this->structure = new ContentStructureExtractor();
        $this->schema = new SchemaExtractor();
    }

    /**
     * @param 'off'|'blocks'|'the_content' $mode
     * @return array{
     *   structure: array<string,mixed>,
     *   content_render: array<string,mixed>,
     *   seo: array<string,mixed>,
     *   rendered_html: string
     * }
     */
    public function process(string $rawHtml, array $seo, ?WP_Post $post = null, string $mode = 'blocks'): array
    {
        $render = $this->renderer->render($rawHtml, $post, $mode);
        $html = (string)($render['html'] ?? $rawHtml);

        $structure = $this->structure->extract($html);
        $structure['content_render'] = [
            'mode' => $render['mode'] ?? 'raw',
            'source' => $render['source'] ?? 'post_content_raw',
            'note' => $render['note'] ?? null,
        ];

        $detected = $this->schema->extractFromHtml($html);
        // Also scan raw in case rendering stripped script tags
        if ($detected['types'] === [] && $html !== $rawHtml) {
            $fromRaw = $this->schema->extractFromHtml($rawHtml);
            if ($fromRaw['types'] !== []) {
                $detected = $fromRaw;
                $detected['note'] = 'JSON-LD found in raw post_content (not in rendered HTML).';
            }
        }

        $claimed = $seo['schema_types'] ?? [];
        if (!is_array($claimed)) {
            $claimed = [];
        }

        $comparison = $this->schema->compare($claimed, $detected['types']);

        $seo['schema_types'] = $comparison['claimed'];
        $seo['schema_detected'] = $comparison['detected'];
        $seo['schema_detected_blocks'] = (int)($detected['count'] ?? 0);
        $seo['schema_claimed_only'] = !empty($comparison['schema_claimed_only']);
        $seo['schema_missing_in_content'] = $comparison['missing_in_content'];
        $seo['schema_note'] = $comparison['note'];

        return [
            'structure' => $structure,
            'content_render' => $structure['content_render'],
            'seo' => $seo,
            'rendered_html' => $html,
        ];
    }

    /**
     * Resolve render mode from constant (set in index.php) or optional config array.
     *
     * @return 'off'|'blocks'|'the_content'
     */
    public static function modeFromConfig(?array $config = null): string
    {
        if (defined('AI_EXPORTER_CONTENT_RENDER')) {
            $mode = (string) AI_EXPORTER_CONTENT_RENDER;
        } elseif (is_array($config)) {
            $mode = (string)($config['content_render'] ?? 'blocks');
        } else {
            $mode = 'blocks';
        }

        return in_array($mode, ['off', 'blocks', 'the_content'], true) ? $mode : 'blocks';
    }
}
