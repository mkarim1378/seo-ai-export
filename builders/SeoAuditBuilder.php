<?php

declare(strict_types=1);

class SeoAuditBuilder
{
    private const STALE_DAYS = 180;
    private const LONG_CONTENT_WORDS = 300;
    private const MIN_INTERNAL_LINKS_FOR_LONG = 2;
    private const THIN_CATEGORY_MAX_COUNT = 3;
    private const KEY_PRODUCT_MIN_WORDS = 150;

    public function build(array $knowledge, array $brain = []): array
    {
        $findings = [];

        $urlables = $this->collectUrlables($knowledge);

        $findings = array_merge(
            $findings,
            $this->auditMissingSeoFields($urlables),
            $this->auditDuplicateSeoFields($urlables),
            $this->auditKeywordCannibalization($urlables),
            $this->auditHeadingStructure($urlables),
            $this->auditProductMedia($knowledge['products'] ?? []),
            $this->auditThinCategories($knowledge['categories'] ?? []),
            $this->auditStalePosts($knowledge['posts'] ?? []),
            $this->auditOrphans($brain),
            $this->auditWeakInternalLinks($urlables, $brain),
            $this->auditUnexpectedNoindex($urlables),
            $this->auditProductIdentifiers($knowledge['products'] ?? []),
            $this->auditProductReviews($knowledge['products'] ?? []),
            $this->auditDeadLinks($brain),
            $this->auditWeakHubs($brain)
        );

        usort(
            $findings,
            static function (array $a, array $b): int {
                $rank = ['critical' => 0, 'warning' => 1, 'opportunity' => 2];
                $sa = $rank[$a['severity']] ?? 9;
                $sb = $rank[$b['severity']] ?? 9;

                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }

                return strcmp((string)$a['id'], (string)$b['id']);
            }
        );

        $summary = [
            'critical' => 0,
            'warning' => 0,
            'opportunity' => 0,
            'total' => count($findings),
        ];

        $byType = [];
        $byEntity = [];

        foreach ($findings as $finding) {
            $severity = $finding['severity'];
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }

            $type = $finding['type'];
            $byType[$type] = ($byType[$type] ?? 0) + 1;

