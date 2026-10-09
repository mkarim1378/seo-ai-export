<?php

declare(strict_types=1);

class InternalLinkGraphBuilder
{
    private const WEAK_HUB_MIN_WORDS = 300;
    private const WEAK_HUB_MAX_OUTGOING = 1;
    private const MAX_LINK_OPPORTUNITIES = 80;
    private const MAX_SOURCES_PER_OPP = 5;

    /**
     * @param array<int,int> $menuObjectIds object_id => 1
     */
    public function build(array $knowledge = [], array $menuObjectIds = []): array
    {
        if ($knowledge === []) {
            return $this->buildFromWordPressFallback($menuObjectIds);
        }

        $nodes = [];
        $urlIndex = [];

        foreach ($this->collectEntities($knowledge) as $entity) {
            $key = $this->nodeKey($entity['entity_type'], (int)$entity['id']);
            $nodes[$key] = $entity;
            $norm = $this->normalizeUrl((string)$entity['url']);
            if ($norm !== '') {
                $urlIndex[$norm] = $key;
            }
        }

        $this->resolveOutgoingTargets($nodes, $urlIndex);
        $this->attachIncoming($nodes);
        $this->flagOrphansAndHubs($nodes, $menuObjectIds);
        $this->attachCrawlDepth($nodes, $knowledge, $menuObjectIds);

        $deadLinks = $this->collectDeadLinks($nodes);
        $duplicateAnchors = $this->collectDuplicateAnchors($nodes);
        $linksByCategory = $this->linksByCategory($nodes);
        $linkOpportunities = $this->buildLinkOpportunities($nodes, $knowledge);

        $orphans = [];
        $weakHubs = [];

        foreach ($nodes as $node) {
            if (!empty($node['is_orphan'])) {
                $orphans[] = [
                    'id' => $node['id'],
                    'entity_type' => $node['entity_type'],
                    'title' => $node['title'],
                    'url' => $node['url'],
                    'crawl_depth' => $node['crawl_depth'] ?? null,
                ];
            }

            if (!empty($node['is_hub_weak'])) {
                $weakHubs[] = [
                    'id' => $node['id'],
                    'entity_type' => $node['entity_type'],
                    'title' => $node['title'],
                    'url' => $node['url'],
                    'word_count' => $node['word_count'],
                    'outgoing_count' => $node['outgoing_count'],
                ];
            }
        }

        $nodeList = array_values($nodes);

        return [
            'nodes' => $nodeList,
            'analysis' => [
                'summary' => [
                    'node_count' => count($nodeList),
                    'total_outgoing' => array_sum(array_column($nodeList, 'outgoing_count')),
                    'orphan_count' => count($orphans),
                    'weak_hub_count' => count($weakHubs),
                    'dead_link_count' => count($deadLinks),
                    'duplicate_anchor_pages' => count($duplicateAnchors),
                    'link_opportunity_count' => count($linkOpportunities),
                    'category_node_count' => count(array_filter(
                        $nodeList,
                        static fn(array $n): bool => ($n['entity_type'] ?? '') === 'category'
                    )),
                ],
                'orphans' => $orphans,
                'weak_hubs' => $weakHubs,
                'dead_internal_links' => $deadLinks,
                'duplicate_anchors' => $duplicateAnchors,
                'links_by_category' => $linksByCategory,
                'link_opportunities' => $linkOpportunities,
            ],
        ];
    }

