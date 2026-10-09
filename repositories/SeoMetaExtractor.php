<?php

declare(strict_types=1);

class SeoMetaExtractor
{
    public function forPost(
        int $postId,
        string $primaryTaxonomy = 'category',
        array $fallbacks = []
    ): array {
        $yoast = $this->yoastPost($postId, $primaryTaxonomy);
        $rankMath = $this->rankMathPost($postId, $primaryTaxonomy);

        return $this->finalize($this->merge($yoast, $rankMath), $fallbacks);
    }

    public function forTerm(int $termId, array $fallbacks = []): array
    {
        $yoast = $this->yoastTerm($termId);
        $rankMath = $this->rankMathTerm($termId);

        return $this->finalize($this->merge($yoast, $rankMath), $fallbacks);
    }

    /**
     * Meta keys already represented in the normalized seo block.
     *
     * @return list<string>
     */
    public static function postMetaKeysToSkip(): array
    {
        return [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_canonical',
            '_yoast_wpseo_focuskw',
            '_yoast_wpseo_focuskeywords',
            '_yoast_wpseo_meta-robots-noindex',
            '_yoast_wpseo_meta-robots-nofollow',
            '_yoast_wpseo_primary_category',
            '_yoast_wpseo_primary_product_cat',
            '_yoast_wpseo_bctitle',
            '_yoast_wpseo_opengraph-title',
            '_yoast_wpseo_opengraph-description',
            '_yoast_wpseo_opengraph-image',
            '_yoast_wpseo_opengraph-image-id',
            '_yoast_wpseo_twitter-title',
            '_yoast_wpseo_twitter-description',
            '_yoast_wpseo_twitter-image',
            'rank_math_title',
            'rank_math_description',
            'rank_math_canonical_url',
            'rank_math_focus_keyword',
            'rank_math_robots',
            'rank_math_primary_category',
            'rank_math_breadcrumb_title',
            'rank_math_facebook_title',
            'rank_math_facebook_description',
            'rank_math_facebook_image',
            'rank_math_twitter_title',
            'rank_math_twitter_description',
            'rank_math_twitter_image',
            'rank_math_twitter_use_facebook',
        ];
    }

    /**
     * @return list<string>
     */
    public static function termMetaKeysToSkip(): array
    {
        return [
            'wpseo_title',
            'wpseo_desc',
            'wpseo_canonical',
            'wpseo_focuskw',
            'wpseo_noindex',
            'wpseo_bctitle',
            'wpseo_opengraph-title',
            'wpseo_opengraph-description',
            'wpseo_opengraph-image',
            'wpseo_twitter-title',
            'wpseo_twitter-description',
            'rank_math_title',
            'rank_math_description',
            'rank_math_canonical_url',
            'rank_math_focus_keyword',
            'rank_math_robots',
            'rank_math_breadcrumb_title',
            'rank_math_facebook_title',
            'rank_math_facebook_description',
            'rank_math_facebook_image',
            'rank_math_twitter_title',
            'rank_math_twitter_description',
            'rank_math_twitter_image',
        ];
    }

    public static function shouldSkipPostMetaKey(string $key): bool
    {
        if (in_array($key, self::postMetaKeysToSkip(), true)) {
            return true;
        }

        return str_starts_with($key, '_yoast_wpseo_')
            || str_starts_with($key, 'rank_math_');
    }

    public static function shouldSkipTermMetaKey(string $key): bool
    {
        if (in_array($key, self::termMetaKeysToSkip(), true)) {
            return true;
        }

        return str_starts_with($key, 'wpseo_')
            || str_starts_with($key, 'rank_math_');
    }

