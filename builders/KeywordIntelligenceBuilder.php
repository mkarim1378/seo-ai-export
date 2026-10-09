<?php

declare(strict_types=1);

/**
 * On-site keyword intelligence: inventory, coverage, soft cannibalization, gaps, suggestions.
 * Optional GSC CSV merge via $gscRows (query, page, clicks, impressions, position).
 */
class KeywordIntelligenceBuilder
{
    private const MAX_SUGGESTIONS = 200;
    private const MAX_GAPS = 150;
    private const MAX_HEADING_KEYWORDS = 3;

    /**
     * @param list<array<string,mixed>> $gscRows
     */
    public function build(array $knowledge, array $brain = [], array $gscRows = []): array
    {
        $inventory = [];
        $urlTargets = [];

        foreach ($this->collectUrlables($knowledge) as $item) {
            $this->ingestEntityKeywords($item, $inventory, $urlTargets);
        }

        foreach ($knowledge['categories'] ?? [] as $category) {
            $this->ingestCategoryKeywords($category, $inventory, $urlTargets);
        }

        $cannibalization = $this->buildCannibalization($inventory);
        $gaps = $this->buildGaps($knowledge, $urlTargets, $inventory);
        $suggestions = $this->buildSuggestions($knowledge, $urlTargets, $inventory);

        $gsc = $this->mergeGsc($gscRows, $inventory, $urlTargets);

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'On-site keyword intelligence from focus/secondary/tags/categories/headings/attributes. Not search-volume data unless GSC CSV is merged.',
            'summary' => [
                'inventory_count' => count($inventory),
                'url_targets_count' => count($urlTargets),
                'cannibalization_groups' => count($cannibalization),
                'gaps' => count($gaps),
                'suggestions' => count($suggestions),
                'gsc_queries_merged' => $gsc['queries_merged'],
            ],
            'inventory' => array_values($inventory),
            'url_targets' => array_values($urlTargets),
            'cannibalization' => $cannibalization,
            'gaps' => $gaps,
            'suggestions' => $suggestions,
            'gsc' => $gsc['payload'],
        ];
    }

    /**
     * Attach seo.keyword_coverage onto knowledge entities from url_targets.
     */
    public function enrichKnowledge(array $knowledge, array $keywordMap): array
    {
        $byKey = [];

        foreach ($keywordMap['url_targets'] ?? [] as $target) {
            $etype = (string)($target['entity_type'] ?? '');
            $eid = (int)($target['entity_id'] ?? 0);
            if ($etype === '' || $eid <= 0) {
                continue;
            }
            $byKey[$etype . ':' . $eid] = $target['coverage'] ?? $target;
        }

        foreach ([
            'products' => 'product',
            'posts' => 'post',
            'pages' => 'page',
            'categories' => 'category',
        ] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $i => $entity) {
                $id = (int)($entity['basic']['id'] ?? 0);
                $key = $type . ':' . $id;
                if (!isset($byKey[$key])) {
                    continue;
                }
                if (!isset($knowledge[$bucket][$i]['seo']) || !is_array($knowledge[$bucket][$i]['seo'])) {
                    $knowledge[$bucket][$i]['seo'] = [];
                }
                $knowledge[$bucket][$i]['seo']['keyword_coverage'] = $byKey[$key];
            }
        }

        return $knowledge;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectUrlables(array $knowledge): array
    {
        $items = [];

        foreach ([
            'posts' => 'post',
            'pages' => 'page',
            'products' => 'product',
        ] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $status = (string)($entity['basic']['status'] ?? '');
                if ($status !== '' && $status !== 'publish') {
                    continue;
                }

                $items[] = [
                    'entity_type' => $type,
                    'entity_id' => (int)($entity['basic']['id'] ?? 0),
                    'title' => (string)($entity['basic']['title'] ?? ''),
                    'slug' => (string)($entity['basic']['slug'] ?? ''),
                    'url' => (string)($entity['basic']['url'] ?? ''),
                    'seo' => $entity['seo'] ?? [],
                    'structure' => $entity['structure'] ?? [],
                    'content' => $entity['content'] ?? [],
                    'taxonomy' => $entity['taxonomy'] ?? [],
                    'attributes' => $entity['attributes'] ?? [],
                    'media' => $entity['media'] ?? [],
                    'is_cornerstone' => !empty($entity['seo']['is_cornerstone']),
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<string,array<string,mixed>> $inventory
     * @param array<string,array<string,mixed>> $urlTargets
     */
    private function ingestEntityKeywords(array $item, array &$inventory, array &$urlTargets): void
    {
        $url = (string)($item['url'] ?? '');
        $etype = (string)$item['entity_type'];
        $eid = (int)$item['entity_id'];
        $seo = $item['seo'] ?? [];

        $primary = trim((string)($seo['focus_keyword'] ?? ''));
        $secondary = [];
        foreach ($seo['secondary_keywords'] ?? [] as $sk) {
            $sk = trim((string)$sk);
            if ($sk !== '') {
                $secondary[] = $sk;
            }
        }

        if ($primary !== '') {
            $this->addInventory($inventory, $primary, 'focus', $url, $etype, $eid);
        }
        foreach ($secondary as $sk) {
            $this->addInventory($inventory, $sk, 'secondary', $url, $etype, $eid);
        }

        foreach ($item['taxonomy']['tags'] ?? [] as $tag) {
            $name = is_array($tag) ? (string)($tag['name'] ?? '') : (string)$tag;
            if ($name !== '') {
                $this->addInventory($inventory, $name, 'tag', $url, $etype, $eid);
            }
        }

        foreach ($item['taxonomy']['categories'] ?? [] as $cat) {
            $name = is_array($cat) ? (string)($cat['name'] ?? '') : (string)$cat;
            if ($name !== '') {
                $this->addInventory($inventory, $name, 'category', $url, $etype, $eid);
            }
        }

        $headingAdded = 0;
        foreach ($item['structure']['headings'] ?? [] as $heading) {
            if ($headingAdded >= self::MAX_HEADING_KEYWORDS) {
                break;
            }
            $level = (int)($heading['level'] ?? 0);
            if ($level < 1 || $level > 2) {
                continue;
            }
            $text = trim((string)($heading['text'] ?? ''));
            if ($text === '' || mb_strlen($text, 'UTF-8') > 80) {
                continue;
            }
            $this->addInventory($inventory, $text, 'heading', $url, $etype, $eid);
            $headingAdded++;
        }

        foreach ($item['attributes'] ?? [] as $attr) {
            foreach ($attr['values'] ?? [] as $val) {
                $val = trim((string)$val);
                if ($val !== '' && mb_strlen($val, 'UTF-8') <= 40) {
                    $this->addInventory($inventory, $val, 'attribute', $url, $etype, $eid);
                }
            }
        }

        $coverage = $this->computeCoverage($item, $primary);
        $key = $etype . ':' . $eid;

        $urlTargets[$key] = [
            'entity_type' => $etype,
            'entity_id' => $eid,
            'url' => $url,
            'title' => (string)($item['title'] ?? ''),
            'primary' => $primary,
            'secondary' => $secondary,
            'coverage_score' => $coverage['score'],
            'coverage' => $coverage,
            'is_cornerstone' => !empty($item['is_cornerstone']),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $inventory
     * @param array<string,array<string,mixed>> $urlTargets
     */
    private function ingestCategoryKeywords(array $category, array &$inventory, array &$urlTargets): void
    {
        $id = (int)($category['basic']['id'] ?? 0);
        $url = (string)($category['basic']['url'] ?? '');
        $name = (string)($category['basic']['name'] ?? '');
        $seo = $category['seo'] ?? [];
        $primary = trim((string)($seo['focus_keyword'] ?? ''));

        if ($name !== '') {
            $this->addInventory($inventory, $name, 'category', $url, 'category', $id);
        }
        if ($primary !== '') {
            $this->addInventory($inventory, $primary, 'focus', $url, 'category', $id);
        }

        $item = [
            'entity_type' => 'category',
            'entity_id' => $id,
            'title' => $name,
            'slug' => (string)($category['basic']['slug'] ?? ''),
            'url' => $url,
            'seo' => $seo,
            'structure' => $category['structure'] ?? [],
            'content' => $category['content'] ?? [],
            'taxonomy' => [],
            'attributes' => [],
            'media' => $category['media'] ?? [],
        ];

        $coverage = $this->computeCoverage($item, $primary !== '' ? $primary : $name);
        $key = 'category:' . $id;
        $urlTargets[$key] = [
            'entity_type' => 'category',
            'entity_id' => $id,
            'url' => $url,
            'title' => $name,
            'primary' => $primary,
            'secondary' => [],
            'coverage_score' => $coverage['score'],
            'coverage' => $coverage,
            'is_cornerstone' => false,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $inventory
     */
    private function addInventory(
        array &$inventory,
        string $keyword,
        string $type,
        string $url,
        string $entityType,
        int $entityId
    ): void {
        $normalized = TextMetrics::normalizeKeyword($keyword);
        if ($normalized === '') {
            return;
        }

        if (!isset($inventory[$normalized])) {
            $inventory[$normalized] = [
                'keyword' => $keyword,
                'normalized' => $normalized,
                'types' => [],
                'sources' => [],
                'urls' => [],
                'entities' => [],
            ];
        }

        $inventory[$normalized]['types'][] = $type;
        $inventory[$normalized]['types'] = array_values(array_unique($inventory[$normalized]['types']));

        $source = $type . ':' . $entityType . ':' . $entityId;
        if (!in_array($source, $inventory[$normalized]['sources'], true)) {
            $inventory[$normalized]['sources'][] = $source;
        }

        if ($url !== '' && !in_array($url, $inventory[$normalized]['urls'], true)) {
            $inventory[$normalized]['urls'][] = $url;
        }

        $entKey = $entityType . ':' . $entityId;
        $exists = false;
        foreach ($inventory[$normalized]['entities'] as $ent) {
            if (($ent['entity_type'] ?? '') . ':' . ($ent['entity_id'] ?? 0) === $entKey) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $inventory[$normalized]['entities'][] = [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'url' => $url,
                'as' => $type,
            ];
        }
    }

    private function computeCoverage(array $item, string $primary): array
    {
        if ($primary === '') {
            return [
                'score' => 0,
                'has_primary' => false,
                'in_title' => false,
                'in_meta' => false,
                'in_slug' => false,
                'in_first_paragraph' => false,
                'in_h2' => false,
                'in_image_alt' => false,
                'checks' => 0,
                'passed' => 0,
            ];
        }

        $seo = $item['seo'] ?? [];
        $title = (string)($seo['resolved_title'] ?? $seo['title'] ?? $item['title'] ?? '');
        $meta = (string)($seo['resolved_description'] ?? $seo['description'] ?? '');
        $slug = (string)($item['slug'] ?? '');
        $slugReadable = str_replace(['-', '_'], ' ', $slug);

        $body = (string)($item['content']['description']
            ?? $item['content']['content']
            ?? $item['content']['short_description']
            ?? '');
        $firstPara = $body;
        if (preg_match('/^(.{0,400})/u', $body, $m)) {
            $firstPara = $m[1];
        }

        $inH2 = false;
        foreach ($item['structure']['headings'] ?? [] as $heading) {
            if ((int)($heading['level'] ?? 0) !== 2) {
                continue;
            }
            if (TextMetrics::textContainsKeyword((string)($heading['text'] ?? ''), $primary)) {
                $inH2 = true;
                break;
            }
        }

        $inAlt = false;
        $featuredAlt = (string)(($item['media']['featured']['alt'] ?? ''));
        if (TextMetrics::textContainsKeyword($featuredAlt, $primary)) {
            $inAlt = true;
        }

        $checks = [
            'in_title' => TextMetrics::textContainsKeyword($title, $primary),
            'in_meta' => TextMetrics::textContainsKeyword($meta, $primary),
            'in_slug' => TextMetrics::textContainsKeyword($slugReadable, $primary)
                || TextMetrics::textContainsKeyword($slug, $primary),
            'in_first_paragraph' => TextMetrics::textContainsKeyword($firstPara, $primary),
            'in_h2' => $inH2,
            'in_image_alt' => $inAlt,
        ];

        $passed = count(array_filter($checks));
        $total = count($checks);
        $score = $total > 0 ? (int)round(($passed / $total) * 100) : 0;

        return array_merge([
            'score' => $score,
            'has_primary' => true,
            'checks' => $total,
            'passed' => $passed,
        ], $checks);
    }

    /**
     * @param array<string,array<string,mixed>> $inventory
     * @return list<array<string,mixed>>
     */
    private function buildCannibalization(array $inventory): array
    {
        $focusGroups = [];

        foreach ($inventory as $row) {
            if (!in_array('focus', $row['types'] ?? [], true)) {
                continue;
            }

            $focusUrls = [];
            foreach ($row['entities'] as $ent) {
                if (($ent['as'] ?? '') !== 'focus') {
                    continue;
                }
                $focusUrls[] = $ent;
            }

            if (count($focusUrls) < 2) {
                continue;
            }

            $focusGroups[] = [
                'keyword' => $row['keyword'],
                'normalized' => $row['normalized'],
                'match' => 'exact_normalized',
                'urls' => array_values(array_unique(array_column($focusUrls, 'url'))),
                'entities' => $focusUrls,
            ];
        }

        // Soft / fuzzy: merge groups whose normalized keywords match via contains
        $soft = [];
        $keys = array_keys($inventory);
        $n = count($keys);
        $used = [];

        for ($i = 0; $i < $n; $i++) {
            if (isset($used[$keys[$i]])) {
                continue;
            }
            $a = $inventory[$keys[$i]];
            if (!in_array('focus', $a['types'] ?? [], true)) {
                continue;
            }

            $groupEntities = [];
            foreach ($a['entities'] as $ent) {
                if (($ent['as'] ?? '') === 'focus') {
                    $groupEntities[] = $ent;
                }
            }

            $keywords = [$a['keyword']];
            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($used[$keys[$j]])) {
                    continue;
                }
                $b = $inventory[$keys[$j]];
                if (!in_array('focus', $b['types'] ?? [], true)) {
                    continue;
                }
                if (!TextMetrics::keywordsMatch($a['normalized'], $b['normalized'])) {
                    continue;
                }
                if ($a['normalized'] === $b['normalized']) {
                    continue;
                }
                $used[$keys[$j]] = true;
                $keywords[] = $b['keyword'];
                foreach ($b['entities'] as $ent) {
                    if (($ent['as'] ?? '') === 'focus') {
                        $groupEntities[] = $ent;
                    }
                }
            }

            $uniqueUrls = array_values(array_unique(array_filter(array_column($groupEntities, 'url'))));
            if (count($uniqueUrls) < 2 || count($keywords) < 2) {
                continue;
            }

            $soft[] = [
                'keyword' => $a['keyword'],
                'normalized' => $a['normalized'],
                'related_keywords' => array_values(array_unique($keywords)),
                'match' => 'fuzzy',
                'urls' => $uniqueUrls,
                'entities' => $groupEntities,
            ];
        }

        return array_merge($focusGroups, $soft);
    }

    /**
     * @param array<string,array<string,mixed>> $urlTargets
     * @param array<string,array<string,mixed>> $inventory
     * @return list<array<string,mixed>>
     */
    private function buildGaps(array $knowledge, array $urlTargets, array $inventory): array
    {
        $gaps = [];

        foreach ($knowledge['categories'] ?? [] as $category) {
            $count = (int)($category['basic']['count'] ?? 0);
            $id = (int)($category['basic']['id'] ?? 0);
            $name = (string)($category['basic']['name'] ?? '');
            $primary = trim((string)($category['seo']['focus_keyword'] ?? ''));

            if ($count >= 5 && $primary === '') {
                $gaps[] = [
                    'type' => 'category_missing_primary_keyword',
                    'entity_type' => 'category',
                    'entity_id' => $id,
                    'url' => (string)($category['basic']['url'] ?? ''),
                    'title' => $name,
                    'recommendation' => 'Assign a primary focus keyword for this category archive.',
                    'suggested_keyword' => $name,
                ];
            }
        }

        foreach ($urlTargets as $target) {
            if (($target['entity_type'] ?? '') === 'category') {
                continue;
            }
            if (trim((string)($target['primary'] ?? '')) !== '') {
                continue;
            }
            if (($target['entity_type'] ?? '') === 'page') {
                continue;
            }

            $gaps[] = [
                'type' => 'url_missing_primary_keyword',
                'entity_type' => $target['entity_type'],
                'entity_id' => $target['entity_id'],
                'url' => $target['url'],
                'title' => $target['title'],
                'recommendation' => 'Set a focus keyword for this URL.',
            ];

            if (count($gaps) >= self::MAX_GAPS) {
                break;
            }
        }

        return array_slice($gaps, 0, self::MAX_GAPS);
    }

    /**
     * @param array<string,array<string,mixed>> $urlTargets
     * @param array<string,array<string,mixed>> $inventory
     * @return list<array<string,mixed>>
     */
    private function buildSuggestions(array $knowledge, array $urlTargets, array $inventory): array
    {
        $suggestions = [];

        foreach ($urlTargets as $target) {
            $primary = trim((string)($target['primary'] ?? ''));
            if ($primary !== '') {
                // Secondary suggestions from tags already in inventory for same URL context
                $secondaries = $target['secondary'] ?? [];
                if (count($secondaries) >= 3) {
                    continue;
                }
                continue;
            }

            if (($target['entity_type'] ?? '') === 'category') {
                $name = (string)($target['title'] ?? '');
                if ($name === '') {
                    continue;
                }
                $suggestions[] = [
                    'entity_type' => 'category',
                    'entity_id' => $target['entity_id'],
                    'url' => $target['url'],
                    'title' => $name,
                    'suggested_primary' => $name,
                    'suggested_secondary' => [],
                    'reason' => 'category_name_as_primary',
                    'confidence' => 'high',
                ];
                continue;
            }

            $entity = $this->findEntity($knowledge, (string)$target['entity_type'], (int)$target['entity_id']);
            if ($entity === null) {
                continue;
            }

            $candidates = [];
            $title = (string)($entity['basic']['title'] ?? $entity['basic']['name'] ?? '');
            if ($title !== '') {
                $candidates[] = ['kw' => $title, 'source' => 'title', 'weight' => 3];
            }

            foreach ($entity['taxonomy']['categories'] ?? [] as $cat) {
                $cname = is_array($cat) ? (string)($cat['name'] ?? '') : (string)$cat;
                if ($cname !== '') {
                    $candidates[] = ['kw' => $cname, 'source' => 'category', 'weight' => 2];
                }
            }

            foreach ($entity['taxonomy']['tags'] ?? [] as $tag) {
                $tname = is_array($tag) ? (string)($tag['name'] ?? '') : (string)$tag;
                if ($tname !== '') {
                    $candidates[] = ['kw' => $tname, 'source' => 'tag', 'weight' => 2];
                }
            }

            foreach ($entity['structure']['headings'] ?? [] as $heading) {
                if ((int)($heading['level'] ?? 0) !== 2) {
                    continue;
                }
                $h = trim((string)($heading['text'] ?? ''));
                if ($h !== '' && mb_strlen($h, 'UTF-8') <= 60) {
                    $candidates[] = ['kw' => $h, 'source' => 'h2', 'weight' => 1];
                }
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $primarySuggestion = $candidates[0]['kw'];
            $secondary = [];
            foreach (array_slice($candidates, 1) as $c) {
                $n = TextMetrics::normalizeKeyword($c['kw']);
                $pn = TextMetrics::normalizeKeyword($primarySuggestion);
                if ($n === '' || $n === $pn) {
                    continue;
                }
                if (!in_array($c['kw'], $secondary, true)) {
                    $secondary[] = $c['kw'];
                }
                if (count($secondary) >= 3) {
                    break;
                }
            }

            $suggestions[] = [
                'entity_type' => $target['entity_type'],
                'entity_id' => $target['entity_id'],
                'url' => $target['url'],
                'title' => $target['title'],
                'suggested_primary' => $primarySuggestion,
                'suggested_secondary' => $secondary,
                'reason' => 'derived_from_' . ($candidates[0]['source'] ?? 'signals'),
                'confidence' => ($candidates[0]['source'] ?? '') === 'title' ? 'medium' : 'low',
            ];

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }

    private function findEntity(array $knowledge, string $type, int $id): ?array
    {
        $bucket = match ($type) {
            'product' => 'products',
            'post' => 'posts',
            'page' => 'pages',
            'category' => 'categories',
            default => null,
        };
        if ($bucket === null) {
            return null;
        }

        foreach ($knowledge[$bucket] ?? [] as $entity) {
            if ((int)($entity['basic']['id'] ?? 0) === $id) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $gscRows
     * @param array<string,array<string,mixed>> $inventory
     * @param array<string,array<string,mixed>> $urlTargets
     * @return array{queries_merged:int,payload:array<string,mixed>}
     */
    private function mergeGsc(array $gscRows, array &$inventory, array $urlTargets): array
    {
        if ($gscRows === []) {
            return [
                'queries_merged' => 0,
                'payload' => [
                    'enabled' => false,
                    'note' => 'Place a Search Console Queries CSV at input/gsc-queries.csv to merge volume signals.',
                    'queries_without_target' => [],
                    'top_queries' => [],
                ],
            ];
        }

        $urlSet = [];
        foreach ($urlTargets as $t) {
            $u = $this->normalizeUrl((string)($t['url'] ?? ''));
            if ($u !== '') {
                $urlSet[$u] = $t;
            }
        }

        $withoutTarget = [];
        $top = [];
        $merged = 0;

        foreach ($gscRows as $row) {
            $query = trim((string)($row['query'] ?? $row['Query'] ?? ''));
            $page = trim((string)($row['page'] ?? $row['Page'] ?? $row['Landing Page'] ?? ''));
            $clicks = (int)($row['clicks'] ?? $row['Clicks'] ?? 0);
            $impressions = (int)($row['impressions'] ?? $row['Impressions'] ?? 0);
            $position = (float)($row['position'] ?? $row['Position'] ?? 0);

            if ($query === '') {
                continue;
            }

            $merged++;
            $this->addInventory(
                $inventory,
                $query,
                'gsc',
                $page,
                'gsc',
                0
            );

            $top[] = [
                'query' => $query,
                'page' => $page,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'position' => $position,
            ];

            $pageNorm = $this->normalizeUrl($page);
            if ($pageNorm !== '' && !isset($urlSet[$pageNorm])) {
                $withoutTarget[] = [
                    'query' => $query,
                    'page' => $page,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'position' => $position,
                    'note' => 'GSC query/page not matched to an exported URL target.',
                ];
            }
        }

        usort($top, static fn(array $a, array $b): int => $b['clicks'] <=> $a['clicks']);

        return [
            'queries_merged' => $merged,
            'payload' => [
                'enabled' => true,
                'queries_merged' => $merged,
                'top_queries' => array_slice($top, 0, 100),
                'queries_without_target' => array_slice($withoutTarget, 0, 100),
            ],
        ];
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
