<?php

declare(strict_types=1);

/**
 * Site-level technical/crawl context for AI consulting.
 * Theme/WP versions are labeled as technical context, not ranking signals.
 */
class SiteProfileBuilder
{
    public function build(): array
    {
        $home = (string) home_url('/');
        $parts = wp_parse_url($home) ?: [];
        $sitemaps = $this->sitemaps();

        return [
            'version' => '1.1',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'identity' => [
                'name' => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'url' => rtrim($home, '/') . '/',
                'language' => get_locale(),
            ],
            'technical_context' => [
                'note' => 'Platform/theme versions are environment context only — not SEO ranking scores.',
                'wordpress' => get_bloginfo('version'),
                'woocommerce' => class_exists('WooCommerce') ? (string) WC()->version : null,
                'theme' => wp_get_theme()->get('Name'),
                'theme_version' => wp_get_theme()->get('Version'),
            ],
            'crawl' => [
                'blog_public' => (string) get_option('blog_public'),
                'search_engine_visibility' => ((string) get_option('blog_public')) !== '0',
                'permalink_structure' => (string) get_option('permalink_structure'),
                'https' => (!empty($parts['scheme']) && strtolower((string)$parts['scheme']) === 'https'),
                'host' => (string) ($parts['host'] ?? ''),
                'sitemaps' => $sitemaps,
                'robots_txt' => $this->robotsTxt(),
                'robots_txt_source' => 'wordpress_robots_txt_filter',
            ],
            'special_pages' => [
                'show_on_front' => (string) get_option('show_on_front'),
                'front_page' => $this->pageRef((int) get_option('page_on_front')),
                'posts_page' => $this->pageRef((int) get_option('page_for_posts')),
                'woocommerce' => $this->wooPages(),
            ],
            'seo_plugins' => $this->seoPlugins(),
            'seo_plugin_globals' => $this->seoPluginGlobals(),
        ];
    }

    /**
     * @return list<array{url:string,reachable:bool|null}>
     */
    private function sitemaps(): array
    {
        $urls = [
            home_url('/wp-sitemap.xml'),
        ];

        if ($this->isYoastActive() || $this->isRankMathActive()) {
            $urls[] = home_url('/sitemap_index.xml');
        }

        if ($this->isYoastActive()) {
            $urls[] = home_url('/sitemap.xml');
        }

        $urls = array_values(array_unique(array_filter($urls)));
        $out = [];

        foreach ($urls as $url) {
            $out[] = [
                'url' => $url,
                'reachable' => $this->probeUrl($url),
            ];
        }

        return $out;
    }

    private function probeUrl(string $url): ?bool
    {
        if (!function_exists('wp_remote_head')) {
            return null;
        }

        $response = wp_remote_head($url, [
            'timeout' => 5,
            'redirection' => 2,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return $code >= 200 && $code < 400;
    }

    private function robotsTxt(): string
    {
        $public = (string) get_option('blog_public');
        $output = "User-agent: *\n";

        if ($public === '0') {
            $output .= "Disallow: /\n";
        } else {
            $output .= "Disallow:\n";
        }

        if (function_exists('apply_filters')) {
            $output = (string) apply_filters('robots_txt', $output, $public !== '0');
        }

        $output = trim($output);

        if (mb_strlen($output, 'UTF-8') > 4000) {
            return mb_substr($output, 0, 4000, 'UTF-8') . "\n…";
        }

        return $output;
    }

    /**
     * Global noindex / CPT visibility from Yoast or Rank Math options.
     *
     * @return array<string,mixed>
     */
    private function seoPluginGlobals(): array
    {
        if ($this->isRankMathActive()) {
            return $this->rankMathGlobals();
        }

        if ($this->isYoastActive()) {
            return $this->yoastGlobals();
        }

        return [
            'plugin' => null,
            'noindex_types' => [],
            'note' => 'No Yoast/Rank Math globals detected.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function yoastGlobals(): array
    {
        $titles = get_option('wpseo_titles', []);
        if (!is_array($titles)) {
            $titles = [];
        }

        $noindex = [];
        $map = [
            'noindex-post' => 'post',
            'noindex-page' => 'page',
            'noindex-product' => 'product',
            'noindex-tax-category' => 'category',
            'noindex-tax-product_cat' => 'product_cat',
            'noindex-tax-post_tag' => 'post_tag',
            'noindex-tax-product_tag' => 'product_tag',
            'noindex-author' => 'author',
            'noindex-archive-product' => 'product_archive',
        ];

        foreach ($map as $optionKey => $type) {
            if (!empty($titles[$optionKey])) {
                $noindex[$type] = true;
            }
        }

        return [
            'plugin' => 'yoast',
            'noindex_types' => $noindex,
            'raw_keys_checked' => array_keys($map),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rankMathGlobals(): array
    {
        $titles = get_option('rank-math-options-titles', []);
        if (!is_array($titles)) {
            $titles = [];
        }

        $noindex = [];
        $candidates = [
            'pt_post_robots' => 'post',
            'pt_page_robots' => 'page',
            'pt_product_robots' => 'product',
            'tax_category_robots' => 'category',
            'tax_product_cat_robots' => 'product_cat',
            'tax_post_tag_robots' => 'post_tag',
            'tax_product_tag_robots' => 'product_tag',
        ];

        foreach ($candidates as $key => $type) {
            $val = $titles[$key] ?? null;
            if (is_array($val) && in_array('noindex', $val, true)) {
                $noindex[$type] = true;
            } elseif (is_string($val) && str_contains(strtolower($val), 'noindex')) {
                $noindex[$type] = true;
            }
        }

        return [
            'plugin' => 'rank_math',
            'noindex_types' => $noindex,
            'raw_keys_checked' => array_keys($candidates),
        ];
    }

    /**
     * @return array{id:int,title:string,url:string}|null
     */
    private function pageRef(int $pageId): ?array
    {
        if ($pageId <= 0) {
            return null;
        }

        $post = get_post($pageId);
        if (!$post instanceof WP_Post) {
            return [
                'id' => $pageId,
                'title' => '',
                'url' => (string) get_permalink($pageId),
            ];
        }

        return [
            'id' => $pageId,
            'title' => get_the_title($post),
            'url' => (string) get_permalink($post),
        ];
    }

    /**
     * @return array<string, array{id:int,title:string,url:string}|null>
     */
    private function wooPages(): array
    {
        if (!function_exists('wc_get_page_id')) {
            return [
                'shop' => null,
                'cart' => null,
                'checkout' => null,
                'myaccount' => null,
            ];
        }

        return [
            'shop' => $this->pageRef((int) wc_get_page_id('shop')),
            'cart' => $this->pageRef((int) wc_get_page_id('cart')),
            'checkout' => $this->pageRef((int) wc_get_page_id('checkout')),
            'myaccount' => $this->pageRef((int) wc_get_page_id('myaccount')),
        ];
    }

    /**
     * @return list<array{name:string,version:string|null,active:bool}>
     */
    private function seoPlugins(): array
    {
        $plugins = [];

        if ($this->isYoastActive()) {
            $plugins[] = [
                'name' => 'yoast',
                'version' => defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : null,
                'active' => true,
            ];
        }

        if ($this->isRankMathActive()) {
            $plugins[] = [
                'name' => 'rank_math',
                'version' => defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : null,
                'active' => true,
            ];
        }

        return $plugins;
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
