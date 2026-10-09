<?php

declare(strict_types=1);

/**
 * Compact pack for Gemini Gem / ChatGPT / agents.
 * Start here instead of stuffing full knowledge + site_brain into the model.
 */
class AiContextBuilder
{
    private const MAX_CRITICAL = 40;
    private const MAX_WARNING = 40;
    private const MAX_OPPORTUNITY = 10;
    private const MAX_URL_INDEX = 500;

    public function build(
        array $knowledge,
        array $brain,
        array $audit,
        array $files = []
    ): array {
        $profile = $brain['site_profile'] ?? [];
        $linkSummary = $brain['link_analysis']['summary'] ?? [];

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'purpose' => 'Primary AI starter pack. Use this first for overview and priorities; open full files only when drilling into a specific URL or cluster.',
            'how_to_use' => [
                'overview' => 'Read site_profile_summary + counts + seo_audit.summary',
                'priorities' => 'Work seo_audit.top_findings in severity order (critical → warning → opportunity)',
                'one_url' => 'Look up url_index, then open that entity in knowledge.json',
                'internal_linking' => 'Use internal_link_graph.json / site_brain.link_analysis',
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
            'seo_audit' => [
                'summary' => $audit['summary'] ?? [
                    'critical' => 0,
                    'warning' => 0,
                    'opportunity' => 0,
                    'total' => 0,
                ],
                'note' => $audit['note'] ?? 'Findings are heuristics for AI workflows, not Google ranking scores.',
                'top_findings' => $this->topFindings($audit['findings'] ?? []),
                'capped' => true,
            ],
            'url_index' => $this->urlIndex($brain, $knowledge),
            'files' => $files !== [] ? $files : [
                'ai_context' => 'json/ai_context.json',
                'manifest' => 'json/manifest.json',
                'site_profile' => 'json/site_profile.json',
                'seo_audit' => 'json/seo_audit.json',
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

        return [
            'identity' => $profile['identity'] ?? [],
            'crawl' => [
                'blog_public' => $profile['crawl']['blog_public'] ?? null,
                'search_engine_visibility' => $profile['crawl']['search_engine_visibility'] ?? null,
                'permalink_structure' => $profile['crawl']['permalink_structure'] ?? null,
                'https' => $profile['crawl']['https'] ?? null,
                'host' => $profile['crawl']['host'] ?? null,
                'sitemaps' => $profile['crawl']['sitemaps'] ?? [],
                'robots_txt' => $robots,
            ],
            'special_pages' => $profile['special_pages'] ?? [],
            'seo_plugins' => $profile['seo_plugins'] ?? [],
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
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function topFindings(array $findings): array
    {
        $buckets = [
            'critical' => [],
            'warning' => [],
            'opportunity' => [],
        ];

        foreach ($findings as $finding) {
            $severity = (string)($finding['severity'] ?? '');
            if (!isset($buckets[$severity])) {
                continue;
            }
            $buckets[$severity][] = $this->slimFinding($finding);
        }

        return array_merge(
            array_slice($buckets['critical'], 0, self::MAX_CRITICAL),
            array_slice($buckets['warning'], 0, self::MAX_WARNING),
            array_slice($buckets['opportunity'], 0, self::MAX_OPPORTUNITY)
        );
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
            'evidence' => $evidence,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function urlIndex(array $brain, array $knowledge): array
    {
        $index = [];

        $entityIndex = $brain['entity_index'] ?? null;
        if (is_array($entityIndex)) {
            foreach (['products', 'categories', 'posts', 'pages'] as $bucket) {
                foreach ($entityIndex[$bucket] ?? [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $index[] = [
                        'id' => (int)($row['id'] ?? 0),
                        'type' => (string)($row['type'] ?? $bucket),
                        'title' => (string)($row['title'] ?? ''),
                        'url' => (string)($row['url'] ?? ''),
                        'word_count' => (int)($row['word_count'] ?? 0),
                        'seo_title' => (string)($row['seo_title'] ?? ''),
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
                    $index[] = [
                        'id' => (int)($entity['basic']['id'] ?? 0),
                        'type' => $type,
                        'title' => (string)($entity['basic']['title'] ?? $entity['basic']['name'] ?? ''),
                        'url' => (string)($entity['basic']['url'] ?? ''),
                        'word_count' => (int)($entity['content']['word_count'] ?? 0),
                        'seo_title' => (string)($entity['seo']['title'] ?? ''),
                    ];
                }
            }
        }

        if (count($index) > self::MAX_URL_INDEX) {
            $index = array_slice($index, 0, self::MAX_URL_INDEX);
        }

        return $index;
    }
}
