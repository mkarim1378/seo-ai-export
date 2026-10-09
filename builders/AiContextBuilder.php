<?php

declare(strict_types=1);

/**
 * Compact pack for Gemini Gem / ChatGPT / agents.
 * Start here instead of stuffing full knowledge + site_brain into the model.
 */
class AiContextBuilder
{
    private const MAX_TOP_FINDINGS = 90;
    private const MAX_NEXT_ACTIONS = 10;
    private const MAX_URL_INDEX = 500;
    private const MAX_KEYWORD_SUGGESTIONS = 25;
    private const MAX_KEYWORD_GAPS = 20;

    public function build(
        array $knowledge,
        array $brain,
        array $audit,
        array $files = [],
        array $keywordMap = []
    ): array {
        $profile = $brain['site_profile'] ?? [];
        $linkSummary = $brain['link_analysis']['summary'] ?? [];
        $findings = $audit['findings'] ?? [];
        $scored = $this->scoreFindings($findings, $brain, $knowledge);
        $top = array_slice($scored, 0, self::MAX_TOP_FINDINGS);

        return [
            'version' => '2.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'purpose' => 'Primary AI starter pack. Use this first for overview and priorities; open full files only when drilling into a specific URL or cluster.',
            'how_to_use' => [
                'overview' => 'Read site_profile_summary + counts + seo_audit.summary',
                'priorities' => 'Work next_actions then seo_audit.top_findings ordered by impact_score',
                'keywords' => 'Use keyword_intelligence for inventory, gaps, and suggested primaries',
                'one_url' => 'Look up url_index, then open that entity in knowledge.json',
                'internal_linking' => 'Use internal_link_graph.json / site_brain.link_analysis.link_opportunities',
                'ignore' => 'Ignore Rank Math/Yoast vanity scores if they appear anywhere',
            ],
            'site_profile_summary' => $this->summarizeSiteProfile($profile),
            'counts' => [
                'products' => count($knowledge['products'] ?? []),
                'categories' => count($knowledge['categories'] ?? []),
                'posts' => count($knowledge['posts'] ?? []),
                'pages' => count($knowledge['pages'] ?? []),
                'media' => count($knowledge['media'] ?? []),
            ],
            'link_analysis_summary' => $linkSummary,
            'keyword_intelligence' => $this->summarizeKeywords($keywordMap),
            'seo_audit' => [
                'summary' => $audit['summary'] ?? [
                    'critical' => 0,
                    'warning' => 0,
                    'opportunity' => 0,
                    'total' => 0,
                ],
                'note' => $audit['note'] ?? 'Findings are heuristics for AI workflows, not Google ranking scores.',
                'top_findings' => array_map([$this, 'slimFinding'], $top),
                'sorted_by' => 'impact_score',
                'capped' => true,
            ],
            'next_actions' => array_map(
                [$this, 'slimFinding'],
                array_slice($scored, 0, self::MAX_NEXT_ACTIONS)
            ),
            'url_index' => $this->urlIndex($brain, $knowledge, $audit),
            'files' => $files !== [] ? $files : [
                'ai_context' => 'json/ai_context.json',
                'manifest' => 'json/manifest.json',
                'site_profile' => 'json/site_profile.json',
                'seo_audit' => 'json/seo_audit.json',
                'keyword_map' => 'json/keyword_map.json',
                'site_brain' => 'json/site_brain.json',
                'internal_link_graph' => 'json/internal_link_graph.json',
                'knowledge' => 'json/knowledge.json',
            ],
        ];
    }

