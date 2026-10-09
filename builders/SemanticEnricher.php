<?php

declare(strict_types=1);

class SemanticEnricher
{
    public function enrich(array $knowledge): array
    {
        $profile = (new SiteProfileBuilder())->build();

        $knowledge['site_profile'] = $profile;
        $knowledge['site'] = [
            'name' => $profile['identity']['name'] ?? get_bloginfo('name'),
            'description' => $profile['identity']['description'] ?? '',
            'url' => $profile['identity']['url'] ?? home_url(),
            'language' => $profile['identity']['language'] ?? get_locale(),
            'theme' => $profile['technical_context']['theme'] ?? null,
            'wordpress' => $profile['technical_context']['wordpress'] ?? null,
            'woocommerce' => $profile['technical_context']['woocommerce'] ?? null,
            'technical_context_note' => $profile['technical_context']['note'] ?? null,
        ];
        $knowledge['taxonomy'] = $this->taxonomy($knowledge);
        $knowledge['statistics'] = $this->statistics($knowledge);
        $knowledge['content_clusters'] = $this->contentClusters($knowledge);

        return $knowledge;
    }

    /**
     * Enrich clusters with hierarchy, pillars, averages, keywords after link graph exists.
     *
     * @param list<array<string,mixed>> $clusters
     * @return list<array<string,mixed>>
     */
    public function enrichClusters(array $clusters, array $knowledge, array $linkGraph = []): array
    {
        $inbound = [];
        foreach ($linkGraph['nodes'] ?? [] as $node) {
            $key = ($node['entity_type'] ?? '') . ':' . ($node['id'] ?? 0);
            $inbound[$key] = (int)($node['incoming_count'] ?? 0);
        }

        $catById = [];
        foreach ($knowledge['categories'] ?? [] as $category) {
            $id = (int)($category['basic']['id'] ?? 0);
            $catById[$id] = $category;
        }

        $entityLookup = [];
        foreach ([
            'products' => 'product',
            'posts' => 'post',
            'pages' => 'page',
        ] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $id = (int)($entity['basic']['id'] ?? 0);
                $entityLookup[$type . ':' . $id] = $entity;
            }
        }

        foreach ($clusters as &$cluster) {
            $catId = (int)($cluster['category_id'] ?? 0);
            $category = $catById[$catId] ?? null;

            $cluster['parent_id'] = (int)($category['basic']['parent'] ?? 0);
            $cluster['parent_name'] = (string)($category['taxonomy']['parent_name'] ?? '');
            $cluster['url'] = (string)($category['basic']['url'] ?? '');
            $cluster['product_count'] = count($cluster['products'] ?? []);
            $cluster['post_count'] = count($cluster['posts'] ?? []);
            $cluster['page_count'] = count($cluster['pages'] ?? []);

            $wordSum = 0;
            $wordN = 0;
            $pillars = [];
            $keywords = [];
            $internalLinks = 0;

            foreach (['products' => 'product', 'posts' => 'post', 'pages' => 'page'] as $listKey => $type) {
                foreach ($cluster[$listKey] ?? [] as $i => $ref) {
                    $id = (int)($ref['id'] ?? 0);
                    $entity = $entityLookup[$type . ':' . $id] ?? null;
                    if ($entity === null) {
                        continue;
                    }

                    $wc = (int)($entity['content']['word_count'] ?? 0);
                    if ($wc > 0) {
                        $wordSum += $wc;
                        $wordN++;
                    }

                    $fk = trim((string)($entity['seo']['focus_keyword'] ?? ''));
                    if ($fk !== '') {
                        $keywords[] = $fk;
                    }

                    $in = $inbound[$type . ':' . $id] ?? 0;
                    $internalLinks += $in;

                    $isCornerstone = !empty($entity['seo']['is_cornerstone']);
                    if ($isCornerstone || $in >= 5) {
                        $pillars[] = [
                            'id' => $id,
                            'type' => $type,
                            'title' => (string)($entity['basic']['title'] ?? $ref['title'] ?? ''),
                            'url' => (string)($entity['basic']['url'] ?? ''),
                            'is_cornerstone' => $isCornerstone,
                            'incoming_count' => $in,
                            'reason' => $isCornerstone ? 'cornerstone' : 'high_inbound',
                        ];
                    }

                    $cluster[$listKey][$i]['word_count'] = $wc;
                    $cluster[$listKey][$i]['focus_keyword'] = $fk;
                    $cluster[$listKey][$i]['incoming_count'] = $in;
                    $cluster[$listKey][$i]['is_cornerstone'] = $isCornerstone;
                }
            }

            // Category archive as natural pillar
            if ($category !== null) {
                $catInbound = $inbound['category:' . $catId] ?? 0;
                if ($catInbound > 0 || (int)($category['basic']['count'] ?? 0) >= 5) {
                    $pillars[] = [
                        'id' => $catId,
                        'type' => 'category',
                        'title' => (string)($category['basic']['name'] ?? ''),
                        'url' => (string)($category['basic']['url'] ?? ''),
                        'is_cornerstone' => false,
                        'incoming_count' => $catInbound,
                        'reason' => 'category_archive',
                    ];
                }
                $catKw = trim((string)($category['seo']['focus_keyword'] ?? ''));
                if ($catKw !== '') {
                    $keywords[] = $catKw;
                } elseif (!empty($category['basic']['name'])) {
                    $keywords[] = (string)$category['basic']['name'];
                }
            }

            usort(
                $pillars,
                static fn(array $a, array $b): int => ($b['incoming_count'] ?? 0) <=> ($a['incoming_count'] ?? 0)
            );

            $cluster['avg_word_count'] = $wordN > 0 ? (int)round($wordSum / $wordN) : 0;
            $cluster['inbound_link_total'] = $internalLinks;
            $cluster['pillars'] = array_slice($pillars, 0, 8);
            $cluster['target_keywords'] = array_values(array_unique($keywords));
        }
        unset($cluster);

