<?php

declare(strict_types=1);

class SeoAuditBuilder
{
    private const STALE_DAYS = 180;
    private const LONG_CONTENT_WORDS = 300;
    private const MIN_INTERNAL_LINKS_FOR_LONG = 2;
    private const THIN_CATEGORY_MAX_COUNT = 3;
    private const KEY_PRODUCT_MIN_WORDS = 150;
    private const TITLE_MIN_CHARS = 30;
    private const TITLE_MAX_CHARS = 65;
    private const DESC_MIN_CHARS = 70;
    private const DESC_MAX_CHARS = 160;

    public function build(array $knowledge, array $brain = [], array $keywordMap = []): array
    {
        $findings = [];

        $urlables = $this->collectUrlables($knowledge);
        $siteProfile = $brain['site_profile'] ?? [];

        $findings = array_merge(
            $findings,
            $this->auditSiteVisibility($siteProfile),
            $this->auditPluginGlobalNoindex($siteProfile),
            $this->auditMissingSeoFields($urlables),
            $this->auditSeoFieldLengths($urlables),
            $this->auditCanonicals($urlables),
            $this->auditDuplicateSeoFields($urlables),
            $this->auditKeywordCannibalization($urlables, $keywordMap),
            $this->auditKeywordCoverage($urlables),
            $this->auditFaqSchemaGaps($urlables),
            $this->auditHeadingStructure($urlables),
            $this->auditProductMedia($knowledge['products'] ?? []),
            $this->auditThinCategories($knowledge['categories'] ?? []),
            $this->auditCategoryContentGaps($knowledge['categories'] ?? []),
            $this->auditTopicGaps($knowledge, $brain),
            $this->auditDuplicateShortDescriptions($knowledge['products'] ?? []),
            $this->auditStalePosts($knowledge['posts'] ?? []),
            $this->auditOrphans($brain),
            $this->auditSpecialPageDiscoverability($brain, $siteProfile),
            $this->auditWeakInternalLinks($urlables, $brain),
            $this->auditUnexpectedNoindex($urlables, $siteProfile),
            $this->auditProductIdentifiers($knowledge['products'] ?? []),
            $this->auditProductReviews($knowledge['products'] ?? []),
            $this->auditProductSchemaEssentials($knowledge['products'] ?? []),
            $this->auditVariationGaps($knowledge['products'] ?? []),
            $this->auditUnresolvedInternalLinks($brain),
            $this->auditWeakHubs($brain),
            $this->auditLinkOpportunities($brain),
            $this->auditAnchorKeywordMismatch($brain, $keywordMap)
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
            'version' => '1.1',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'Findings are evidence-based heuristics for AI workflows, not Google ranking scores.',
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
                    'basic' => $entity['basic'] ?? [],
                    'content' => $entity['content'] ?? [],
                    'seo' => $entity['seo'] ?? [],
                    'structure' => $entity['structure'] ?? [],
                    'media' => $entity['media'] ?? [],
                    'identifiers' => $entity['identifiers'] ?? [],
                    'ratings' => $entity['ratings'] ?? [],
                    'reviews' => $entity['reviews'] ?? [],
                    'total_sales' => (int)($entity['basic']['total_sales'] ?? 0),
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
                'basic' => $entity['basic'] ?? [],
                'content' => $entity['content'] ?? [],
                'seo' => $entity['seo'] ?? [],
                'structure' => $entity['structure'] ?? [],
                'media' => $entity['media'] ?? [],
                'identifiers' => [],
                'ratings' => [],
                'reviews' => [],
                'total_sales' => 0,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditSiteVisibility(array $siteProfile): array
    {
        $visible = $siteProfile['crawl']['search_engine_visibility'] ?? null;
        $blogPublic = (string)($siteProfile['crawl']['blog_public'] ?? '');

        if ($visible === true || ($visible === null && $blogPublic !== '0')) {
            return [];
        }

        $item = [
            'entity_type' => 'site',
            'entity_id' => 0,
            'title' => (string)($siteProfile['identity']['name'] ?? 'site'),
            'url' => (string)($siteProfile['identity']['url'] ?? ''),
        ];

        return [
            $this->finding(
                $item,
                'site_discourages_search_engines',
                'critical',
                'enable_search_engine_visibility',
                'WordPress is set to discourage search engines (blog_public=0). Fix this before other SEO work.',
                [
                    'blog_public' => $blogPublic !== '' ? $blogPublic : '0',
                    'search_engine_visibility' => false,
                ]
            ),
        ];
    }

    private function auditMissingSeoFields(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            $seoTitle = trim((string)($item['seo']['title'] ?? ''));
            $seoDesc = trim((string)($item['seo']['description'] ?? ''));
            $resolvedTitle = trim((string)($item['seo']['resolved_title'] ?? ''));
            $resolvedDesc = trim((string)($item['seo']['resolved_description'] ?? ''));

            if ($seoTitle === '') {
                $findings[] = $this->finding(
                    $item,
                    'missing_seo_title',
                    'warning',
                    'generate_title',
                    $resolvedTitle !== ''
                        ? 'Plugin SEO title is empty; WordPress/theme fallback title exists as resolved_title.'
                        : 'SEO title is missing and no fallback title was resolved.',
                    [
                        'seo_title' => $seoTitle,
                        'resolved_title' => $resolvedTitle,
                        'has_fallback_title' => $resolvedTitle !== '',
                    ]
                );
            }

            if ($seoDesc === '') {
                $findings[] = $this->finding(
                    $item,
                    'missing_meta_description',
                    'warning',
                    'generate_meta_description',
                    $resolvedDesc !== ''
                        ? 'Plugin meta description is empty; a fallback resolved_description exists.'
                        : 'Meta description is missing and no fallback was resolved.',
                    [
                        'seo_description' => $seoDesc,
                        'resolved_description' => $resolvedDesc,
                        'has_fallback_description' => $resolvedDesc !== '',
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
    private function auditSeoFieldLengths(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            $title = trim((string)($item['seo']['resolved_title'] ?? $item['seo']['title'] ?? ''));
            $desc = trim((string)($item['seo']['resolved_description'] ?? $item['seo']['description'] ?? ''));
            $titleLen = $title !== ''
                ? (int)($item['seo']['title_length'] ?? mb_strlen($title, 'UTF-8'))
                : 0;
            $descLen = $desc !== ''
                ? (int)($item['seo']['description_length'] ?? mb_strlen($desc, 'UTF-8'))
                : 0;

            if ($title !== '' && ($titleLen < self::TITLE_MIN_CHARS || $titleLen > self::TITLE_MAX_CHARS)) {
                $findings[] = $this->finding(
                    $item,
                    $titleLen < self::TITLE_MIN_CHARS ? 'seo_title_too_short' : 'seo_title_too_long',
                    'opportunity',
                    'optimize_title_length',
                    'Resolved title length is outside the common SERP guidance range (heuristic, not a Google score).',
                    [
                        'title_length' => $titleLen,
                        'recommended_min' => self::TITLE_MIN_CHARS,
                        'recommended_max' => self::TITLE_MAX_CHARS,
                        'resolved_title' => $title,
                    ]
                );
            }

            if ($desc !== '' && ($descLen < self::DESC_MIN_CHARS || $descLen > self::DESC_MAX_CHARS)) {
                $findings[] = $this->finding(
                    $item,
                    $descLen < self::DESC_MIN_CHARS ? 'meta_description_too_short' : 'meta_description_too_long',
                    'opportunity',
                    'optimize_meta_description_length',
                    'Resolved meta description length is outside the common SERP guidance range (heuristic).',
                    [
                        'description_length' => $descLen,
                        'recommended_min' => self::DESC_MIN_CHARS,
                        'recommended_max' => self::DESC_MAX_CHARS,
                        'resolved_description' => $desc,
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
    private function auditCanonicals(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            if (($item['entity_type'] ?? '') === 'category') {
                // Term canonical empty is common; only flag mismatch when set.
            }

            $canonical = trim((string)($item['seo']['canonical'] ?? ''));
            $url = trim((string)($item['url'] ?? ''));

            if ($canonical === '') {
                if (($item['entity_type'] ?? '') === 'category') {
                    continue;
                }

                $findings[] = $this->finding(
                    $item,
                    'missing_canonical',
                    'opportunity',
                    'set_canonical',
                    'Explicit canonical is empty; confirm the theme/plugin emits a self-referencing canonical.',
                    [
                        'canonical' => '',
                        'permalink' => $url,
                    ]
                );
                continue;
            }

            if ($url === '') {
                continue;
            }

            if ($this->normalizeUrl($canonical) !== $this->normalizeUrl($url)) {
                $findings[] = $this->finding(
                    $item,
                    'canonical_mismatch',
                    'warning',
                    'review_canonical',
                    'Canonical URL differs from the entity permalink.',
                    [
                        'canonical' => $canonical,
                        'permalink' => $url,
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
    private function auditKeywordCannibalization(array $items, array $keywordMap = []): array
    {
        $findings = [];
        $emitted = [];

        if (!empty($keywordMap['cannibalization']) && is_array($keywordMap['cannibalization'])) {
            foreach ($keywordMap['cannibalization'] as $group) {
                $entities = $group['entities'] ?? [];
                if (count($entities) < 2) {
                    continue;
                }

                $match = (string)($group['match'] ?? 'exact_normalized');
                $severity = $match === 'fuzzy' ? 'warning' : 'critical';
                $type = $match === 'fuzzy' ? 'keyword_cannibalization_fuzzy' : 'keyword_cannibalization';

                foreach ($entities as $ent) {
                    $item = [
                        'entity_type' => (string)($ent['entity_type'] ?? 'unknown'),
                        'entity_id' => (int)($ent['entity_id'] ?? 0),
                        'title' => '',
                        'url' => (string)($ent['url'] ?? ''),
                    ];
                    $fid = $item['entity_type'] . ':' . $item['entity_id'] . ':' . $type;
                    if (isset($emitted[$fid])) {
                        continue;
                    }
                    $emitted[$fid] = true;

                    $findings[] = $this->finding(
                        $item,
                        $type,
                        $severity,
                        'resolve_keyword_cannibalization',
                        $match === 'fuzzy'
                            ? 'Similar focus keywords compete across multiple published URLs.'
                            : 'Focus keyword is used on multiple published URLs.',
                        [
                            'focus_keyword' => $group['keyword'] ?? '',
                            'related_keywords' => $group['related_keywords'] ?? [],
                            'match' => $match,
                            'competing_urls' => $group['urls'] ?? [],
                        ]
                    );
                }
            }

            return $findings;
        }

        $byKeyword = [];

        foreach ($items as $item) {
            $keyword = TextMetrics::normalizeKeyword((string)($item['seo']['focus_keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }
            $byKeyword[$keyword][] = $item;
        }

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
    private function auditKeywordCoverage(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            if (($item['entity_type'] ?? '') === 'category') {
                continue;
            }

            $primary = trim((string)($item['seo']['focus_keyword'] ?? ''));
            if ($primary === '') {
                continue;
            }

            $coverage = $item['seo']['keyword_coverage'] ?? null;
            if (!is_array($coverage)) {
                $coverage = $this->inlineCoverage($item, $primary);
            }

            $score = (int)($coverage['score'] ?? 0);
            $missing = [];
            foreach (['in_title', 'in_meta', 'in_slug', 'in_first_paragraph', 'in_h2', 'in_image_alt'] as $flag) {
                if (empty($coverage[$flag])) {
                    $missing[] = $flag;
                }
            }

            if ($score >= 70 && count($missing) <= 2) {
                continue;
            }

            $findings[] = $this->finding(
                $item,
                'weak_keyword_coverage',
                $score < 40 ? 'warning' : 'opportunity',
                'improve_keyword_placement',
                'Focus keyword is weakly placed across title/meta/slug/body/headings/alt.',
                [
                    'focus_keyword' => $primary,
                    'coverage_score' => $score,
                    'missing' => $missing,
                    'coverage' => $coverage,
                ]
            );

            $secondary = $item['seo']['secondary_keywords'] ?? [];
            if (!is_array($secondary) || $secondary === []) {
                continue;
            }

            $body = (string)($item['content']['content']
                ?? $item['content']['description']
                ?? '');
            $headingText = '';
            foreach ($item['structure']['headings'] ?? [] as $h) {
                $headingText .= ' ' . (string)($h['text'] ?? '');
            }

            $uncovered = [];
            foreach ($secondary as $sk) {
                $sk = trim((string)$sk);
                if ($sk === '') {
                    continue;
                }
                if (
                    !TextMetrics::textContainsKeyword($body, $sk)
                    && !TextMetrics::textContainsKeyword($headingText, $sk)
                ) {
                    $uncovered[] = $sk;
                }
            }

            if ($uncovered !== []) {
                $findings[] = $this->finding(
                    $item,
                    'secondary_keywords_uncovered',
                    'opportunity',
                    'cover_secondary_keywords',
                    'Secondary keywords are not visible in body/headings.',
                    ['uncovered' => array_slice($uncovered, 0, 5)]
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string,mixed>
     */
    private function inlineCoverage(array $item, string $primary): array
    {
        $seo = $item['seo'] ?? [];
        $title = (string)($seo['resolved_title'] ?? $seo['title'] ?? $item['title'] ?? '');
        $meta = (string)($seo['resolved_description'] ?? $seo['description'] ?? '');
        $slug = str_replace(['-', '_'], ' ', (string)($item['basic']['slug'] ?? ''));
        $body = (string)($item['content']['content'] ?? $item['content']['description'] ?? '');
        $first = mb_substr($body, 0, 400, 'UTF-8');

        $inH2 = false;
        foreach ($item['structure']['headings'] ?? [] as $heading) {
            if ((int)($heading['level'] ?? 0) === 2
                && TextMetrics::textContainsKeyword((string)($heading['text'] ?? ''), $primary)
            ) {
                $inH2 = true;
                break;
            }
        }

        $checks = [
            'in_title' => TextMetrics::textContainsKeyword($title, $primary),
            'in_meta' => TextMetrics::textContainsKeyword($meta, $primary),
            'in_slug' => TextMetrics::textContainsKeyword($slug, $primary),
            'in_first_paragraph' => TextMetrics::textContainsKeyword($first, $primary),
            'in_h2' => $inH2,
            'in_image_alt' => false,
        ];
        $passed = count(array_filter($checks));

        return array_merge($checks, [
            'score' => (int)round(($passed / max(1, count($checks))) * 100),
            'has_primary' => true,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function auditFaqSchemaGaps(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            $faqs = $item['structure']['faq_candidates'] ?? [];
            if (!is_array($faqs) || count($faqs) < 2) {
                continue;
            }

            $schema = $item['seo']['schema_types'] ?? [];
            $hasFaqSchema = false;
            foreach ($schema as $type) {
                if (stripos((string)$type, 'faq') !== false) {
                    $hasFaqSchema = true;
                    break;
                }
            }

            if ($hasFaqSchema) {
                continue;
            }

            $findings[] = $this->finding(
                $item,
                'faq_candidates_without_schema',
                'opportunity',
                'add_faq_schema',
                'Content has FAQ-like blocks but no FAQ schema type is declared in plugin meta.',
                [
                    'faq_candidate_count' => count($faqs),
                    'schema_types' => $schema,
                    'note' => 'schema_types reflects plugin claims, not necessarily rendered JSON-LD.',
                ]
            );
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
                $title = trim((string)($item['title'] ?? ''));
                if ($title !== '') {
                    $findings[] = $this->finding(
                        $item,
                        'h1_likely_from_theme',
                        'opportunity',
                        'verify_theme_h1',
                        'No H1 in stored content HTML; themes usually output the post title as H1. Verify in the rendered page.',
                        [
                            'h1_count' => $h1Count,
                            'heading_count' => count($headings),
                            'likely_theme_h1' => $title,
                        ]
                    );
                } else {
                    $findings[] = $this->finding(
                        $item,
                        'missing_h1',
                        'warning',
                        'add_h1',
                        'Content has no H1 heading and no usable title fallback.',
                        ['h1_count' => $h1Count, 'heading_count' => count($headings)]
                    );
                }
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
            $sales = (int)($product['basic']['total_sales'] ?? 0);

            if (!$hasImage) {
                $findings[] = $this->finding(
                    $item,
                    'product_missing_image',
                    'critical',
                    'add_product_image',
                    'Product has no featured image.',
                    [
                        'featured' => $featured,
                        'total_sales' => $sales,
                        'priority_note' => $sales >= 10
                            ? 'High-sales product — fix before lower-traffic SKUs.'
                            : null,
                    ]
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

            if ($count > self::THIN_CATEGORY_MAX_COUNT) {
                continue;
            }

            if ($description !== '' && $wordCount >= 40) {
                continue;
            }

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
                'Category looks thin (few products and weak/empty description).',
                [
                    'product_count' => $count,
                    'word_count' => $wordCount,
                    'description_empty' => $description === '',
                ]
            );
        }

        return $findings;
    }

    /**
     * Large categories with empty/weak copy — previously skipped by thin_category.
     *
     * @param list<array<string,mixed>> $categories
     * @return list<array<string,mixed>>
     */
    private function auditCategoryContentGaps(array $categories): array
    {
        $findings = [];

        foreach ($categories as $category) {
            $count = (int)($category['basic']['count'] ?? 0);
            $description = trim((string)($category['content']['description'] ?? ''));
            $wordCount = (int)($category['content']['word_count'] ?? 0);
            $seoTitle = trim((string)($category['seo']['title'] ?? ''));

            if ($count <= self::THIN_CATEGORY_MAX_COUNT) {
                continue;
            }

            $item = [
                'entity_type' => 'category',
                'entity_id' => (int)($category['basic']['id'] ?? 0),
                'title' => (string)($category['basic']['name'] ?? ''),
                'url' => (string)($category['basic']['url'] ?? ''),
            ];

            if ($description === '' || $wordCount < 40) {
                $findings[] = $this->finding(
                    $item,
                    'large_category_empty_description',
                    'warning',
                    'expand_category_description',
                    'Category has meaningful product volume but weak/empty description content.',
                    [
                        'product_count' => $count,
                        'word_count' => $wordCount,
                        'description_empty' => $description === '',
                    ]
                );
            }

            if ($seoTitle === '') {
                $findings[] = $this->finding(
                    $item,
                    'category_missing_seo_title',
                    'opportunity',
                    'generate_title',
                    'Important category is missing an explicit SEO title.',
                    [
                        'product_count' => $count,
                        'resolved_title' => (string)($category['seo']['resolved_title'] ?? ''),
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
    private function auditDuplicateShortDescriptions(array $products): array
    {
        $byShort = [];

        foreach ($products as $product) {
            $status = (string)($product['basic']['status'] ?? '');
            if ($status !== '' && $status !== 'publish') {
                continue;
            }

            $short = mb_strtolower(trim((string)($product['content']['short_description'] ?? '')));
            if ($short === '' || mb_strlen($short, 'UTF-8') < 20) {
                continue;
            }

            $byShort[$short][] = $product;
        }

        $findings = [];

        foreach ($byShort as $short => $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $product) {
                $item = $this->productAsItem($product);
                $findings[] = $this->finding(
                    $item,
                    'duplicate_product_short_description',
                    'warning',
                    'rewrite_product_short_description',
                    'Product short description is duplicated across multiple products.',
                    [
                        'duplicate_count' => count($group),
                        'competing_urls' => $this->otherUrls(
                            array_map(fn(array $p): array => $this->productAsItem($p), $group),
                            $item
                        ),
                        'short_description_sample' => mb_substr((string)$short, 0, 120, 'UTF-8'),
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
            if (($item['entity_type'] ?? '') === 'category') {
                continue;
            }

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
    private function auditUnexpectedNoindex(array $items, array $siteProfile = []): array
    {
        $findings = [];
        $excludedIds = $this->intentionalNoindexPageIds($siteProfile);

        foreach ($items as $item) {
            $index = $item['seo']['robots']['index'] ?? true;
            if ($index !== false) {
                continue;
            }

            $entityId = (int)($item['entity_id'] ?? 0);
            $entityType = (string)($item['entity_type'] ?? '');
            $url = strtolower((string)($item['url'] ?? ''));
            $title = strtolower((string)($item['title'] ?? ''));

            $intentional = isset($excludedIds[$entityId])
                || preg_match('/(cart|checkout|my-account|myaccount|thank[-_ ]?you|order-received)/u', $url . ' ' . $title);

            if ($intentional || $entityType === 'category') {
                $findings[] = $this->finding(
                    $item,
                    'intentional_noindex_candidate',
                    'opportunity',
                    'confirm_noindex_intent',
                    'URL is noindex; this often looks intentional (Woo utility/thank-you/account). Confirm before changing.',
                    [
                        'robots' => $item['seo']['robots'] ?? [],
                        'likely_intentional' => true,
                    ]
                );
                continue;
            }

            $findings[] = $this->finding(
                $item,
                'unexpected_noindex',
                'warning',
                'review_robots_directives',
                'Published URL is marked noindex. Review whether this is intentional.',
                [
                    'robots' => $item['seo']['robots'] ?? [],
                    'likely_intentional' => false,
                ]
            );
        }

        return $findings;
    }

    /**
     * @return array<int,true>
     */
    private function intentionalNoindexPageIds(array $siteProfile): array
    {
        $ids = [];
        $woo = $siteProfile['special_pages']['woocommerce'] ?? [];

        foreach (['cart', 'checkout', 'myaccount'] as $key) {
            $id = (int)($woo[$key]['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditSpecialPageDiscoverability(array $brain, array $siteProfile): array
    {
        $findings = [];
        $nodesById = [];

        foreach ($brain['knowledge_graph']['internal_link_graph']['nodes'] ?? [] as $node) {
            $nodesById[(int)($node['id'] ?? 0)] = $node;
        }

        $candidates = [];
        $front = $siteProfile['special_pages']['front_page'] ?? null;
        $shop = $siteProfile['special_pages']['woocommerce']['shop'] ?? null;

        if (is_array($front) && (int)($front['id'] ?? 0) > 0) {
            $candidates[] = ['role' => 'front_page', 'page' => $front];
        }
        if (is_array($shop) && (int)($shop['id'] ?? 0) > 0) {
            $candidates[] = ['role' => 'shop', 'page' => $shop];
        }

        foreach ($candidates as $candidate) {
            $page = $candidate['page'];
            $id = (int)$page['id'];
            $node = $nodesById[$id] ?? null;
            if ($node === null) {
                continue;
            }

            $inMenu = !empty($node['in_menu']);
            $isOrphan = !empty($node['is_orphan']);

            if ($inMenu && !$isOrphan) {
                continue;
            }

            $item = [
                'entity_type' => (string)($node['entity_type'] ?? 'page'),
                'entity_id' => $id,
                'title' => (string)($page['title'] ?? $node['title'] ?? ''),
                'url' => (string)($page['url'] ?? $node['url'] ?? ''),
            ];

            $findings[] = $this->finding(
                $item,
                'important_page_low_discoverability',
                'warning',
                'add_to_navigation_or_internal_links',
                'Important site page (home/shop) looks hard to discover via menus/internal links.',
                [
                    'role' => $candidate['role'],
                    'in_menu' => $inMenu,
                    'is_orphan' => $isOrphan,
                    'incoming_count' => (int)($node['incoming_count'] ?? 0),
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
    private function auditUnresolvedInternalLinks(array $brain): array
    {
        $findings = [];
        $bySourceReason = [];

        foreach ($brain['link_analysis']['dead_internal_links'] ?? [] as $dead) {
            $sourceId = (int)($dead['source_id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }

            $reason = (string)($dead['reason'] ?? 'unresolved_target');
            $bucket = $reason === 'missing_target_entity'
                ? 'missing_target_entity'
                : 'unresolved_target';

            $bySourceReason[$sourceId][$bucket][] = $dead;
        }

        foreach ($bySourceReason as $sourceId => $groups) {
            foreach ($groups as $bucket => $links) {
                $first = $links[0];
                $item = [
                    'entity_type' => (string)($first['source_type'] ?? 'unknown'),
                    'entity_id' => $sourceId,
                    'title' => '',
                    'url' => (string)($first['source_url'] ?? ''),
                ];

                if ($bucket === 'missing_target_entity') {
                    $findings[] = $this->finding(
                        $item,
                        'missing_internal_link_target',
                        'warning',
                        'fix_broken_internal_links',
                        'Page links to an internal target id that is not present in the exported published set.',
                        [
                            'link_count' => count($links),
                            'reason' => 'missing_target_entity',
                            'samples' => array_slice($links, 0, 5),
                            'note' => 'This is not a confirmed HTTP 404; verify before deleting links.',
                        ]
                    );
                    continue;
                }

                $findings[] = $this->finding(
                    $item,
                    'unresolved_internal_target',
                    'opportunity',
                    'review_unresolved_internal_links',
                    'Page has internal URLs that could not be resolved to a post/page id (archives, filters, or non-exported URLs are common).',
                    [
                        'link_count' => count($links),
                        'reason' => 'unresolved_target',
                        'samples' => array_slice($links, 0, 5),
                        'note' => 'Do not treat these as proven 404s without a crawl/HTTP check.',
                    ]
                );
            }
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

    /**
     * @return list<array<string,mixed>>
     */
    private function auditPluginGlobalNoindex(array $siteProfile): array
    {
        $globals = $siteProfile['seo_plugin_globals'] ?? [];
        if (!is_array($globals) || $globals === []) {
            return [];
        }

        $findings = [];
        $item = [
            'entity_type' => 'site',
            'entity_id' => 0,
            'title' => (string)($siteProfile['identity']['name'] ?? 'site'),
            'url' => (string)($siteProfile['identity']['url'] ?? ''),
        ];

        foreach ($globals['noindex_types'] ?? [] as $type => $flag) {
            if (!$flag) {
                continue;
            }
            $findings[] = $this->finding(
                $item,
                'global_noindex_content_type',
                'critical',
                'review_global_noindex',
                'SEO plugin global settings mark a content type as noindex.',
                [
                    'content_type' => $type,
                    'plugin' => $globals['plugin'] ?? null,
                ]
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditTopicGaps(array $knowledge, array $brain): array
    {
        $findings = [];
        $clusters = $brain['content_clusters'] ?? $knowledge['content_clusters'] ?? [];

        foreach ($clusters as $cluster) {
            $productCount = count($cluster['products'] ?? []);
            $postCount = count($cluster['posts'] ?? []);
            $categoryId = (int)($cluster['category_id'] ?? 0);
            $name = (string)($cluster['category'] ?? '');

            if ($productCount < 5) {
                continue;
            }

            if ($postCount === 0) {
                $findings[] = $this->finding(
                    [
                        'entity_type' => 'category',
                        'entity_id' => $categoryId,
                        'title' => $name,
                        'url' => (string)($cluster['url'] ?? ''),
                    ],
                    'category_missing_buying_guide',
                    'opportunity',
                    'create_buying_guide_post',
                    'Large product category has no related blog/guide posts in its cluster.',
                    [
                        'product_count' => $productCount,
                        'post_count' => $postCount,
                        'slug' => $cluster['slug'] ?? '',
                    ]
                );
            }

            if (empty($cluster['target_keywords']) && empty($cluster['pillars'])) {
                $findings[] = $this->finding(
                    [
                        'entity_type' => 'category',
                        'entity_id' => $categoryId,
                        'title' => $name,
                        'url' => (string)($cluster['url'] ?? ''),
                    ],
                    'topic_gap_no_pillar',
                    'opportunity',
                    'designate_pillar_content',
                    'Topic cluster has no cornerstone/pillar content detected.',
                    [
                        'product_count' => $productCount,
                        'post_count' => $postCount,
                        'avg_word_count' => $cluster['avg_word_count'] ?? null,
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
    private function auditProductSchemaEssentials(array $products): array
    {
        $findings = [];

        foreach ($products as $product) {
            $status = (string)($product['basic']['status'] ?? '');
            if ($status !== '' && $status !== 'publish') {
                continue;
            }

            $sales = (int)($product['basic']['total_sales'] ?? 0);
            $wordCount = (int)($product['content']['word_count'] ?? 0);
            if ($sales < 1 && $wordCount < self::KEY_PRODUCT_MIN_WORDS) {
                continue;
            }

            $item = $this->productAsItem($product);
            $missing = [];

            $brand = trim((string)($product['identifiers']['brand'] ?? ''));
            $gtin = trim((string)($product['identifiers']['gtin'] ?? ''));
            $ean = trim((string)($product['identifiers']['ean'] ?? ''));
            $mpn = trim((string)($product['identifiers']['mpn'] ?? ''));
            $price = trim((string)($product['pricing']['price'] ?? ''));
            $hasImage = !empty($product['media']['featured']['id']) || !empty($product['media']['featured']['url']);
            $reviewCount = (int)($product['ratings']['review_count'] ?? count($product['reviews'] ?? []));

            if ($brand === '') {
                $missing[] = 'brand';
            }
            if ($gtin === '' && $ean === '' && $mpn === '') {
                $missing[] = 'gtin_or_mpn';
            }
            if (!$hasImage) {
                $missing[] = 'image';
            }
            if ($price === '' || !is_numeric($price)) {
                $missing[] = 'price';
            }
            if ($reviewCount <= 0) {
                $missing[] = 'reviews';
            }

            if (count($missing) < 2) {
                continue;
            }

            $severity = $sales >= 10 ? 'warning' : 'opportunity';

            $findings[] = $this->finding(
                $item,
                'product_schema_essentials_incomplete',
                $severity,
                'complete_product_schema_fields',
                'Product is missing multiple fields commonly needed for rich Product results.',
                [
                    'missing' => $missing,
                    'total_sales' => $sales,
                    'word_count' => $wordCount,
                    'schema_types' => $product['seo']['schema_types'] ?? [],
                    'note' => 'Heuristic checklist — not a live rich-result test.',
                ]
            );
        }

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private function auditVariationGaps(array $products): array
    {
        $findings = [];

        foreach ($products as $product) {
            if ((string)($product['basic']['type'] ?? '') !== 'variable') {
                continue;
            }
            $status = (string)($product['basic']['status'] ?? '');
            if ($status !== '' && $status !== 'publish') {
                continue;
            }

            $sales = (int)($product['basic']['total_sales'] ?? 0);
            $variations = $product['variations'] ?? [];
            if (!is_array($variations) || $variations === []) {
                continue;
            }

            $missingGtin = 0;
            foreach ($variations as $variation) {
                $vGtin = trim((string)($variation['gtin'] ?? ''));
                if ($vGtin === '') {
                    $missingGtin++;
                }
            }

            if ($missingGtin === 0) {
                continue;
            }

            if ($sales < 1 && count($variations) < 3) {
                continue;
            }

            $item = $this->productAsItem($product);
            $findings[] = $this->finding(
                $item,
                'variable_product_missing_variation_gtin',
                $sales >= 5 ? 'warning' : 'opportunity',
                'add_variation_identifiers',
                'Variable product has variations without GTIN.',
                [
                    'variation_count' => count($variations),
                    'missing_gtin_count' => $missingGtin,
                    'total_sales' => $sales,
                ]
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditLinkOpportunities(array $brain): array
    {
        $findings = [];
        $opportunities = $brain['link_analysis']['link_opportunities']
            ?? $brain['knowledge_graph']['internal_link_graph']['analysis']['link_opportunities']
            ?? [];

        foreach (array_slice($opportunities, 0, 40) as $opp) {
            $item = [
                'entity_type' => (string)($opp['entity_type'] ?? 'unknown'),
                'entity_id' => (int)($opp['entity_id'] ?? $opp['id'] ?? 0),
                'title' => (string)($opp['title'] ?? ''),
                'url' => (string)($opp['url'] ?? ''),
            ];

            $findings[] = $this->finding(
                $item,
                'suggested_internal_link',
                'opportunity',
                'add_suggested_internal_links',
                'Suggested internal link sources exist for this under-linked URL.',
                [
                    'suggested_sources' => array_slice($opp['suggested_sources'] ?? [], 0, 5),
                    'suggested_anchor' => $opp['suggested_anchor'] ?? '',
                    'reason' => $opp['reason'] ?? 'same_cluster_or_orphan',
                ]
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditAnchorKeywordMismatch(array $brain, array $keywordMap): array
    {
        $findings = [];
        $targetsByUrl = [];
        foreach ($keywordMap['url_targets'] ?? [] as $t) {
            $u = $this->normalizeUrl((string)($t['url'] ?? ''));
            if ($u !== '') {
                $targetsByUrl[$u] = $t;
            }
        }

        foreach ($brain['link_analysis']['duplicate_anchors'] ?? [] as $page) {
            foreach ($page['duplicates'] ?? [] as $dup) {
                $targets = $dup['targets'] ?? [];
                if (count($targets) < 2) {
                    continue;
                }
                $item = [
                    'entity_type' => (string)($page['entity_type'] ?? 'unknown'),
                    'entity_id' => (int)($page['id'] ?? 0),
                    'title' => (string)($page['title'] ?? ''),
                    'url' => (string)($page['url'] ?? ''),
                ];
                $findings[] = $this->finding(
                    $item,
                    'duplicate_anchor_text',
                    'opportunity',
                    'diversify_anchor_text',
                    'Same anchor text points to multiple different targets on one page.',
                    [
                        'anchor' => $dup['anchor'] ?? '',
                        'count' => $dup['count'] ?? count($targets),
                        'targets' => $targets,
                    ]
                );
            }
        }

        // Sample outgoing links from nodes for anchor vs target focus mismatch
        $nodes = $brain['knowledge_graph']['internal_link_graph']['nodes']
            ?? $brain['link_analysis']['nodes']
            ?? [];

        $checked = 0;
        foreach ($nodes as $node) {
            if ($checked >= 80) {
                break;
            }
            foreach ($node['outgoing_links'] ?? [] as $link) {
                $targetUrl = $this->normalizeUrl((string)($link['target_url'] ?? ''));
                $anchor = trim((string)($link['anchor'] ?? ''));
                if ($targetUrl === '' || $anchor === '' || mb_strlen($anchor, 'UTF-8') < 3) {
                    continue;
                }
                $target = $targetsByUrl[$targetUrl] ?? null;
                $primary = trim((string)($target['primary'] ?? ''));
                if ($primary === '') {
                    continue;
                }
                if (TextMetrics::textContainsKeyword($anchor, $primary)) {
                    continue;
                }
                // Only flag generic anchors
                $generic = ['اینجا', 'اینجا کلیک کنید', 'کلیک کنید', 'بیشتر', 'more', 'click here', 'here', 'read more', 'ادامه مطلب'];
                $anchorNorm = TextMetrics::normalizeKeyword($anchor);
                $isGeneric = in_array($anchorNorm, array_map([TextMetrics::class, 'normalizeKeyword'], $generic), true);
                if (!$isGeneric) {
                    continue;
                }

                $item = [
                    'entity_type' => (string)($node['entity_type'] ?? 'unknown'),
                    'entity_id' => (int)($node['id'] ?? 0),
                    'title' => (string)($node['title'] ?? ''),
                    'url' => (string)($node['url'] ?? ''),
                ];
                $findings[] = $this->finding(
                    $item,
                    'generic_anchor_for_keyworded_target',
                    'opportunity',
                    'improve_anchor_text',
                    'Generic anchor text links to a page that has a focus keyword — prefer descriptive anchors.',
                    [
                        'anchor' => $anchor,
                        'target_url' => $link['target_url'] ?? '',
                        'target_focus_keyword' => $primary,
                    ]
                );
                $checked++;
                break;
            }
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

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (function_exists('home_url') && str_starts_with($url, '/')) {
            $url = home_url($url);
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return rtrim(strtolower($url), '/');
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

        return rtrim($host . $path, '/') . $query;
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
