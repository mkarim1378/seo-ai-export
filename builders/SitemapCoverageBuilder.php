<?php

declare(strict_types=1);

/**
 * Fetch site sitemap URL lists and compare with exported published URLs.
 */
class SitemapCoverageBuilder
{
    private const MAX_URLS = 5000;
    private const MAX_CHILD_SITEMAPS = 30;

    public function build(array $knowledge, array $siteProfile = []): array
    {
        $sitemapEntries = $siteProfile['crawl']['sitemaps'] ?? [];
        $sitemapUrls = [];
        foreach ($sitemapEntries as $entry) {
            if (is_array($entry)) {
                $sitemapUrls[] = (string)($entry['url'] ?? '');
            } elseif (is_string($entry)) {
                $sitemapUrls[] = $entry;
            }
        }
        $sitemapUrls = array_values(array_unique(array_filter($sitemapUrls)));

        $collected = [];
        $fetched = [];
        $errors = [];

        foreach ($sitemapUrls as $sitemapUrl) {
            $result = $this->fetchSitemapUrls($sitemapUrl, 0);
            $fetched[] = [
                'url' => $sitemapUrl,
                'ok' => $result['ok'],
                'url_count' => count($result['urls']),
                'error' => $result['error'],
            ];
            if ($result['error'] !== null) {
                $errors[] = ['sitemap' => $sitemapUrl, 'error' => $result['error']];
            }
            foreach ($result['urls'] as $u) {
                $collected[$this->normalizeUrl($u)] = $u;
                if (count($collected) >= self::MAX_URLS) {
                    break 2;
                }
            }
        }

        $exported = $this->exportedUrlMap($knowledge);
        $inSitemapNotExported = [];
        $inExportNotSitemap = [];

        foreach ($collected as $norm => $original) {
            if (!isset($exported[$norm])) {
                $inSitemapNotExported[] = $original;
            }
        }

        foreach ($exported as $norm => $meta) {
            if ($collected !== [] && !isset($collected[$norm])) {
                $inExportNotSitemap[] = [
                    'url' => $meta['url'],
                    'entity_type' => $meta['entity_type'],
                    'entity_id' => $meta['entity_id'],
                    'title' => $meta['title'],
                ];
            }
        }

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'Compares sitemap XML URLs with exported published entities. Caps apply; child sitemaps limited. Not a full crawl budget analysis.',
            'sitemaps_checked' => $fetched,
            'summary' => [
                'sitemap_url_count' => count($collected),
                'exported_url_count' => count($exported),
                'in_sitemap_not_exported' => count($inSitemapNotExported),
                'in_export_not_sitemap' => count($inExportNotSitemap),
                'fetch_errors' => count($errors),
                'coverage_pct' => count($exported) > 0 && $collected !== []
                    ? (int)round(
                        (count($exported) - count($inExportNotSitemap)) / count($exported) * 100
                    )
                    : null,
            ],
            'in_sitemap_not_exported' => array_slice($inSitemapNotExported, 0, 100),
            'in_export_not_sitemap' => array_slice($inExportNotSitemap, 0, 100),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{ok:bool,urls:list<string>,error:string|null}
     */
    private function fetchSitemapUrls(string $url, int $depth): array
    {
        if ($url === '' || !function_exists('wp_remote_get')) {
            return ['ok' => false, 'urls' => [], 'error' => 'wp_remote_get unavailable or empty URL'];
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 3,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'urls' => [], 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return ['ok' => false, 'urls' => [], 'error' => 'HTTP ' . $code];
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return ['ok' => false, 'urls' => [], 'error' => 'empty body'];
        }

        $urls = [];

        // sitemap index
        if (stripos($body, '<sitemapindex') !== false && $depth < 1) {
            if (preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $body, $m)) {
                $childCount = 0;
                foreach ($m[1] as $loc) {
                    $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($loc === '') {
                        continue;
                    }
                    $child = $this->fetchSitemapUrls($loc, $depth + 1);
                    foreach ($child['urls'] as $u) {
                        $urls[] = $u;
                        if (count($urls) >= self::MAX_URLS) {
                            break 2;
                        }
                    }
                    $childCount++;
                    if ($childCount >= self::MAX_CHILD_SITEMAPS) {
                        break;
                    }
                }
            }

            return ['ok' => true, 'urls' => $urls, 'error' => null];
        }

        // urlset
        if (preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $body, $m)) {
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($loc === '') {
                    continue;
                }
                $urls[] = $loc;
                if (count($urls) >= self::MAX_URLS) {
                    break;
                }
            }
        }

        return ['ok' => true, 'urls' => $urls, 'error' => null];
    }

    /**
     * @return array<string,array{url:string,entity_type:string,entity_id:int,title:string}>
     */
    private function exportedUrlMap(array $knowledge): array
    {
        $map = [];
        foreach ([
            'products' => 'product',
            'posts' => 'post',
            'pages' => 'page',
            'categories' => 'category',
        ] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $status = (string)($entity['basic']['status'] ?? 'publish');
                if ($status !== '' && $status !== 'publish' && $type !== 'category') {
                    continue;
                }
                $url = (string)($entity['basic']['url'] ?? '');
                $norm = $this->normalizeUrl($url);
                if ($norm === '') {
                    continue;
                }
                $map[$norm] = [
                    'url' => $url,
                    'entity_type' => $type,
                    'entity_id' => (int)($entity['basic']['id'] ?? 0),
                    'title' => (string)($entity['basic']['title'] ?? $entity['basic']['name'] ?? ''),
                ];
            }
        }

        return $map;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            return rtrim(strtolower($url), '/');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '/');

        return rtrim($host . $path, '/');
    }
}
