<?php

declare(strict_types=1);

/**
 * Drops noisy builder/plugin meta that bloats exports and misleads AI consultants.
 * SEO plugin scores remain excluded via SeoMetaExtractor prefix skips.
 */
class CustomFieldsFilter
{
    private const MAX_STRING_LENGTH = 2000;

    /**
     * Exact meta keys that are operational noise or already represented elsewhere.
     *
     * @var list<string>
     */
    private const EXACT_SKIP = [
        '_edit_lock',
        '_edit_last',
        '_thumbnail_id',
        '_wp_page_template',
        '_wp_attachment_image_alt',
        '_wp_attachment_metadata',
        '_wp_attached_file',
        '_wp_desired_post_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_encloseme',
        '_pingme',
        'footnotes',
    ];

    /**
     * Prefixes for page builders, embeds, and other high-noise plugin meta.
     *
     * @var list<string>
     */
    private const PREFIX_SKIP = [
        '_elementor',
        'elementor',
        '_oembed',
        '_wp_old_',
        '_vc_',
        'vc_',
        '_wpb_',
        'wpb_',
        '_et_',
        'et_',
        '_fusion',
        'fusion_',
        '_uag',
        'uag_',
        '_generate-',
        'nebula_',
        '_thrive',
        'thrive_',
        '_fl_builder',
        'fl_builder',
        '_oxygen',
        'ct_',
        '_bricks',
        '_acf_changed',
        'rs_',
        'revslider',
        '_yoast_indexation',
        '_wpseo_scan',
    ];

    public static function shouldSkipKey(string $key, bool $isTerm = false): bool
    {
        if ($isTerm) {
            if (SeoMetaExtractor::shouldSkipTermMetaKey($key)) {
                return true;
            }
        } elseif (SeoMetaExtractor::shouldSkipPostMetaKey($key)) {
            return true;
        }

        if (in_array($key, self::EXACT_SKIP, true)) {
            return true;
        }

        $lower = strtolower($key);

        foreach (self::PREFIX_SKIP as $prefix) {
            if (str_starts_with($lower, strtolower($prefix))) {
                return true;
            }
        }

        // Private WP keys that are usually machine state, not consulting signal.
        if (str_starts_with($key, '_wp_') && !in_array($key, ['_wp_page_template'], true)) {
            // Already covered many; keep unknown short _wp_ out too if huge prefix patterns miss.
            if (preg_match('/^_wp_(old_|desired_|trash_|attached_|attachment_)/', $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $meta Raw get_post_meta / get_term_meta style map
     * @return array<string, mixed>
     */
    public static function filterMetaMap(array $meta, bool $isTerm = false): array
    {
        $result = [];

        foreach ($meta as $key => $values) {
            $key = (string)$key;

            if (self::shouldSkipKey($key, $isTerm)) {
                continue;
            }

            $raw = is_array($values) ? ($values[0] ?? '') : $values;
            $value = maybe_unserialize($raw);
            $normalized = self::normalizeValue($value);

            if ($normalized === null) {
                continue;
            }

            $result[$key] = $normalized;
        }

        return $result;
    }

    private static function normalizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 3) {
            return null;
        }

        if (is_object($value)) {
            $value = json_decode(wp_json_encode($value), true);
            if (!is_array($value)) {
                return null;
            }
        }

        if (is_array($value)) {
            if (count($value) > 40) {
                return null;
            }

            $out = [];
            foreach ($value as $k => $v) {
                $normalized = self::normalizeValue($v, $depth + 1);
                if ($normalized === null) {
                    continue;
                }
                $out[$k] = $normalized;
            }

            return $out === [] ? null : $out;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $clean = function_exists('ai_clean_text')
                ? ai_clean_text($value)
                : trim($value);

            if ($clean === '') {
                return null;
            }

            if (mb_strlen($clean, 'UTF-8') > self::MAX_STRING_LENGTH) {
                return null;
            }

            // Serialized leftovers / binary-ish noise
            if (str_starts_with($clean, 'a:') || str_starts_with($clean, 'O:')) {
                return null;
            }

            return $clean;
        }

        return null;
    }
}