        return $clusters;
    }

    private function statistics(array $knowledge): array
    {
        return [
            'products' => count($knowledge['products'] ?? []),
            'categories' => count($knowledge['categories'] ?? []),
            'posts' => count($knowledge['posts'] ?? []),
            'pages' => count($knowledge['pages'] ?? []),
            'media' => count($knowledge['media'] ?? []),
        ];
    }

    private function taxonomy(array $knowledge): array
    {
        $tree = [];

        foreach ($knowledge['categories'] ?? [] as $category) {
            $tree[] = [
                'id' => $category['basic']['id'] ?? null,
                'name' => $category['basic']['name'] ?? '',
                'slug' => $category['basic']['slug'] ?? '',
                'parent' => (int)($category['basic']['parent'] ?? 0),
            ];
        }

        return $tree;
    }

    private function contentClusters(array $knowledge): array
    {
        $clusters = [];
        $byId = [];
        $byName = [];

        foreach ($knowledge['categories'] ?? [] as $category) {
            $slug = (string)($category['basic']['slug'] ?? '');
            $id = (int)($category['basic']['id'] ?? 0);
            $name = (string)($category['basic']['name'] ?? '');

            if ($slug === '') {
                continue;
            }

            $clusters[$slug] = [
                'category_id' => $id,
                'category' => $name,
                'slug' => $slug,
                'products' => [],
                'posts' => [],
                'pages' => [],
            ];

            if ($id > 0) {
                $byId[$id] = $slug;
            }

            if ($name !== '') {
                $byName[mb_strtolower($name)] = $slug;
            }
        }

        foreach ($knowledge['products'] ?? [] as $product) {
            foreach ($this->termSlugs($product['taxonomy']['categories'] ?? [], $byId, $byName, $clusters) as $slug) {
                $clusters[$slug]['products'][] = [
                    'id' => $product['basic']['id'] ?? null,
                    'title' => $product['basic']['title'] ?? '',
                ];
            }
        }

        foreach ($knowledge['posts'] ?? [] as $post) {
            foreach ($this->termSlugs($post['taxonomy']['categories'] ?? [], $byId, $byName, $clusters) as $slug) {
                $clusters[$slug]['posts'][] = [
                    'id' => $post['basic']['id'] ?? null,
                    'title' => $post['basic']['title'] ?? '',
                ];
            }
        }

        // Attach pages that link strongly to a category URL or mention category name in title
        $catUrlToSlug = [];
        foreach ($knowledge['categories'] ?? [] as $category) {
            $slug = (string)($category['basic']['slug'] ?? '');
            $url = $this->normalizeUrl((string)($category['basic']['url'] ?? ''));
            if ($slug !== '' && $url !== '') {
                $catUrlToSlug[$url] = $slug;
            }
        }

        foreach ($knowledge['pages'] ?? [] as $page) {
            $assigned = [];
            $title = mb_strtolower((string)($page['basic']['title'] ?? ''), 'UTF-8');

            foreach ($byName as $nameKey => $slug) {
                if ($nameKey !== '' && str_contains($title, $nameKey)) {
                    $assigned[] = $slug;
                }
            }

            foreach ($page['structure']['internal_links'] ?? [] as $link) {
                $norm = $this->normalizeUrl((string)($link['url'] ?? ''));
                if ($norm !== '' && isset($catUrlToSlug[$norm])) {
                    $assigned[] = $catUrlToSlug[$norm];
                }
            }

            foreach (array_unique($assigned) as $slug) {
                if (!isset($clusters[$slug])) {
                    continue;
                }
                $clusters[$slug]['pages'][] = [
                    'id' => $page['basic']['id'] ?? null,
                    'title' => $page['basic']['title'] ?? '',
                ];
            }
        }

        return array_values($clusters);
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
     * @param list<mixed> $terms
     * @param array<int,string> $byId
     * @param array<string,string> $byName
     * @param array<string,array<string,mixed>> $clusters
     * @return list<string>
     */
    private function termSlugs(array $terms, array $byId, array $byName, array $clusters): array
    {
        $slugs = [];

        foreach ($terms as $term) {
            if (is_array($term)) {
                $slug = (string)($term['slug'] ?? '');
                $id = (int)($term['id'] ?? 0);
                $name = (string)($term['name'] ?? '');

                if ($slug !== '' && isset($clusters[$slug])) {
                    $slugs[] = $slug;
                    continue;
                }

                if ($id > 0 && isset($byId[$id])) {
                    $slugs[] = $byId[$id];
                    continue;
                }

                $nameKey = mb_strtolower($name);
                if ($nameKey !== '' && isset($byName[$nameKey])) {
                    $slugs[] = $byName[$nameKey];
                }

                continue;
            }

            $raw = trim((string)$term);
            if ($raw === '') {
                continue;
            }

            if (isset($clusters[$raw])) {
                $slugs[] = $raw;
                continue;
            }

            $nameKey = mb_strtolower($raw);
            if (isset($byName[$nameKey])) {
                $slugs[] = $byName[$nameKey];
            }
        }

        return array_values(array_unique($slugs));
    }
}