    private function emptySeo(): array
    {
        return [
            'title' => '',
            'description' => '',
            'canonical' => '',
            'focus_keyword' => '',
            'secondary_keywords' => [],
            'robots' => [
                'index' => true,
                'follow' => true,
                'noarchive' => false,
                'nosnippet' => false,
                'noimageindex' => false,
            ],
            'primary_category' => null,
            'breadcrumb_title' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image' => '',
            'schema_types' => [],
            'is_cornerstone' => false,
            'resolved_title' => '',
            'resolved_description' => '',
            'title_length' => 0,
            'description_length' => 0,
            'plugin' => null,
            'sources' => [],
        ];
    }

    /**
     * @param array{title?:string,description?:string} $fallbacks
     */
    private function finalize(array $seo, array $fallbacks): array
    {
        $fallbackTitle = $this->stringify($fallbacks['title'] ?? '');
        $fallbackDescription = $this->stringify($fallbacks['description'] ?? '');

        $seoTitle = $this->stringify($seo['title'] ?? '');
        $seoDescription = $this->stringify($seo['description'] ?? '');

        $seo['resolved_title'] = $seoTitle !== '' ? $seoTitle : $fallbackTitle;
        $seo['resolved_description'] = $seoDescription !== '' ? $seoDescription : $fallbackDescription;
        $seo['title_length'] = mb_strlen($seo['resolved_title'], 'UTF-8');
        $seo['description_length'] = mb_strlen($seo['resolved_description'], 'UTF-8');

        if (!isset($seo['schema_types']) || !is_array($seo['schema_types'])) {
            $seo['schema_types'] = [];
        }

        $seo['schema_types'] = array_values(array_unique(array_filter(array_map(
            'strval',
            $seo['schema_types']
        ))));

        $seo['is_cornerstone'] = (bool)($seo['is_cornerstone'] ?? false);

        $robots = is_array($seo['robots'] ?? null) ? $seo['robots'] : [];
        $seo['robots'] = [
            'index' => (bool)($robots['index'] ?? true),
            'follow' => (bool)($robots['follow'] ?? true),
            'noarchive' => (bool)($robots['noarchive'] ?? false),
            'nosnippet' => (bool)($robots['nosnippet'] ?? false),
            'noimageindex' => (bool)($robots['noimageindex'] ?? false),
        ];

        return $seo;
    }

    private function merge(array $yoast, array $rankMath): array
    {
        if ($this->isRankMathActive()) {
            $primary = $rankMath;
            $fallback = $yoast;
            $primarySource = 'rank_math';
            $fallbackSource = 'yoast';
            $plugin = 'rank_math';
        } elseif ($this->isYoastActive()) {
            $primary = $yoast;
            $fallback = $rankMath;
            $primarySource = 'yoast';
            $fallbackSource = 'rank_math';
            $plugin = 'yoast';
        } elseif ($this->hasAnyValue($rankMath)) {
            $primary = $rankMath;
            $fallback = $yoast;
            $primarySource = 'rank_math';
            $fallbackSource = 'yoast';
            $plugin = 'rank_math';
        } elseif ($this->hasAnyValue($yoast)) {
            $primary = $yoast;
            $fallback = $rankMath;
            $primarySource = 'yoast';
            $fallbackSource = 'rank_math';
            $plugin = 'yoast';
        } else {
            $primary = $yoast;
            $fallback = $rankMath;
            $primarySource = 'yoast';
            $fallbackSource = 'rank_math';
            $plugin = null;
        }

        $result = $this->emptySeo();
        $sources = [];

        foreach ($this->scalarFields() as $field) {
            $result[$field] = $this->pickScalar(
                $primary[$field] ?? '',
                $fallback[$field] ?? '',
                $primarySource,
                $fallbackSource,
                $sources,
                $field
            );
        }

        $result['secondary_keywords'] = $this->pickList(
            $primary['secondary_keywords'] ?? [],
            $fallback['secondary_keywords'] ?? [],
            $primarySource,
            $fallbackSource,
            $sources,
            'secondary_keywords'
        );

        $result['robots'] = $this->pickRobots(
            $primary['robots'] ?? null,
            $fallback['robots'] ?? null,
            $primarySource,
            $fallbackSource,
            $sources
        );

        $result['primary_category'] = $this->pickPrimaryCategory(
            $primary['primary_category'] ?? null,
            $fallback['primary_category'] ?? null,
            $primarySource,
            $fallbackSource,
            $sources
        );

        $result['schema_types'] = $this->pickList(
            $primary['schema_types'] ?? [],
            $fallback['schema_types'] ?? [],
            $primarySource,
            $fallbackSource,
            $sources,
            'schema_types'
        );

        if (!empty($primary['is_cornerstone'])) {
            $result['is_cornerstone'] = true;
            $sources['is_cornerstone'] = $primarySource;
        } elseif (!empty($fallback['is_cornerstone'])) {
            $result['is_cornerstone'] = true;
            $sources['is_cornerstone'] = $fallbackSource;
        } else {
            $result['is_cornerstone'] = false;
        }

        $result['plugin'] = $plugin;
        $result['sources'] = $sources;

        return $result;
    }

