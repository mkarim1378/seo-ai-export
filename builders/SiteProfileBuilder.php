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

        return [
            'version' => '1.0',
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
                'sitemaps' => $this->sitemaps(),
                'robots_txt' => $this->robotsTxt(),
            ],
            'special_pages' => [
                'show_on_front' => (string) get_option('show_on_front'),
                'front_page' => $this->pageRef((int) get_option('page_on_front')),
                'posts_page' => $this->pageRef((int) get_option('page_for_posts')),
                'woocommerce' => $this->wooPages(),
            ],
            'seo_plugins' => $this->seoPlugins(),
        ];
    }

    /**
     * @return list<string>
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

        return array_values(array_unique(array_filter($urls)));
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