    private function summarizeSiteProfile(array $profile): array
    {
        $robots = (string)($profile['crawl']['robots_txt'] ?? '');
        if (mb_strlen($robots, 'UTF-8') > 800) {
            $robots = mb_substr($robots, 0, 800, 'UTF-8') . "\n…";
        }

        $sitemaps = $profile['crawl']['sitemaps'] ?? [];
        // Back-compat if old string list slipped through
        if ($sitemaps !== [] && is_string($sitemaps[0] ?? null)) {
            $sitemaps = array_map(
                static fn(string $u): array => ['url' => $u, 'reachable' => null],
                $sitemaps
            );
        }

        return [
            'identity' => $profile['identity'] ?? [],
            'crawl' => [
                'blog_public' => $profile['crawl']['blog_public'] ?? null,
                'search_engine_visibility' => $profile['crawl']['search_engine_visibility'] ?? null,
                'permalink_structure' => $profile['crawl']['permalink_structure'] ?? null,
                'https' => $profile['crawl']['https'] ?? null,
                'host' => $profile['crawl']['host'] ?? null,
                'sitemaps' => $sitemaps,
                'robots_txt' => $robots,
            ],
            'special_pages' => $profile['special_pages'] ?? [],
            'seo_plugins' => $profile['seo_plugins'] ?? [],
            'seo_plugin_globals' => [
                'plugin' => $profile['seo_plugin_globals']['plugin'] ?? null,
                'noindex_types' => $profile['seo_plugin_globals']['noindex_types'] ?? [],
            ],
            'technical_context' => [
                'note' => $profile['technical_context']['note']
                    ?? 'Platform/theme versions are environment context only — not SEO ranking scores.',
                'wordpress' => $profile['technical_context']['wordpress'] ?? null,
                'woocommerce' => $profile['technical_context']['woocommerce'] ?? null,
                'theme' => $profile['technical_context']['theme'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeKeywords(array $keywordMap): array
    {
        if ($keywordMap === []) {
            return [
                'enabled' => false,
                'note' => 'keyword_map.json not available in this run.',
            ];
        }

        return [
            'enabled' => true,
            'summary' => $keywordMap['summary'] ?? [],
            'top_suggestions' => array_slice($keywordMap['suggestions'] ?? [], 0, self::MAX_KEYWORD_SUGGESTIONS),
            'top_gaps' => array_slice($keywordMap['gaps'] ?? [], 0, self::MAX_KEYWORD_GAPS),
            'cannibalization_groups' => count($keywordMap['cannibalization'] ?? []),
            'gsc' => [
                'enabled' => !empty($keywordMap['gsc']['enabled']),
                'queries_merged' => $keywordMap['summary']['gsc_queries_merged']
                    ?? $keywordMap['gsc']['queries_merged']
                    ?? 0,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function scoreFindings(array $findings, array $brain, array $knowledge): array
    {
        $inbound = [];
        foreach ($brain['entity_index'] ?? [] as $bucket => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = ($row['type'] ?? '') . ':' . ($row['id'] ?? 0);
                $inbound[$key] = (int)($row['inbound_links'] ?? 0);
            }
        }

        $sales = [];
        $cornerstone = [];
        foreach ($knowledge['products'] ?? [] as $p) {
            $id = (int)($p['basic']['id'] ?? 0);
            $sales['product:' . $id] = (int)($p['basic']['total_sales'] ?? 0);
            if (!empty($p['seo']['is_cornerstone'])) {
                $cornerstone['product:' . $id] = true;
            }
        }
        foreach (['posts' => 'post', 'pages' => 'page'] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $e) {
                $id = (int)($e['basic']['id'] ?? 0);
                if (!empty($e['seo']['is_cornerstone'])) {
                    $cornerstone[$type . ':' . $id] = true;
                }
            }
        }

        $clusterSize = [];
        foreach ($brain['content_clusters'] ?? [] as $cluster) {
            $size = count($cluster['products'] ?? []) + count($cluster['posts'] ?? []);
            foreach (['products' => 'product', 'posts' => 'post', 'pages' => 'page'] as $list => $type) {
                foreach ($cluster[$list] ?? [] as $ref) {
                    $clusterSize[$type . ':' . ($ref['id'] ?? 0)] = $size;
                }
            }
            $cid = (int)($cluster['category_id'] ?? 0);
            if ($cid > 0) {
                $clusterSize['category:' . $cid] = $size;
            }
        }

        $severityWeight = [
            'critical' => 100,
            'warning' => 40,
            'opportunity' => 15,
        ];

        $scored = [];
        foreach ($findings as $finding) {
            $etype = (string)($finding['entity_type'] ?? '');
            $eid = (int)($finding['entity_id'] ?? 0);
            $key = $etype . ':' . $eid;
            $sev = (string)($finding['severity'] ?? 'opportunity');

            $score = $severityWeight[$sev] ?? 10;
            $score += min(40, ($inbound[$key] ?? 0) * 2);
            if (!empty($cornerstone[$key])) {
                $score += 25;
            }
            $score += min(50, (int)floor(($sales[$key] ?? 0) * 0.5));
            $score += min(20, (int)floor(($clusterSize[$key] ?? 0) / 2));
            if ($etype === 'product' || $etype === 'category') {
                $score += 5;
            }

            $finding['impact_score'] = $score;
            $scored[] = $finding;
        }

        usort(
            $scored,
            static function (array $a, array $b): int {
                $cmp = ($b['impact_score'] ?? 0) <=> ($a['impact_score'] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }
                $rank = ['critical' => 0, 'warning' => 1, 'opportunity' => 2];
                return ($rank[$a['severity'] ?? ''] ?? 9) <=> ($rank[$b['severity'] ?? ''] ?? 9);
            }
        );

        return $scored;
    }

    private function slimFinding(array $finding): array
    {
        $evidence = $finding['evidence'] ?? [];
        if (is_array($evidence) && count($evidence) > 8) {
            $evidence = array_slice($evidence, 0, 8, true);
        }

        return [
            'id' => $finding['id'] ?? '',
            'severity' => $finding['severity'] ?? '',
            'type' => $finding['type'] ?? '',
            'entity_type' => $finding['entity_type'] ?? '',
            'entity_id' => $finding['entity_id'] ?? 0,
            'url' => $finding['url'] ?? '',
            'title' => $finding['title'] ?? '',
            'recommendation' => $finding['recommendation'] ?? '',
            'ai_action' => $finding['ai_action'] ?? '',
            'impact_score' => $finding['impact_score'] ?? 0,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function urlIndex(array $brain, array $knowledge, array $audit): array
    {
        $findingsCount = [];
        foreach ($audit['findings'] ?? [] as $finding) {
            $key = ($finding['entity_type'] ?? '') . ':' . ($finding['entity_id'] ?? 0);
            $findingsCount[$key] = ($findingsCount[$key] ?? 0) + 1;
        }

        $index = [];

        $entityIndex = $brain['entity_index'] ?? null;
        if (is_array($entityIndex)) {
            foreach (['products', 'categories', 'posts', 'pages'] as $bucket) {
                foreach ($entityIndex[$bucket] ?? [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $type = (string)($row['type'] ?? $bucket);
                    $id = (int)($row['id'] ?? 0);
                    $key = $type . ':' . $id;
                    $index[] = [
                        'id' => $id,
                        'type' => $type,
                        'title' => (string)($row['title'] ?? ''),
                        'url' => (string)($row['url'] ?? ''),
                        'word_count' => (int)($row['word_count'] ?? 0),
                        'seo_title' => (string)($row['seo_title'] ?? ''),
                        'focus_keyword' => (string)($row['focus_keyword'] ?? ''),
                        'is_cornerstone' => !empty($row['is_cornerstone']),
                        'inbound_links' => (int)($row['inbound_links'] ?? 0),
                        'findings_count' => $findingsCount[$key] ?? 0,
                        'total_sales' => (int)($row['total_sales'] ?? 0),
                    ];
                }
            }
        } else {
            foreach ([
                'products' => 'product',
                'categories' => 'category',
                'posts' => 'post',
                'pages' => 'page',
            ] as $bucket => $type) {
                foreach ($knowledge[$bucket] ?? [] as $entity) {
                    $id = (int)($entity['basic']['id'] ?? 0);
                    $key = $type . ':' . $id;
                    $index[] = [
                        'id' => $id,
                        'type' => $type,
                        'title' => (string)($entity['basic']['title'] ?? $entity['basic']['name'] ?? ''),
                        'url' => (string)($entity['basic']['url'] ?? ''),
                        'word_count' => (int)($entity['content']['word_count'] ?? 0),
                        'seo_title' => (string)($entity['seo']['title'] ?? ''),
                        'focus_keyword' => (string)($entity['seo']['focus_keyword'] ?? ''),
                        'is_cornerstone' => !empty($entity['seo']['is_cornerstone']),
                        'inbound_links' => 0,
                        'findings_count' => $findingsCount[$key] ?? 0,
                        'total_sales' => (int)($entity['basic']['total_sales'] ?? 0),
                    ];
                }
            }
        }

        usort(
            $index,
            static function (array $a, array $b): int {
                $cmp = ($b['findings_count'] ?? 0) <=> ($a['findings_count'] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return ($b['inbound_links'] ?? 0) <=> ($a['inbound_links'] ?? 0);
            }
        );

        if (count($index) > self::MAX_URL_INDEX) {
            $index = array_slice($index, 0, self::MAX_URL_INDEX);
        }

        return $index;
    }
}