    /**
     * @return list<string>
     */
    private function scalarFields(): array
    {
        return [
            'title',
            'description',
            'canonical',
            'focus_keyword',
            'breadcrumb_title',
            'og_title',
            'og_description',
            'og_image',
            'twitter_title',
            'twitter_description',
            'twitter_image',
        ];
    }

    private function pickScalar(
        mixed $primary,
        mixed $fallback,
        string $primarySource,
        string $fallbackSource,
        array &$sources,
        string $field
    ): string {
        $primary = $this->stringify($primary);
        $fallback = $this->stringify($fallback);

        if ($primary !== '') {
            $sources[$field] = $primarySource;
            return $primary;
        }

        if ($fallback !== '') {
            $sources[$field] = $fallbackSource;
            return $fallback;
        }

        return '';
    }

    private function pickList(
        array $primary,
        array $fallback,
        string $primarySource,
        string $fallbackSource,
        array &$sources,
        string $field
    ): array {
        $primary = array_values(array_filter(array_map('strval', $primary)));
        $fallback = array_values(array_filter(array_map('strval', $fallback)));

        if ($primary !== []) {
            $sources[$field] = $primarySource;
            return $primary;
        }

        if ($fallback !== []) {
            $sources[$field] = $fallbackSource;
            return $fallback;
        }

        return [];
    }

    private function pickRobots(
        ?array $primary,
        ?array $fallback,
        string $primarySource,
        string $fallbackSource,
        array &$sources
    ): array {
        $default = [
            'index' => true,
            'follow' => true,
            'noarchive' => false,
            'nosnippet' => false,
            'noimageindex' => false,
        ];

        if (is_array($primary) && $this->robotsIsExplicit($primary)) {
            $sources['robots'] = $primarySource;
            return $this->normalizeRobots($primary);
        }

        if (is_array($fallback) && $this->robotsIsExplicit($fallback)) {
            $sources['robots'] = $fallbackSource;
            return $this->normalizeRobots($fallback);
        }

        if (is_array($primary)) {
            $sources['robots'] = $primarySource;
            return $this->normalizeRobots($primary);
        }

        return $default;
    }

    private function normalizeRobots(array $robots): array
    {
        return [
            'index' => (bool)($robots['index'] ?? true),
            'follow' => (bool)($robots['follow'] ?? true),
            'noarchive' => (bool)($robots['noarchive'] ?? false),
            'nosnippet' => (bool)($robots['nosnippet'] ?? false),
            'noimageindex' => (bool)($robots['noimageindex'] ?? false),
        ];
    }

    private function robotsIsExplicit(array $robots): bool
    {
        return array_key_exists('index', $robots)
            || array_key_exists('follow', $robots)
            || array_key_exists('noarchive', $robots)
            || array_key_exists('nosnippet', $robots)
            || array_key_exists('noimageindex', $robots);
    }

