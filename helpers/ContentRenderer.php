<?php

declare(strict_types=1);

/**
 * Best-effort content rendering for structure extraction.
 * Gutenberg: do_blocks. Optional the_content (heavier; can run shortcodes).
 * Elementor and other page-builder meta stores are NOT rendered — documented limitation.
 */
class ContentRenderer
{
    /**
     * @param 'off'|'blocks'|'the_content' $mode
     * @return array{
     *   html: string,
     *   mode: string,
     *   source: string,
     *   note: string|null
     * }
     */
    public function render(string $rawHtml, ?WP_Post $post = null, string $mode = 'blocks'): array
    {
        $rawHtml = (string)$rawHtml;
        $mode = in_array($mode, ['off', 'blocks', 'the_content'], true) ? $mode : 'blocks';

        if ($mode === 'off' || trim($rawHtml) === '') {
            return [
                'html' => $rawHtml,
                'mode' => 'off',
                'source' => 'post_content_raw',
                'note' => $mode === 'off'
                    ? 'Content rendering disabled in config.'
                    : null,
            ];
        }

        if ($mode === 'the_content' && $post instanceof WP_Post && function_exists('apply_filters')) {
            $rendered = $this->renderViaTheContent($post, $rawHtml);
            if ($rendered !== null) {
                return [
                    'html' => $rendered,
                    'mode' => 'the_content',
                    'source' => 'the_content_filter',
                    'note' => 'Rendered via apply_filters(the_content). Page-builder content stored only in meta (e.g. Elementor) may still be missing.',
                ];
            }
        }

        if (function_exists('do_blocks') && function_exists('has_blocks') && has_blocks($rawHtml)) {
            $html = (string) do_blocks($rawHtml);
            if (function_exists('do_shortcode')) {
                $html = (string) do_shortcode($html);
            }

            return [
                'html' => $html,
                'mode' => 'blocks',
                'source' => 'do_blocks',
                'note' => 'Block markup expanded via do_blocks. Elementor/meta builders are not rendered.',
            ];
        }

        // Non-block content: still expand shortcodes when possible
        if (function_exists('do_shortcode') && str_contains($rawHtml, '[')) {
            return [
                'html' => (string) do_shortcode($rawHtml),
                'mode' => 'shortcodes',
                'source' => 'do_shortcode',
                'note' => 'Shortcodes expanded; not full the_content pipeline.',
            ];
        }

        return [
            'html' => $rawHtml,
            'mode' => 'raw',
            'source' => 'post_content_raw',
            'note' => 'No block markup detected; using stored post_content as-is.',
        ];
    }

    private function renderViaTheContent(WP_Post $post, string $rawHtml): ?string
    {
        try {
            $previous = $GLOBALS['post'] ?? null;
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            $GLOBALS['post'] = $post;

            if (function_exists('setup_postdata')) {
                setup_postdata($post);
            }

            $html = (string) apply_filters('the_content', $rawHtml);

            if (function_exists('wp_reset_postdata')) {
                wp_reset_postdata();
            }

            if ($previous !== null) {
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                $GLOBALS['post'] = $previous;
            } else {
                unset($GLOBALS['post']);
            }

            return $html !== '' ? $html : null;
        } catch (Throwable $e) {
            if (function_exists('wp_reset_postdata')) {
                wp_reset_postdata();
            }

            return null;
        }
    }
}
