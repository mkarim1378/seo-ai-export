<?php

declare(strict_types=1);

/**
 * Collect redirect rules from Rank Math, Yoast Premium, and Redirection plugin.
 */
class RedirectMapBuilder
{
    private const MAX_RULES = 2000;

    public function build(array $knowledge = []): array
    {
        $rules = [];
        $sources = [];

        $rm = $this->fromRankMath();
        if ($rm['rules'] !== []) {
            $sources[] = 'rank_math';
            $rules = array_merge($rules, $rm['rules']);
        }

        $yoast = $this->fromYoast();
        if ($yoast['rules'] !== []) {
            $sources[] = 'yoast';
            $rules = array_merge($rules, $yoast['rules']);
        }

        $redirection = $this->fromRedirectionPlugin();
        if ($redirection['rules'] !== []) {
            $sources[] = 'redirection_plugin';
            $rules = array_merge($rules, $redirection['rules']);
        }

        if (count($rules) > self::MAX_RULES) {
            $rules = array_slice($rules, 0, self::MAX_RULES);
        }

        $urlSet = $this->publishedUrlSet($knowledge);
        $analysis = $this->analyze($rules, $urlSet);

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'Redirect rules from installed SEO/redirect plugins. Not a live HTTP crawl of every URL.',
            'sources' => $sources,
            'summary' => [
                'rule_count' => count($rules),
                'by_source' => $this->countBy($rules, 'source'),
                'by_type' => $this->countBy($rules, 'type'),
                'issues' => $analysis['summary'],
            ],
            'rules' => $rules,
            'analysis' => $analysis,
        ];
    }

    /**
     * @return array{rules: list<array<string,mixed>>}
     */
    private function fromRankMath(): array
    {
        if (!defined('RANK_MATH_VERSION') && !class_exists('RankMath')) {
            return ['rules' => []];
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return ['rules' => []];
        }

        $table = $wpdb->prefix . 'rank_math_redirections';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return ['rules' => []];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT id, sources, url_to, header_code, status FROM {$table} WHERE status = 'active' LIMIT " . self::MAX_RULES,
            ARRAY_A
        );

        if (!is_array($rows)) {
            return ['rules' => []];
        }

        $rules = [];
        foreach ($rows as $row) {
            $sourcesRaw = $row['sources'] ?? '';
            $fromList = [];
            if (is_string($sourcesRaw) && $sourcesRaw !== '') {
                $decoded = json_decode($sourcesRaw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $src) {
                        if (is_array($src)) {
                            $fromList[] = (string)($src['pattern'] ?? $src['url'] ?? '');
                        } elseif (is_string($src)) {
                            $fromList[] = $src;
                        }
                    }
                } else {
                    $fromList[] = $sourcesRaw;
                }
            }

            foreach ($fromList as $from) {
                $from = trim($from);
                if ($from === '') {
                    continue;
                }
                $rules[] = [
                    'source' => 'rank_math',
                    'id' => (int)($row['id'] ?? 0),
                    'from' => $from,
                    'to' => (string)($row['url_to'] ?? ''),
                    'type' => (string)((int)($row['header_code'] ?? 301)),
                    'status' => (string)($row['status'] ?? 'active'),
                ];
            }
        }

        return ['rules' => $rules];
    }

    /**
     * @return array{rules: list<array<string,mixed>>}
     */
    private function fromYoast(): array
    {
        $optionKeys = [
            'wpseo-premium-redirects-base',
            'wpseo-premium-redirects-export-plain',
            'wpseo_redirect',
        ];

        $rules = [];
        foreach ($optionKeys as $key) {
            $data = get_option($key, null);
            if (!is_array($data) || $data === []) {
                continue;
            }

            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $from = (string)($item['origin'] ?? $item['from'] ?? $item['url'] ?? $item['key'] ?? '');
                $to = (string)($item['url'] ?? $item['target'] ?? $item['to'] ?? $item['destination'] ?? '');
                // Yoast format sometimes: origin => url as keys differently
                if ($from === '' && isset($item['format'])) {
                    continue;
                }
                if ($from === '') {
                    continue;
                }
                // Avoid treating destination-only rows oddly
                if ($to === '' && isset($item['url']) && isset($item['origin'])) {
                    $to = (string)$item['url'];
                }

                $type = (string)($item['type'] ?? $item['header'] ?? '301');
                $rules[] = [
                    'source' => 'yoast',
                    'id' => 0,
                    'from' => $from,
                    'to' => $to,
                    'type' => $type,
                    'status' => 'active',
                    'option' => $key,
                ];
            }
        }

        return ['rules' => $rules];
    }

    /**
     * @return array{rules: list<array<string,mixed>>}
     */
    private function fromRedirectionPlugin(): array
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return ['rules' => []];
        }

        $table = $wpdb->prefix . 'redirection_items';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return ['rules' => []];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT id, url, action_data, action_code, status FROM {$table} WHERE status = 'enabled' LIMIT " . self::MAX_RULES,
            ARRAY_A
        );

        if (!is_array($rows)) {
            return ['rules' => []];
        }

        $rules = [];
        foreach ($rows as $row) {
            $rules[] = [
                'source' => 'redirection_plugin',
                'id' => (int)($row['id'] ?? 0),
                'from' => (string)($row['url'] ?? ''),
                'to' => (string)($row['action_data'] ?? ''),
                'type' => (string)((int)($row['action_code'] ?? 301)),
                'status' => (string)($row['status'] ?? ''),
            ];
        }

        return ['rules' => $rules];
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param array<string,bool> $urlSet
     * @return array<string,mixed>
     */
    private function analyze(array $rules, array $urlSet): array
    {
        $byFrom = [];
        foreach ($rules as $rule) {
            $from = $this->normalizePath((string)($rule['from'] ?? ''));
            if ($from === '') {
                continue;
            }
            $byFrom[$from][] = $rule;
        }

        $chains = [];
        $loops = [];
        $duplicateFrom = [];
        $toMissing = [];

        foreach ($byFrom as $from => $group) {
            if (count($group) > 1) {
                $duplicateFrom[] = [
                    'from' => $from,
                    'count' => count($group),
                    'targets' => array_values(array_unique(array_map(
                        static fn(array $r): string => (string)($r['to'] ?? ''),
                        $group
                    ))),
                ];
            }
        }

        foreach ($rules as $rule) {
            $from = $this->normalizePath((string)($rule['from'] ?? ''));
            $to = $this->normalizePath((string)($rule['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }

            // Simple one-hop chain: from→to and to is also a from
            if (isset($byFrom[$to])) {
                $next = $byFrom[$to][0] ?? null;
                $nextTo = $this->normalizePath((string)($next['to'] ?? ''));
                $chains[] = [
                    'path' => [$from, $to, $nextTo],
                    'sources' => [$rule['source'] ?? '', $next['source'] ?? ''],
                ];
                if ($nextTo === $from) {
                    $loops[] = ['path' => [$from, $to, $nextTo]];
                }
            }

            // Absolute URL target not in published set (soft signal)
            $toUrl = (string)($rule['to'] ?? '');
            if (
                $urlSet !== []
                && (str_starts_with($toUrl, 'http://') || str_starts_with($toUrl, 'https://'))
            ) {
                $norm = $this->normalizeUrl($toUrl);
                if ($norm !== '' && !isset($urlSet[$norm]) && !$this->looksLikeExternal($toUrl)) {
                    $toMissing[] = [
                        'from' => $rule['from'] ?? '',
                        'to' => $toUrl,
                        'source' => $rule['source'] ?? '',
                    ];
                }
            }
        }

        return [
            'summary' => [
                'chain_count' => count($chains),
                'loop_count' => count($loops),
                'duplicate_from_count' => count($duplicateFrom),
                'internal_target_not_in_export' => count($toMissing),
            ],
            'chains' => array_slice($chains, 0, 50),
            'loops' => array_slice($loops, 0, 20),
            'duplicate_from' => array_slice($duplicateFrom, 0, 50),
            'internal_target_not_in_export' => array_slice($toMissing, 0, 50),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function publishedUrlSet(array $knowledge): array
    {
        $set = [];
        foreach (['products', 'posts', 'pages', 'categories'] as $bucket) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $url = (string)($entity['basic']['url'] ?? '');
                $norm = $this->normalizeUrl($url);
                if ($norm !== '') {
                    $set[$norm] = true;
                }
            }
        }

        return $set;
    }

    private function looksLikeExternal(string $url): bool
    {
        if (!function_exists('home_url')) {
            return false;
        }
        $home = $this->normalizeUrl(home_url('/'));
        $target = $this->normalizeUrl($url);
        if ($home === '' || $target === '') {
            return false;
        }
        $homeHost = explode('/', $home)[0] ?? '';
        $targetHost = explode('/', $target)[0] ?? '';

        return $homeHost !== '' && $targetHost !== '' && $homeHost !== $targetHost;
    }

    private function normalizePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $parts = function_exists('wp_parse_url') ? wp_parse_url($value) : parse_url($value);
            $value = is_array($parts) ? (string)($parts['path'] ?? '/') : $value;
        }
        $value = '/' . ltrim($value, '/');

        return rtrim(strtolower($value), '/') ?: '/';
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

    /**
     * @param list<array<string,mixed>> $rules
     * @return array<string,int>
     */
    private function countBy(array $rules, string $field): array
    {
        $counts = [];
        foreach ($rules as $rule) {
            $key = (string)($rule[$field] ?? 'unknown');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