    /**
     * @param list<string>|string $directives
     * @return array{index:bool,follow:bool,noarchive:bool,nosnippet:bool,noimageindex:bool}
     */
    private function robotsFromDirectives(array|string $directives, bool $defaultIndex = true, bool $defaultFollow = true): array
    {
        if (is_string($directives)) {
            $directives = preg_split('/\s*,\s*/', $directives) ?: [];
        }

        $directives = array_map(
            static fn($d): string => strtolower(trim((string)$d)),
            $directives
        );

        return [
            'index' => $defaultIndex && !in_array('noindex', $directives, true),
            'follow' => $defaultFollow && !in_array('nofollow', $directives, true),
            'noarchive' => in_array('noarchive', $directives, true),
            'nosnippet' => in_array('nosnippet', $directives, true),
            'noimageindex' => in_array('noimageindex', $directives, true),
        ];
    }

    private function pickPrimaryCategory(
        mixed $primary,
        mixed $fallback,
        string $primarySource,
        string $fallbackSource,
        array &$sources
    ): ?array {
        if (is_array($primary) && !empty($primary['id'])) {
            $sources['primary_category'] = $primarySource;
            return $primary;
        }

        if (is_array($fallback) && !empty($fallback['id'])) {
            $sources['primary_category'] = $fallbackSource;
            return $fallback;
        }

        return null;
    }

    private function yoastPost(int $postId, string $primaryTaxonomy): array
    {
        $focus = $this->metaString($postId, '_yoast_wpseo_focuskw');
        $secondaryRaw = get_post_meta($postId, '_yoast_wpseo_focuskeywords', true);
        $secondary = $this->parseYoastSecondaryKeywords($secondaryRaw, $focus);

        $noindex = get_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', true);
        $nofollow = get_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow', true);
        $adv = $this->metaString($postId, '_yoast_wpseo_meta-robots-adv');

        $robots = $this->robotsFromDirectives(
            $adv,
            !in_array((string)$noindex, ['1', 'yes'], true),
            !in_array((string)$nofollow, ['1', 'yes'], true)
        );

        $primaryKey = $primaryTaxonomy === 'product_cat'
            ? '_yoast_wpseo_primary_product_cat'
            : '_yoast_wpseo_primary_category';

        $schemaTypes = array_values(array_filter([
            $this->metaString($postId, '_yoast_wpseo_schema_page_type'),
            $this->metaString($postId, '_yoast_wpseo_schema_article_type'),
        ]));

        $cornerstone = get_post_meta($postId, '_yoast_wpseo_is_cornerstone', true);

        return [
            'title' => $this->metaString($postId, '_yoast_wpseo_title'),
            'description' => $this->metaString($postId, '_yoast_wpseo_metadesc'),
            'canonical' => $this->metaString($postId, '_yoast_wpseo_canonical'),
            'focus_keyword' => $focus,
            'secondary_keywords' => $secondary,
            'robots' => $robots,
            'primary_category' => $this->resolveTerm(
                (int)get_post_meta($postId, $primaryKey, true)
            ),
            'breadcrumb_title' => $this->metaString($postId, '_yoast_wpseo_bctitle'),
            'og_title' => $this->metaString($postId, '_yoast_wpseo_opengraph-title'),
            'og_description' => $this->metaString($postId, '_yoast_wpseo_opengraph-description'),
            'og_image' => $this->metaString($postId, '_yoast_wpseo_opengraph-image'),
            'twitter_title' => $this->metaString($postId, '_yoast_wpseo_twitter-title'),
            'twitter_description' => $this->metaString($postId, '_yoast_wpseo_twitter-description'),
            'twitter_image' => $this->metaString($postId, '_yoast_wpseo_twitter-image'),
            'schema_types' => $schemaTypes,
            'is_cornerstone' => in_array((string)$cornerstone, ['1', 'yes', 'true'], true),
        ];
    }

