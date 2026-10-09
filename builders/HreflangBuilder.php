<?php

declare(strict_types=1);

/**
 * Detect multilingual / hreflang setup from Polylang, WPML, or SEO plugins.
 */
class HreflangBuilder
{
    private const MAX_MAP = 500;

    public function build(array $knowledge = []): array
    {
        $providers = [];
        $languages = [];
        $pairs = [];

        if ($this->isPolylang()) {
            $providers[] = 'polylang';
            $pll = $this->fromPolylang($knowledge);
            $languages = array_merge($languages, $pll['languages']);
            $pairs = array_merge($pairs, $pll['pairs']);
        }

        if ($this->isWpml()) {
            $providers[] = 'wpml';
            $wpml = $this->fromWpml($knowledge);
            $languages = array_merge($languages, $wpml['languages']);
            $pairs = array_merge($pairs, $wpml['pairs']);
        }

        $siteLocale = function_exists('get_locale') ? (string) get_locale() : '';
        $languages[] = [
            'code' => $siteLocale,
            'name' => $siteLocale,
            'source' => 'wordpress_locale',
            'is_default' => true,
        ];

        // Dedupe languages by code
        $byCode = [];
        foreach ($languages as $lang) {
            $code = (string)($lang['code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (!isset($byCode[$code])) {
                $byCode[$code] = $lang;
            }
        }

        $pairs = array_slice($pairs, 0, self::MAX_MAP);

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'Hreflang/language map from Polylang/WPML when active. Not a live HTML alternate-link crawl.',
            'enabled' => $providers !== [],
            'providers' => $providers,
            'default_locale' => $siteLocale,
            'languages' => array_values($byCode),
            'summary' => [
                'language_count' => count($byCode),
                'translation_pair_count' => count($pairs),
                'providers' => $providers,
            ],
            'translation_pairs' => $pairs,
            'gaps' => $this->gaps($knowledge, $pairs, $providers),
        ];
    }

    private function isPolylang(): bool
    {
        return function_exists('pll_languages_list')
            || function_exists('pll_get_post_translations')
            || defined('POLYLANG_VERSION');
    }

    private function isWpml(): bool
    {
        return defined('ICL_SITEPRESS_VERSION')
            || defined('WPML_VERSION')
            || has_action('wpml_loaded');
    }

    /**
     * @return array{languages: list<array<string,mixed>>, pairs: list<array<string,mixed>>}
     */
    private function fromPolylang(array $knowledge): array
    {
        $languages = [];
        $pairs = [];

        if (function_exists('pll_languages_list')) {
            $codes = pll_languages_list(['fields' => 'slug']);
            $names = function_exists('pll_languages_list')
                ? pll_languages_list(['fields' => 'name'])
                : [];
            if (is_array($codes)) {
                foreach ($codes as $i => $code) {
                    $languages[] = [
                        'code' => (string)$code,
                        'name' => (string)($names[$i] ?? $code),
                        'source' => 'polylang',
                        'is_default' => function_exists('pll_default_language')
                            && (string)pll_default_language() === (string)$code,
                    ];
                }
            }
        }

        if (!function_exists('pll_get_post_translations')) {
            return compact('languages', 'pairs');
        }

        foreach (['posts' => 'post', 'pages' => 'page', 'products' => 'product'] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $id = (int)($entity['basic']['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $translations = pll_get_post_translations($id);
                if (!is_array($translations) || count($translations) < 2) {
                    continue;
                }
                $map = [];
                foreach ($translations as $lang => $tid) {
                    $tid = (int)$tid;
                    $url = $tid > 0 && function_exists('get_permalink')
                        ? (string) get_permalink($tid)
                        : '';
                    $map[(string)$lang] = [
                        'id' => $tid,
                        'url' => $url,
                    ];
                }
                $pairs[] = [
                    'entity_type' => $type,
                    'source_id' => $id,
                    'source_url' => (string)($entity['basic']['url'] ?? ''),
                    'translations' => $map,
                    'provider' => 'polylang',
                ];
                if (count($pairs) >= self::MAX_MAP) {
                    break 2;
                }
            }
        }

        return compact('languages', 'pairs');
    }

    /**
     * @return array{languages: list<array<string,mixed>>, pairs: list<array<string,mixed>>}
     */
    private function fromWpml(array $knowledge): array
    {
        $languages = [];
        $pairs = [];

        $active = apply_filters('wpml_active_languages', null);
        if (is_array($active)) {
            foreach ($active as $code => $info) {
                $languages[] = [
                    'code' => (string)$code,
                    'name' => (string)($info['native_name'] ?? $info['translated_name'] ?? $code),
                    'source' => 'wpml',
                    'is_default' => !empty($info['default_locale']) || !empty($info['active']),
                    'url' => (string)($info['url'] ?? ''),
                ];
            }
        }

        foreach (['posts' => 'post', 'pages' => 'page', 'products' => 'product'] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $id = (int)($entity['basic']['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $trid = apply_filters('wpml_element_trid', null, $id, 'post_' . $type);
                if (!$trid) {
                    continue;
                }
                $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_' . $type);
                if (!is_array($translations) || count($translations) < 2) {
                    continue;
                }
                $map = [];
                foreach ($translations as $lang => $obj) {
                    $tid = (int)($obj->element_id ?? $obj['element_id'] ?? 0);
                    $url = $tid > 0 ? (string) get_permalink($tid) : '';
                    $map[(string)$lang] = [
                        'id' => $tid,
                        'url' => $url,
                    ];
                }
                $pairs[] = [
                    'entity_type' => $type,
                    'source_id' => $id,
                    'source_url' => (string)($entity['basic']['url'] ?? ''),
                    'translations' => $map,
                    'provider' => 'wpml',
                ];
                if (count($pairs) >= self::MAX_MAP) {
                    break 2;
                }
            }
        }

        return compact('languages', 'pairs');
    }

    /**
     * @param list<array<string,mixed>> $pairs
     * @param list<string> $providers
     * @return list<array<string,mixed>>
     */
    private function gaps(array $knowledge, array $pairs, array $providers): array
    {
        if ($providers === []) {
            return [[
                'type' => 'no_multilingual_plugin',
                'recommendation' => 'No Polylang/WPML detected — hreflang map is locale-only.',
            ]];
        }

        $covered = [];
        foreach ($pairs as $pair) {
            $covered[($pair['entity_type'] ?? '') . ':' . ($pair['source_id'] ?? 0)] = true;
            foreach ($pair['translations'] ?? [] as $tr) {
                $covered[($pair['entity_type'] ?? '') . ':' . ($tr['id'] ?? 0)] = true;
            }
        }

        $gaps = [];
        $checked = 0;
        foreach (['posts' => 'post', 'pages' => 'page', 'products' => 'product'] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $status = (string)($entity['basic']['status'] ?? '');
                if ($status !== '' && $status !== 'publish') {
                    continue;
                }
                $id = (int)($entity['basic']['id'] ?? 0);
                $key = $type . ':' . $id;
                if (isset($covered[$key])) {
                    continue;
                }
                $gaps[] = [
                    'type' => 'missing_translation_group',
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'url' => (string)($entity['basic']['url'] ?? ''),
                    'title' => (string)($entity['basic']['title'] ?? ''),
                    'recommendation' => 'Published URL has no translation group in the multilingual plugin.',
                ];
                $checked++;
                if ($checked >= 40) {
                    break 2;
                }
            }
        }

        return $gaps;
    }
}