    private function nodeKey(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectEntities(array $knowledge): array
    {
        $entities = [];

        foreach ([
            'posts' => 'post',
            'pages' => 'page',
            'products' => 'product',
        ] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $item) {
                $id = (int)($item['basic']['id'] ?? 0);

                if ($id <= 0) {
                    continue;
                }

                $outgoing = $this->resolveOutgoing($item, $type);

                $entities[] = [
                    'id' => $id,
                    'node_key' => $this->nodeKey($type, $id),
                    'entity_type' => $type,
                    'title' => (string)($item['basic']['title'] ?? ''),
                    'url' => (string)($item['basic']['url'] ?? ''),
                    'status' => (string)($item['basic']['status'] ?? ''),
                    'word_count' => (int)($item['content']['word_count'] ?? 0),
                    'focus_keyword' => (string)($item['seo']['focus_keyword'] ?? ''),
                    'is_cornerstone' => !empty($item['seo']['is_cornerstone']),
                    'categories' => $this->categoryLabels(
                        $item['taxonomy']['categories'] ?? []
                    ),
                    'category_ids' => $this->categoryIds($item['taxonomy']['categories'] ?? []),
                    'outgoing_links' => $outgoing,
                    'outgoing_count' => count($outgoing),
                    'incoming_count' => 0,
                    'incoming_from' => [],
                    'in_menu' => false,
                    'is_orphan' => false,
                    'is_hub_weak' => false,
                    'crawl_depth' => null,
                ];
            }
        }

        foreach ($knowledge['categories'] ?? [] as $category) {
            $id = (int)($category['basic']['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $entities[] = [
                'id' => $id,
                'node_key' => $this->nodeKey('category', $id),
                'entity_type' => 'category',
                'title' => (string)($category['basic']['name'] ?? ''),
                'url' => (string)($category['basic']['url'] ?? ''),
                'status' => 'publish',
                'word_count' => (int)($category['content']['word_count'] ?? 0),
                'focus_keyword' => (string)($category['seo']['focus_keyword'] ?? ''),
                'is_cornerstone' => false,
                'categories' => [(string)($category['basic']['name'] ?? '')],
                'category_ids' => [$id],
                'outgoing_links' => $this->normalizeLinks($category['structure']['internal_links'] ?? []),
                'outgoing_count' => 0,
                'incoming_count' => 0,
                'incoming_from' => [],
                'in_menu' => false,
                'is_orphan' => false,
                'is_hub_weak' => false,
                'crawl_depth' => null,
            ];
            $entities[array_key_last($entities)]['outgoing_count'] = count(
                $entities[array_key_last($entities)]['outgoing_links']
            );
        }

        return $entities;
    }

    /**
     * @param list<mixed> $categories
     * @return list<string>
     */
    private function categoryLabels(array $categories): array
    {
        $labels = [];

        foreach ($categories as $category) {
            if (is_array($category)) {
                $label = (string)($category['name'] ?? $category['slug'] ?? '');
            } else {
                $label = (string)$category;
            }

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param list<mixed> $categories
     * @return list<int>
     */
    private function categoryIds(array $categories): array
    {
        $ids = [];
        foreach ($categories as $category) {
            if (is_array($category)) {
                $id = (int)($category['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{target_id:int,target_url:string,anchor:string,target_key?:string,target_type?:string}>
     */
    private function resolveOutgoing(array $entity, string $type): array
    {
        $links = $entity['structure']['internal_links'] ?? null;

        if (is_array($links) && $links !== []) {
            return $this->normalizeLinks($links);
        }

        return $this->fallbackLinksFromHtml($entity, $type);
    }

    /**
     * @param list<array<string,mixed>> $links
     * @return list<array{target_id:int,target_url:string,anchor:string}>
     */
    private function normalizeLinks(array $links): array
    {
        $normalized = [];

        foreach ($links as $link) {
            $url = strtok((string)($link['url'] ?? $link['target_url'] ?? ''), '#') ?: '';

            if ($url === '') {
                continue;
            }

            $targetId = (int)($link['target_post_id'] ?? $link['target_id'] ?? 0);

            if ($targetId <= 0 && function_exists('url_to_postid')) {
                $targetId = (int)url_to_postid($url);
            }

            $normalized[] = [
                'target_id' => $targetId,
                'target_url' => $url,
                'anchor' => function_exists('ai_clean_text')
                    ? ai_clean_text((string)($link['anchor'] ?? ''))
                    : trim(strip_tags((string)($link['anchor'] ?? ''))),
                'target_key' => '',
                'target_type' => '',
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @param array<string,string> $urlIndex
     */
    private function resolveOutgoingTargets(array &$nodes, array $urlIndex): void
    {
        $postTypeMap = [];
        foreach ($nodes as $key => $node) {
            if (in_array($node['entity_type'], ['post', 'page', 'product'], true)) {
                $postTypeMap[(int)$node['id']] = $key;
            }
        }

        foreach ($nodes as &$node) {
            foreach ($node['outgoing_links'] as &$link) {
                $targetId = (int)($link['target_id'] ?? 0);
                $url = (string)($link['target_url'] ?? '');
                $norm = $this->normalizeUrl($url);

                if ($targetId > 0 && isset($postTypeMap[$targetId])) {
                    $link['target_key'] = $postTypeMap[$targetId];
                    $link['target_type'] = $nodes[$postTypeMap[$targetId]]['entity_type'];
                    continue;
                }

                if ($norm !== '' && isset($urlIndex[$norm])) {
                    $key = $urlIndex[$norm];
                    $link['target_key'] = $key;
                    $link['target_type'] = $nodes[$key]['entity_type'] ?? '';
                    $link['target_id'] = (int)($nodes[$key]['id'] ?? 0);
                    continue;
                }

                $link['target_key'] = '';
                $link['target_type'] = '';
            }
            unset($link);
            $node['outgoing_count'] = count($node['outgoing_links']);
        }
        unset($node);
    }

    /**
     * @return list<array{target_id:int,target_url:string,anchor:string}>
     */
    private function fallbackLinksFromHtml(array $entity, string $type): array
    {
        $id = (int)($entity['basic']['id'] ?? 0);

        if ($id <= 0 || !function_exists('get_post')) {
            return [];
        }

        $html = '';

        if ($type === 'product' && function_exists('wc_get_product')) {
            $product = wc_get_product($id);
            if ($product) {
                $html = (string)$product->get_short_description()
                    . "\n"
                    . (string)$product->get_description();
            }
        } else {
            $post = get_post($id);
            if ($post) {
                $html = (string)$post->post_content;
            }
        }

        if (trim($html) === '') {
            return [];
        }

        if (class_exists('ContentStructureExtractor')) {
            $structure = (new ContentStructureExtractor())->extract($html);
            return $this->normalizeLinks($structure['internal_links'] ?? []);
        }

        return $this->parseHrefLinks($html);
    }

    /**
     * @return list<array{target_id:int,target_url:string,anchor:string}>
     */
    private function parseHrefLinks(string $html): array
    {
        if (!preg_match_all(
            '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $home = rtrim(home_url(), '/');
        $links = [];

        foreach ($matches as $match) {
            $url = strtok($match[1], '#') ?: '';

            if ($url === '') {
                continue;
            }

            if (str_starts_with($url, '/')) {
                $url = home_url($url);
            }

            if (!str_starts_with($url, $home)) {
                continue;
            }

            $targetId = function_exists('url_to_postid')
                ? (int)url_to_postid($url)
                : 0;

            $links[] = [
                'target_id' => $targetId,
                'target_url' => $url,
                'anchor' => function_exists('ai_clean_text')
                    ? ai_clean_text($match[2] ?? '')
                    : trim(strip_tags($match[2] ?? '')),
                'target_key' => '',
                'target_type' => '',
            ];
        }

        return $links;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     */
    private function attachIncoming(array &$nodes): void
    {
        foreach ($nodes as $sourceKey => $node) {
            foreach ($node['outgoing_links'] as $link) {
                $targetKey = (string)($link['target_key'] ?? '');

                if ($targetKey === '' || !isset($nodes[$targetKey])) {
                    continue;
                }

                $nodes[$targetKey]['incoming_from'][] = $sourceKey;
            }
        }

        foreach ($nodes as &$node) {
            $node['incoming_from'] = array_values(array_unique(
                array_map('strval', $node['incoming_from'])
            ));
            $node['incoming_count'] = count($node['incoming_from']);
        }
        unset($node);
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @param array<int,int> $menuObjectIds
     */
    private function flagOrphansAndHubs(array &$nodes, array $menuObjectIds): void
    {
        foreach ($nodes as &$node) {
            $inMenu = isset($menuObjectIds[(int)$node['id']])
                && in_array($node['entity_type'], ['post', 'page', 'product'], true);
            $node['in_menu'] = $inMenu;

            $isPublished = ($node['status'] === 'publish' || $node['status'] === '');
            $node['is_orphan'] = $isPublished
                && (int)$node['incoming_count'] === 0
                && !$inMenu
                && $node['entity_type'] !== 'category';

            $isImportantType = in_array($node['entity_type'], ['post', 'product', 'page'], true);
            $node['is_hub_weak'] = $isImportantType
                && $isPublished
                && (int)$node['word_count'] >= self::WEAK_HUB_MIN_WORDS
                && (int)$node['outgoing_count'] <= self::WEAK_HUB_MAX_OUTGOING;
        }
        unset($node);
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @param array<int,int> $menuObjectIds
     */
    private function attachCrawlDepth(array &$nodes, array $knowledge, array $menuObjectIds): void
    {
        $queue = [];
        $frontId = function_exists('get_option') ? (int)get_option('page_on_front') : 0;
        if ($frontId > 0) {
            foreach (['page', 'post', 'product'] as $type) {
                $key = $this->nodeKey($type, $frontId);
                if (isset($nodes[$key])) {
                    $queue[] = [$key, 0];
                    break;
                }
            }
        }

        // Home URL match
        if ($queue === [] && function_exists('home_url')) {
            $homeNorm = $this->normalizeUrl(home_url('/'));
            foreach ($nodes as $key => $node) {
                if ($this->normalizeUrl((string)$node['url']) === $homeNorm) {
                    $queue[] = [$key, 0];
                    break;
                }
            }
        }

        foreach ($menuObjectIds as $objectId => $_) {
            foreach (['page', 'post', 'product'] as $type) {
                $key = $this->nodeKey($type, (int)$objectId);
                if (isset($nodes[$key])) {
                    $queue[] = [$key, 1];
                }
            }
        }

        $visited = [];
        while ($queue !== []) {
            [$key, $depth] = array_shift($queue);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            if (!isset($nodes[$key])) {
                continue;
            }
            $nodes[$key]['crawl_depth'] = $depth;

            foreach ($nodes[$key]['outgoing_links'] as $link) {
                $tk = (string)($link['target_key'] ?? '');
                if ($tk !== '' && !isset($visited[$tk])) {
                    $queue[] = [$tk, $depth + 1];
                }
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function collectDeadLinks(array $nodes): array
    {
        $dead = [];

        foreach ($nodes as $node) {
            foreach ($node['outgoing_links'] as $link) {
                $targetKey = (string)($link['target_key'] ?? '');
                $targetId = (int)($link['target_id'] ?? 0);
                $targetUrl = (string)($link['target_url'] ?? '');

                if ($targetKey !== '' && isset($nodes[$targetKey])) {
                    continue;
                }

                $dead[] = [
                    'source_id' => $node['id'],
                    'source_type' => $node['entity_type'],
                    'source_url' => $node['url'],
                    'anchor' => $link['anchor'] ?? '',
                    'target_id' => $targetId,
                    'target_url' => $targetUrl,
                    'reason' => $targetId > 0 && $targetKey === ''
                        ? 'missing_target_entity'
                        : 'unresolved_target',
                ];
            }
        }

        return $dead;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function collectDuplicateAnchors(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $byAnchor = [];

            foreach ($node['outgoing_links'] as $link) {
                $anchor = mb_strtolower(trim((string)($link['anchor'] ?? '')));

                if ($anchor === '') {
                    continue;
                }

                $byAnchor[$anchor][] = $link;
            }

            $duplicates = [];

            foreach ($byAnchor as $anchor => $links) {
                if (count($links) < 2) {
                    continue;
                }

                $duplicates[] = [
                    'anchor' => $anchor,
                    'count' => count($links),
                    'targets' => array_values(array_unique(array_map(
                        static fn(array $link): string => (string)($link['target_url'] ?? ''),
                        $links
                    ))),
                ];
            }

            if ($duplicates === []) {
                continue;
            }

            $result[] = [
                'id' => $node['id'],
                'entity_type' => $node['entity_type'],
                'title' => $node['title'],
                'url' => $node['url'],
                'duplicates' => $duplicates,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function linksByCategory(array $nodes): array
    {
        $buckets = [];

        foreach ($nodes as $node) {
            if (($node['entity_type'] ?? '') === 'category') {
                continue;
            }

            $categories = $node['categories'] ?? [];

            if ($categories === []) {
                $categories = ['(uncategorized)'];
            }

            foreach ($categories as $category) {
                if (!isset($buckets[$category])) {
                    $buckets[$category] = [
                        'category' => $category,
                        'entity_count' => 0,
                        'outgoing_count' => 0,
                        'incoming_count' => 0,
                        'orphan_count' => 0,
                    ];
                }

                $buckets[$category]['entity_count']++;
                $buckets[$category]['outgoing_count'] += (int)$node['outgoing_count'];
                $buckets[$category]['incoming_count'] += (int)$node['incoming_count'];

                if (!empty($node['is_orphan'])) {
                    $buckets[$category]['orphan_count']++;
                }
            }
        }

        $list = array_values($buckets);
        usort(
            $list,
            static fn(array $a, array $b): int => $b['outgoing_count'] <=> $a['outgoing_count']
        );

        return $list;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function buildLinkOpportunities(array $nodes, array $knowledge): array
    {
        $opportunities = [];

        $byCategoryId = [];
        foreach ($nodes as $key => $node) {
            foreach ($node['category_ids'] ?? [] as $cid) {
                $byCategoryId[$cid][] = $key;
            }
        }

        foreach ($nodes as $key => $node) {
            if (empty($node['is_orphan']) && (int)$node['incoming_count'] > 0) {
                continue;
            }
            if (($node['entity_type'] ?? '') === 'category') {
                continue;
            }
            if (($node['status'] ?? '') !== 'publish' && ($node['status'] ?? '') !== '') {
                continue;
            }

            $candidates = [];
            foreach ($node['category_ids'] ?? [] as $cid) {
                foreach ($byCategoryId[$cid] ?? [] as $sourceKey) {
                    if ($sourceKey === $key) {
                        continue;
                    }
                    $source = $nodes[$sourceKey] ?? null;
                    if ($source === null) {
                        continue;
                    }
                    if (($source['entity_type'] ?? '') === 'category') {
                        continue;
                    }
                    // Prefer sources that already link out and are not orphans
                    $score = (int)$source['outgoing_count'] + (int)$source['incoming_count'];
                    if (!empty($source['is_cornerstone'])) {
                        $score += 10;
                    }
                    $candidates[] = [
                        'score' => $score,
                        'entity_type' => $source['entity_type'],
                        'entity_id' => $source['id'],
                        'title' => $source['title'],
                        'url' => $source['url'],
                        'suggested_anchor' => $node['focus_keyword'] !== ''
                            ? $node['focus_keyword']
                            : $node['title'],
                    ];
                }
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $sources = array_slice($candidates, 0, self::MAX_SOURCES_PER_OPP);

            $opportunities[] = [
                'id' => $node['id'],
                'entity_id' => $node['id'],
                'entity_type' => $node['entity_type'],
                'title' => $node['title'],
                'url' => $node['url'],
                'reason' => !empty($node['is_orphan']) ? 'orphan' : 'low_inbound',
                'suggested_anchor' => $node['focus_keyword'] !== ''
                    ? $node['focus_keyword']
                    : $node['title'],
                'suggested_sources' => $sources,
            ];

            if (count($opportunities) >= self::MAX_LINK_OPPORTUNITIES) {
                break;
            }
        }

        return $opportunities;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (function_exists('home_url') && str_starts_with($url, '/')) {
            $url = home_url($url);
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
     * @param array<int,int> $menuObjectIds
     */
    private function buildFromWordPressFallback(array $menuObjectIds): array
    {
        $knowledge = [
            'posts' => [],
            'pages' => [],
            'products' => [],
            'categories' => [],
        ];

        $posts = get_posts([
            'post_type' => ['post', 'page', 'product'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($posts as $post) {
            $bucket = match ($post->post_type) {
                'page' => 'pages',
                'product' => 'products',
                default => 'posts',
            };

            $html = (string)$post->post_content;
            $structure = class_exists('ContentStructureExtractor')
                ? (new ContentStructureExtractor())->extract($html)
                : ['internal_links' => $this->parseHrefLinks($html)];

            $plain = function_exists('ai_clean_text')
                ? ai_clean_text($html)
                : trim(strip_tags($html));
            $metrics = class_exists('TextMetrics')
                ? TextMetrics::analyze($plain)
                : ['word_count' => str_word_count($plain)];

            $categories = [];
            if ($post->post_type === 'product') {
                $categories = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names']) ?: [];
            } elseif ($post->post_type === 'post') {
                $categories = wp_get_post_terms($post->ID, 'category', ['fields' => 'names']) ?: [];
            }

            $knowledge[$bucket][] = [
                'basic' => [
                    'id' => (int)$post->ID,
                    'title' => html_entity_decode((string)$post->post_title),
                    'url' => get_permalink($post),
                    'status' => $post->post_status,
                ],
                'content' => [
                    'word_count' => (int)($metrics['word_count'] ?? 0),
                ],
                'taxonomy' => [
                    'categories' => is_array($categories) ? $categories : [],
                ],
                'structure' => $structure,
                'seo' => [],
            ];
        }

        return $this->build($knowledge, $menuObjectIds);
    }
}