            $entityKey = $finding['entity_type'] . ':' . $finding['entity_id'];
            if (!isset($byEntity[$entityKey])) {
                $byEntity[$entityKey] = [];
            }
            $byEntity[$entityKey][] = $finding['id'];
        }

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'summary' => $summary,
            'findings' => $findings,
            'indexes' => [
                'by_type' => $byType,
                'by_entity' => $byEntity,
                'by_severity' => [
                    'critical' => array_values(array_map(
                        static fn(array $f): string => $f['id'],
                        array_filter($findings, static fn(array $f): bool => $f['severity'] === 'critical')
                    )),
                    'warning' => array_values(array_map(
                        static fn(array $f): string => $f['id'],
                        array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')
                    )),
                    'opportunity' => array_values(array_map(
                        static fn(array $f): string => $f['id'],
                        array_filter($findings, static fn(array $f): bool => $f['severity'] === 'opportunity')
                    )),
                ],
            ],
        ];
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
                    'url' => (string)($entity['basic']['url'] ?? ''),
                    'updated_at' => (string)($entity['basic']['updated_at'] ?? ''),
                    'word_count' => (int)($entity['content']['word_count'] ?? 0),
                    'seo' => $entity['seo'] ?? [],
                    'structure' => $entity['structure'] ?? [],
                    'media' => $entity['media'] ?? [],
                    'identifiers' => $entity['identifiers'] ?? [],
                    'ratings' => $entity['ratings'] ?? [],
                    'reviews' => $entity['reviews'] ?? [],
                ];
            }
        }

        foreach ($knowledge['categories'] ?? [] as $entity) {
            $items[] = [
                'entity_type' => 'category',
                'entity_id' => (int)($entity['basic']['id'] ?? 0),
                'title' => (string)($entity['basic']['name'] ?? ''),
                'url' => (string)($entity['basic']['url'] ?? ''),
                'updated_at' => '',
                'word_count' => (int)($entity['content']['word_count'] ?? 0),
                'seo' => $entity['seo'] ?? [],
                'structure' => $entity['structure'] ?? [],
                'media' => $entity['media'] ?? [],
                'identifiers' => [],
                'ratings' => [],
                'reviews' => [],
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function auditMissingSeoFields(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            $seoTitle = trim((string)($item['seo']['title'] ?? ''));
            $seoDesc = trim((string)($item['seo']['description'] ?? ''));

            if ($seoTitle === '') {
                $findings[] = $this->finding(
                    $item,
                    'missing_seo_title',
                    'warning',
                    'generate_title',
                    'SEO title is missing.',
                    ['seo_title' => $seoTitle]
                );
            }

            if ($seoDesc === '') {
                $findings[] = $this->finding(
                    $item,
                    'missing_meta_description',
                    'warning',
                    'generate_meta_description',
                    'Meta description is missing.',
                    ['seo_description' => $seoDesc]
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function auditDuplicateSeoFields(array $items): array
    {
        $byTitle = [];
        $byDesc = [];

        foreach ($items as $item) {
            $title = mb_strtolower(trim((string)($item['seo']['title'] ?? '')));
            $desc = mb_strtolower(trim((string)($item['seo']['description'] ?? '')));

            if ($title !== '') {
                $byTitle[$title][] = $item;
            }
            if ($desc !== '') {
                $byDesc[$desc][] = $item;
            }
        }

        $findings = [];

        foreach ($byTitle as $value => $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $item) {
                $findings[] = $this->finding(
                    $item,
                    'duplicate_seo_title',
                    'critical',
                    'generate_title',
                    'SEO title is duplicated across multiple URLs.',
                    [
                        'seo_title' => $item['seo']['title'] ?? '',
                        'duplicate_count' => count($group),
                        'other_urls' => $this->otherUrls($group, $item),
                    ]
                );
            }
        }

        foreach ($byDesc as $value => $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $item) {
                $findings[] = $this->finding(
                    $item,
                    'duplicate_meta_description',
                    'critical',
                    'generate_meta_description',
                    'Meta description is duplicated across multiple URLs.',
                    [
                        'seo_description' => $item['seo']['description'] ?? '',
                        'duplicate_count' => count($group),
                        'other_urls' => $this->otherUrls($group, $item),
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function auditKeywordCannibalization(array $items): array
    {
        $byKeyword = [];

        foreach ($items as $item) {
            $keyword = mb_strtolower(trim((string)($item['seo']['focus_keyword'] ?? '')));
            if ($keyword === '') {
                continue;
            }
            $byKeyword[$keyword][] = $item;
        }

        $findings = [];

        foreach ($byKeyword as $keyword => $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $item) {
                $findings[] = $this->finding(
                    $item,
                    'keyword_cannibalization',
                    'critical',
                    'resolve_keyword_cannibalization',
                    'Focus keyword is used on multiple published URLs.',
                    [
                        'focus_keyword' => $item['seo']['focus_keyword'] ?? '',
                        'competing_urls' => $this->otherUrls($group, $item),
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function auditHeadingStructure(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            // Category archive H1 usually comes from the theme template, not term description HTML.
            if (($item['entity_type'] ?? '') === 'category') {
                continue;
            }

            $headings = $item['structure']['headings'] ?? [];
            if (!is_array($headings)) {
                $headings = [];
            }

            $h1Count = 0;
            $h2Count = 0;

            foreach ($headings as $heading) {
                $level = (int)($heading['level'] ?? 0);
                if ($level === 1) {
                    $h1Count++;
                }
                if ($level === 2) {
                    $h2Count++;
                }
            }

            if ($h1Count === 0) {
                $findings[] = $this->finding(
                    $item,
                    'missing_h1',
                    'warning',
                    'add_h1',
                    'Content has no H1 heading.',
                    ['h1_count' => $h1Count, 'heading_count' => count($headings)]
                );
            } elseif ($h1Count > 1) {
                $findings[] = $this->finding(
                    $item,
                    'multiple_h1',
                    'warning',
                    'fix_heading_hierarchy',
                    'Content has more than one H1 heading.',
                    ['h1_count' => $h1Count]
                );
            }

            if (
                (int)$item['word_count'] >= self::LONG_CONTENT_WORDS
                && $h2Count === 0
            ) {
                $findings[] = $this->finding(
                    $item,
                    'weak_heading_outline',
                    'opportunity',
                    'improve_heading_outline',
                    'Long content has no H2 headings.',
                    [
                        'word_count' => $item['word_count'],
                        'h2_count' => $h2Count,
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private function auditProductMedia(array $products): array
    {
        $findings = [];

        foreach ($products as $product) {
            $status = (string)($product['basic']['status'] ?? '');
            if ($status !== '' && $status !== 'publish') {
                continue;
            }

            $item = $this->productAsItem($product);
            $featured = $product['media']['featured'] ?? [];
            $hasImage = !empty($featured['id']) || !empty($featured['url']);

            if (!$hasImage) {
                $findings[] = $this->finding(
                    $item,
                    'product_missing_image',
                    'critical',
                    'add_product_image',
                    'Product has no featured image.',
                    ['featured' => $featured]
                );
                continue;
            }

            $alt = trim((string)($featured['alt'] ?? ''));
            if ($alt === '') {
                $findings[] = $this->finding(
                    $item,
                    'product_image_missing_alt',
                    'warning',
                    'fix_image_alt',
                    'Product featured image is missing alt text.',
                    [
                        'image_id' => $featured['id'] ?? null,
                        'image_url' => $featured['url'] ?? '',
                    ]
                );
            }

            foreach ($product['media']['gallery'] ?? [] as $image) {
                if (trim((string)($image['alt'] ?? '')) !== '') {
                    continue;
                }

                $findings[] = $this->finding(
                    $item,
                    'gallery_image_missing_alt',
                    'opportunity',
                    'fix_image_alt',
                    'Gallery image is missing alt text.',
                    [
                        'image_id' => $image['id'] ?? null,
                        'image_url' => $image['url'] ?? '',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $categories
     * @return list<array<string,mixed>>
     */
    private function auditThinCategories(array $categories): array
    {
        $findings = [];

        foreach ($categories as $category) {
            $count = (int)($category['basic']['count'] ?? 0);
            $description = trim((string)($category['content']['description'] ?? ''));
            $wordCount = (int)($category['content']['word_count'] ?? 0);

            if ($count > self::THIN_CATEGORY_MAX_COUNT && $description !== '') {
                continue;
            }

            if ($count <= self::THIN_CATEGORY_MAX_COUNT && ($description === '' || $wordCount < 40)) {
                $item = [
                    'entity_type' => 'category',
                    'entity_id' => (int)($category['basic']['id'] ?? 0),
                    'title' => (string)($category['basic']['name'] ?? ''),
                    'url' => (string)($category['basic']['url'] ?? ''),
                ];

                $findings[] = $this->finding(
                    $item,
                    'thin_category',
                    'warning',
                    'expand_category_description',
                    'Category looks thin (few products and/or empty description).',
                    [
                        'product_count' => $count,
                        'word_count' => $wordCount,
                        'description_empty' => $description === '',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $posts
     * @return list<array<string,mixed>>
     */
    private function auditStalePosts(array $posts): array
    {
        $findings = [];
        $daySeconds = defined('DAY_IN_SECONDS') ? (int)DAY_IN_SECONDS : 86400;
        $threshold = time() - (self::STALE_DAYS * $daySeconds);

        foreach ($posts as $post) {
            $status = (string)($post['basic']['status'] ?? '');
            if ($status !== '' && $status !== 'publish') {
                continue;
            }

            $updated = (string)($post['basic']['updated_at'] ?? '');
            if ($updated === '') {
                continue;
            }

            $ts = strtotime($updated);
            if ($ts === false || $ts >= $threshold) {
                continue;
            }

            $item = [
                'entity_type' => 'post',
                'entity_id' => (int)($post['basic']['id'] ?? 0),
                'title' => (string)($post['basic']['title'] ?? ''),
                'url' => (string)($post['basic']['url'] ?? ''),
            ];

            $days = (int) floor((time() - $ts) / $daySeconds);

            $findings[] = $this->finding(
                $item,
                'stale_content',
                'opportunity',
                'refresh_stale_content',
                'Post has not been updated for a long time.',
                [
                    'updated_at' => $updated,
                    'days_since_update' => $days,
                    'threshold_days' => self::STALE_DAYS,
                ]
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditOrphans(array $brain): array
    {
        $findings = [];

        foreach ($brain['link_analysis']['orphans'] ?? [] as $orphan) {
            $item = [
                'entity_type' => (string)($orphan['entity_type'] ?? 'unknown'),
                'entity_id' => (int)($orphan['id'] ?? 0),
                'title' => (string)($orphan['title'] ?? ''),
                'url' => (string)($orphan['url'] ?? ''),
            ];

            $findings[] = $this->finding(
                $item,
                'orphan_url',
                'warning',
                'add_internal_links',
                'URL has no inbound internal links and is not in menus.',
                ['orphan' => true]
            );
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function auditWeakInternalLinks(array $items, array $brain): array
    {
        $outgoingById = [];

        foreach ($brain['knowledge_graph']['internal_link_graph']['nodes'] ?? [] as $node) {
            $outgoingById[(int)$node['id']] = (int)($node['outgoing_count'] ?? 0);
        }

        $findings = [];

        foreach ($items as $item) {
            $wordCount = (int)$item['word_count'];
            if ($wordCount < self::LONG_CONTENT_WORDS) {
                continue;
            }

            $outgoing = $outgoingById[(int)$item['entity_id']]
                ?? count($item['structure']['internal_links'] ?? []);

            if ($outgoing >= self::MIN_INTERNAL_LINKS_FOR_LONG) {
                continue;
            }

            $findings[] = $this->finding(
                $item,
                'low_internal_links',
                'opportunity',
                'add_internal_links',
                'Long content has too few internal links.',
                [
                    'word_count' => $wordCount,
                    'outgoing_count' => $outgoing,
                    'minimum_expected' => self::MIN_INTERNAL_LINKS_FOR_LONG,
                ]
            );
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function auditUnexpectedNoindex(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            $index = $item['seo']['robots']['index'] ?? true;
            if ($index !== false) {
                continue;
            }

            $findings[] = $this->finding(
                $item,
                'unexpected_noindex',
                'critical',
                'review_robots_directives',
                'Published URL is marked noindex.',
                [
                    'robots' => $item['seo']['robots'] ?? [],
                ]
            );
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private function auditProductIdentifiers(array $products): array
    {
        $findings = [];

        foreach ($products as $product) {
            $status = (string)($product['basic']['status'] ?? '');
            if ($status !== '' && $status !== 'publish') {
                continue;
            }

            $wordCount = (int)($product['content']['word_count'] ?? 0);
            if ($wordCount < self::KEY_PRODUCT_MIN_WORDS) {
                continue;
            }

            $item = $this->productAsItem($product);
            $brand = trim((string)($product['identifiers']['brand'] ?? ''));
            $gtin = trim((string)($product['identifiers']['gtin'] ?? ''));
            $ean = trim((string)($product['identifiers']['ean'] ?? ''));

            if ($brand === '') {
                $findings[] = $this->finding(
                    $item,
                    'missing_product_brand',
                    'opportunity',
                    'add_product_brand',
                    'Key product is missing brand identifier.',
                    ['word_count' => $wordCount]
                );
            }

            if ($gtin === '' && $ean === '') {
                $findings[] = $this->finding(
                    $item,
                    'missing_product_gtin',
                    'opportunity',
                    'add_product_gtin',
                    'Key product is missing GTIN/EAN.',
                    ['word_count' => $wordCount]
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private function auditProductReviews(array $products): array
    {
        $findings = [];

        foreach ($products as $product) {
            $status = (string)($product['basic']['status'] ?? '');
            if ($status !== '' && $status !== 'publish') {
                continue;
            }

            $wordCount = (int)($product['content']['word_count'] ?? 0);
            $reviewCount = (int)($product['ratings']['review_count'] ?? count($product['reviews'] ?? []));

            if ($wordCount < self::KEY_PRODUCT_MIN_WORDS || $reviewCount > 0) {
                continue;
            }

            $item = $this->productAsItem($product);

            $findings[] = $this->finding(
                $item,
                'product_zero_reviews',
                'opportunity',
                'encourage_product_reviews',
                'Content-rich product has zero reviews.',
                [
                    'word_count' => $wordCount,
                    'review_count' => $reviewCount,
                ]
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditDeadLinks(array $brain): array
    {
        $findings = [];
        $bySource = [];

        foreach ($brain['link_analysis']['dead_internal_links'] ?? [] as $dead) {
            $sourceId = (int)($dead['source_id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }
            $bySource[$sourceId][] = $dead;
        }

        foreach ($bySource as $sourceId => $links) {
            $first = $links[0];
            $item = [
                'entity_type' => (string)($first['source_type'] ?? 'unknown'),
                'entity_id' => $sourceId,
                'title' => '',
                'url' => (string)($first['source_url'] ?? ''),
            ];

            $findings[] = $this->finding(
                $item,
                'dead_internal_links',
                'warning',
                'fix_dead_internal_links',
                'Page contains dead or unresolved internal links.',
                [
                    'dead_count' => count($links),
                    'samples' => array_slice($links, 0, 5),
                ]
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditWeakHubs(array $brain): array
    {
        $findings = [];

        foreach ($brain['link_analysis']['weak_hubs'] ?? [] as $hub) {
            $item = [
                'entity_type' => (string)($hub['entity_type'] ?? 'unknown'),
                'entity_id' => (int)($hub['id'] ?? 0),
                'title' => (string)($hub['title'] ?? ''),
                'url' => (string)($hub['url'] ?? ''),
            ];

            $findings[] = $this->finding(
                $item,
                'weak_hub',
                'opportunity',
                'add_internal_links',
                'Important long-form URL has very few outgoing internal links.',
                [
                    'word_count' => $hub['word_count'] ?? 0,
                    'outgoing_count' => $hub['outgoing_count'] ?? 0,
                ]
            );
        }

        return $findings;
    }

    private function productAsItem(array $product): array
    {
        return [
            'entity_type' => 'product',
            'entity_id' => (int)($product['basic']['id'] ?? 0),
            'title' => (string)($product['basic']['title'] ?? ''),
            'url' => (string)($product['basic']['url'] ?? ''),
        ];
    }

    /**
     * @param list<array<string,mixed>> $group
     * @return list<string>
     */
    private function otherUrls(array $group, array $item): array
    {
        $urls = [];

        foreach ($group as $other) {
            if ((int)$other['entity_id'] === (int)$item['entity_id']
                && ($other['entity_type'] ?? '') === ($item['entity_type'] ?? '')
            ) {
                continue;
            }
            $urls[] = (string)($other['url'] ?? '');
        }

        return array_values(array_filter($urls));
    }

    private function finding(
        array $item,
        string $type,
        string $severity,
        string $aiAction,
        string $recommendation,
        array $evidence = []
    ): array {
        $entityType = (string)($item['entity_type'] ?? 'unknown');
        $entityId = (int)($item['entity_id'] ?? 0);

        return [
            'id' => sprintf('%s-%d-%s', $entityType, $entityId, $type),
            'severity' => $severity,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'url' => (string)($item['url'] ?? ''),
            'title' => (string)($item['title'] ?? ''),
            'evidence' => $evidence,
            'recommendation' => $recommendation,
            'ai_action' => $aiAction,
        ];
    }
}