    private function rankMathPost(int $postId, string $_primaryTaxonomy = 'category'): array
    {
        $keywords = $this->parseRankMathKeywords(
            $this->metaString($postId, 'rank_math_focus_keyword')
        );
        $focus = $keywords[0] ?? '';
        $secondary = array_slice($keywords, 1);

        $robotsRaw = get_post_meta($postId, 'rank_math_robots', true);
        if (!is_array($robotsRaw)) {
            $robotsRaw = [];
        }
        $robots = $this->robotsFromDirectives($robotsRaw);

        $twitterTitle = $this->metaString($postId, 'rank_math_twitter_title');
        $twitterDescription = $this->metaString($postId, 'rank_math_twitter_description');
        $twitterImage = $this->metaString($postId, 'rank_math_twitter_image');
        $useFacebook = get_post_meta($postId, 'rank_math_twitter_use_facebook', true);

        $ogTitle = $this->metaString($postId, 'rank_math_facebook_title');
        $ogDescription = $this->metaString($postId, 'rank_math_facebook_description');
        $ogImage = $this->metaString($postId, 'rank_math_facebook_image');

        if ($useFacebook === 'on' || $useFacebook === '1' || $useFacebook === true) {
            if ($twitterTitle === '') {
                $twitterTitle = $ogTitle;
            }
            if ($twitterDescription === '') {
                $twitterDescription = $ogDescription;
            }
            if ($twitterImage === '') {
                $twitterImage = $ogImage;
            }
        }

        $snippet = $this->metaString($postId, 'rank_math_rich_snippet');
        $schemaTypes = $snippet !== '' ? [$snippet] : [];

        $pillar = get_post_meta($postId, 'rank_math_pillar_content', true);

        return [
            'title' => $this->metaString($postId, 'rank_math_title'),
            'description' => $this->metaString($postId, 'rank_math_description'),
            'canonical' => $this->metaString($postId, 'rank_math_canonical_url'),
            'focus_keyword' => $focus,
            'secondary_keywords' => $secondary,
            'robots' => $robots,
            'primary_category' => $this->resolveTerm(
                (int)get_post_meta($postId, 'rank_math_primary_category', true)
            ),
            'breadcrumb_title' => $this->metaString($postId, 'rank_math_breadcrumb_title'),
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'og_image' => $ogImage,
            'twitter_title' => $twitterTitle,
            'twitter_description' => $twitterDescription,
            'twitter_image' => $twitterImage,
            'schema_types' => $schemaTypes,
            'is_cornerstone' => in_array((string)$pillar, ['1', 'on', 'yes', 'true'], true),
        ];
    }

    private function yoastTerm(int $termId): array
    {
        $noindex = get_term_meta($termId, 'wpseo_noindex', true);

        return [
            'title' => $this->termMetaString($termId, 'wpseo_title'),
            'description' => $this->termMetaString($termId, 'wpseo_desc'),
            'canonical' => $this->termMetaString($termId, 'wpseo_canonical'),
            'focus_keyword' => $this->termMetaString($termId, 'wpseo_focuskw'),
            'secondary_keywords' => [],
            'robots' => $this->robotsFromDirectives(
                [],
                !in_array((string)$noindex, ['1', 'yes', 'noindex'], true),
                true
            ),
            'primary_category' => null,
            'breadcrumb_title' => $this->termMetaString($termId, 'wpseo_bctitle'),
            'og_title' => $this->termMetaString($termId, 'wpseo_opengraph-title'),
            'og_description' => $this->termMetaString($termId, 'wpseo_opengraph-description'),
            'og_image' => $this->termMetaString($termId, 'wpseo_opengraph-image'),
            'twitter_title' => $this->termMetaString($termId, 'wpseo_twitter-title'),
            'twitter_description' => $this->termMetaString($termId, 'wpseo_twitter-description'),
            'twitter_image' => '',
            'schema_types' => [],
            'is_cornerstone' => false,
        ];
    }

