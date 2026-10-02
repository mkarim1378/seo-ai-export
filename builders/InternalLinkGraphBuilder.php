<?php

declare(strict_types=1);

class InternalLinkGraphBuilder
{
    private const WEAK_HUB_MIN_WORDS = 300;
    private const WEAK_HUB_MAX_OUTGOING = 1;

    /**
     * @param array<int,int> $menuObjectIds object_id => 1
     */
    public function build(array $knowledge = [], array $menuObjectIds = []): array
    {
        if ($knowledge === []) {
            return $this->buildFromWordPressFallback($menuObjectIds);
        }

        $nodes = [];

        foreach ($this->collectEntities($knowledge) as $entity) {
            $nodes[$entity['id']] = $entity;
        }

        $this->attachIncoming($nodes);
        $this->flagOrphansAndHubs($nodes, $menuObjectIds);

        $deadLinks = $this->collectDeadLinks($nodes);
        $duplicateAnchors = $this->collectDuplicateAnchors($nodes);
        $linksByCategory = $this->linksByCategory($nodes);

        $orphans = [];
        $weakHubs = [];

        foreach ($nodes as $node) {
            if (!empty($node['is_orphan'])) {
                $orphans[] = [
                    'id' => $node['id'],
                    'entity_type' => $node['entity_type'],
                    'title' => $node['title'],
                    'url' => $node['url'],
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
                ],
                'orphans' => $orphans,
                'weak_hubs' => $weakHubs,
                'dead_internal_links' => $deadLinks,
                'duplicate_anchors' => $duplicateAnchors,
                'links_by_category' => $linksByCategory,
            ],
        ];
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
                    'entity_type' => $type,
                    'title' => (string)($item['basic']['title'] ?? ''),
                    'url' => (string)($item['basic']['url'] ?? ''),
                    'status' => (string)($item['basic']['status'] ?? ''),
                    'word_count' => (int)($item['content']['word_count'] ?? 0),
                    'categories' => array_values(array_filter(array_map(
                        'strval',
                        $item['taxonomy']['categories'] ?? []
                    ))),
                    'outgoing_links' => $outgoing,
                    'outgoing_count' => count($outgoing),
                    'incoming_count' => 0,
                    'incoming_from' => [],
                    'in_menu' => false,
                    'is_orphan' => false,
                    'is_hub_weak' => false,
                ];
            }
        }

        return $entities;
    }

    /**
     * @return list<array{target_id:int,target_url:string,anchor:string}>
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
                'anchor' => ai_clean_text((string)($link['anchor'] ?? '')),
            ];
        }

        return $normalized;
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
                'anchor' => ai_clean_text($match[2] ?? ''),
            ];
        }

        return $links;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     */
    private function attachIncoming(array &$nodes): void
    {
        foreach ($nodes as $sourceId => $node) {
            foreach ($node['outgoing_links'] as $link) {
                $targetId = (int)($link['target_id'] ?? 0);

                if ($targetId <= 0 || !isset($nodes[$targetId])) {
                    continue;
                }

                $nodes[$targetId]['incoming_from'][] = (int)$sourceId;
            }
        }

        foreach ($nodes as &$node) {
            $node['incoming_from'] = array_values(array_unique(
                array_map('intval', $node['incoming_from'])
            ));
            $node['incoming_count'] = count($node['incoming_from']);
        }
        unset($node);
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<int,int> $menuObjectIds
     */
    private function flagOrphansAndHubs(array &$nodes, array $menuObjectIds): void
    {
        foreach ($nodes as &$node) {
            $inMenu = isset($menuObjectIds[(int)$node['id']]);
            $node['in_menu'] = $inMenu;

            $isPublished = ($node['status'] === 'publish' || $node['status'] === '');
            $node['is_orphan'] = $isPublished
                && (int)$node['incoming_count'] === 0
                && !$inMenu;

            $isImportantType = in_array($node['entity_type'], ['post', 'product', 'page'], true);
            $node['is_hub_weak'] = $isImportantType
                && $isPublished
                && (int)$node['word_count'] >= self::WEAK_HUB_MIN_WORDS
                && (int)$node['outgoing_count'] <= self::WEAK_HUB_MAX_OUTGOING;
        }
        unset($node);
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function collectDeadLinks(array $nodes): array
    {
        $dead = [];

        foreach ($nodes as $node) {
            foreach ($node['outgoing_links'] as $link) {
                $targetId = (int)($link['target_id'] ?? 0);
                $targetUrl = (string)($link['target_url'] ?? '');

                $isDead = $targetId <= 0 || !isset($nodes[$targetId]);

                if (!$isDead) {
                    continue;
                }

                $dead[] = [
                    'source_id' => $node['id'],
                    'source_type' => $node['entity_type'],
                    'source_url' => $node['url'],
                    'anchor' => $link['anchor'] ?? '',
                    'target_id' => $targetId,
                    'target_url' => $targetUrl,
                    'reason' => $targetId <= 0 ? 'unresolved_target' : 'missing_target_entity',
                ];
            }
        }

        return $dead;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
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
     * @param array<int,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function linksByCategory(array $nodes): array
    {
        $buckets = [];

        foreach ($nodes as $node) {
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
     * @param array<int,int> $menuObjectIds
     */
    private function buildFromWordPressFallback(array $menuObjectIds): array
    {
        $knowledge = [
            'posts' => [],
            'pages' => [],
            'products' => [],
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

            $plain = ai_clean_text($html);
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
            ];
        }

        return $this->build($knowledge, $menuObjectIds);
    }
}