    private function rankMathTerm(int $termId): array
    {
        $keywords = $this->parseRankMathKeywords(
            $this->termMetaString($termId, 'rank_math_focus_keyword')
        );
        $focus = $keywords[0] ?? '';
        $secondary = array_slice($keywords, 1);

        $robotsRaw = get_term_meta($termId, 'rank_math_robots', true);
        if (!is_array($robotsRaw)) {
            $robotsRaw = [];
        }

        $snippet = $this->termMetaString($termId, 'rank_math_rich_snippet');

        return [
            'title' => $this->termMetaString($termId, 'rank_math_title'),
            'description' => $this->termMetaString($termId, 'rank_math_description'),
            'canonical' => $this->termMetaString($termId, 'rank_math_canonical_url'),
            'focus_keyword' => $focus,
            'secondary_keywords' => $secondary,
            'robots' => $this->robotsFromDirectives($robotsRaw),
            'primary_category' => null,
            'breadcrumb_title' => $this->termMetaString($termId, 'rank_math_breadcrumb_title'),
            'og_title' => $this->termMetaString($termId, 'rank_math_facebook_title'),
            'og_description' => $this->termMetaString($termId, 'rank_math_facebook_description'),
            'og_image' => $this->termMetaString($termId, 'rank_math_facebook_image'),
            'twitter_title' => $this->termMetaString($termId, 'rank_math_twitter_title'),
            'twitter_description' => $this->termMetaString($termId, 'rank_math_twitter_description'),
            'twitter_image' => $this->termMetaString($termId, 'rank_math_twitter_image'),
            'schema_types' => $snippet !== '' ? [$snippet] : [],
            'is_cornerstone' => false,
        ];
    }

    private function parseYoastSecondaryKeywords(mixed $raw, string $focus): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $keywords = [];

        foreach ($raw as $item) {
            if (is_array($item)) {
                $keyword = trim((string)($item['keyword'] ?? $item['key'] ?? ''));
            } else {
                $keyword = trim((string)$item);
            }

            if ($keyword === '' || mb_strtolower($keyword) === mb_strtolower($focus)) {
                continue;
            }

            $keywords[] = $keyword;
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @return list<string>
     */
    private function parseRankMathKeywords(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/u', $raw) ?: [];
        $keywords = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $keywords[] = $part;
            }
        }

        return array_values(array_unique($keywords));
    }

    private function resolveTerm(int $termId): ?array
    {
        if ($termId <= 0) {
            return null;
        }

        $term = get_term($termId);

        if (!$term instanceof WP_Term || is_wp_error($term)) {
            return [
                'id' => $termId,
                'name' => '',
                'slug' => '',
                'taxonomy' => '',
            ];
        }

        return [
            'id' => (int)$term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'taxonomy' => $term->taxonomy,
        ];
    }

    private function metaString(int $postId, string $key): string
    {
        return $this->stringify(get_post_meta($postId, $key, true));
    }

    private function termMetaString(int $termId, string $key): string
    {
        return $this->stringify(get_term_meta($termId, $key, true));
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string)$value);
    }

    private function hasAnyValue(array $seo): bool
    {
        foreach ($this->scalarFields() as $field) {
            if ($this->stringify($seo[$field] ?? '') !== '') {
                return true;
            }
        }

        if (!empty($seo['secondary_keywords'])) {
            return true;
        }

        if (!empty($seo['primary_category']['id'])) {
            return true;
        }

        $robots = $seo['robots'] ?? null;
        if (is_array($robots)) {
            if (($robots['index'] ?? true) === false || ($robots['follow'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    private function isYoastActive(): bool
    {
        return defined('WPSEO_VERSION')
            || class_exists('WPSEO_Meta')
            || function_exists('YoastSEO');
    }

    private function isRankMathActive(): bool
    {
        return defined('RANK_MATH_VERSION')
            || class_exists('RankMath')
            || function_exists('rank_math');
    }
}
