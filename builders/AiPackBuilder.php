<?php

declare(strict_types=1);

/**
 * Curated upload pack for Gemini Gem / ChatGPT / Claude Projects.
 * Full payloads only — no summarize/truncate of source datasets.
 * Knowledge is split by entity type so large sites fit upload/token budgets better.
 * Pack JSON is written compact (minified) by AiPackExporter.
 */
class AiPackBuilder
{
    /** Core pack files excluding knowledge-* parts. */
    public const CORE_FILE_COUNT = 7;

    /** @var list<string> */
    public const KNOWLEDGE_SECTIONS = [
        'products',
        'categories',
        'posts',
        'pages',
        'media',
    ];

    /**
     * @return array{
     *   files: array<string, array>,
     *   paths: array<string, string>,
     *   manifest: array
     * }
     */
    public function build(
        array $knowledge,
        array $brain,
        array $audit,
        array $aiContext,
        array $keywordMap,
        array $siteProfile,
        array $redirectMap,
        array $hreflang,
        array $sitemapCoverage,
        array $mediaSeo,
        array $contentDuplicates,
        array $auditDiff,
        ?string $version = null
    ): array {
        $generatedAt = function_exists('current_time')
            ? current_time('mysql')
            : date('Y-m-d H:i:s');

        $linkGraph = $brain['knowledge_graph']['internal_link_graph'] ?? [];

        $technical = [
            'version' => '1.0',
            'generated_at' => $generatedAt,
            'purpose' => 'Full technical SEO payloads merged into one uploadable file. Each key matches the standalone json/*.json export.',
            'site_profile' => $siteProfile !== [] ? $siteProfile : ($brain['site_profile'] ?? []),
            'redirect_map' => $redirectMap,
            'hreflang' => $hreflang,
            'sitemap_coverage' => $sitemapCoverage,
            'media_seo' => $mediaSeo,
            'content_duplicates' => $contentDuplicates,
            'audit_diff' => $auditDiff,
        ];

        $knowledgePaths = [];
        foreach (self::KNOWLEDGE_SECTIONS as $section) {
            $knowledgePaths['knowledge_' . $section] = 'ai-pack/knowledge-' . $section . '.json';
        }

        $paths = array_merge(
            [
                'manifest' => 'ai-pack/manifest.json',
                'ai_context' => 'ai-pack/ai_context.json',
                'site_brain' => 'ai-pack/site_brain.json',
                'internal_link_graph' => 'ai-pack/internal_link_graph.json',
                'seo_audit' => 'ai-pack/seo_audit.json',
                'keyword_map' => 'ai-pack/keyword_map.json',
                'technical' => 'ai-pack/technical.json',
            ],
            $knowledgePaths
        );

        $fileCount = count($paths);

        $howToUse = [
            'format' => 'All ai-pack JSON files are minified (compact) to reduce upload size.',
            'upload_core' => 'Start with the 7 core files (everything except knowledge-*). Enough for strategy, audit, keywords, linking, technical.',
            'upload_knowledge' => 'Add knowledge-{section}.json only when you need full entity bodies (rewrite, FAQ, per-URL SEO). Prefer one section at a time on Claude Projects.',
            'upload_gemini' => 'Gemini Gem / ChatGPT usually allow ~10 files: upload core 7 + up to 3 knowledge-* parts (skip media unless needed).',
            'upload_claude' => 'Claude Projects: core 7 first; add knowledge-products / knowledge-posts / knowledge-pages as capacity allows. media is often the heaviest — upload last or skip.',
            'start' => 'Read ai_context.json first for priorities (next_actions + top_findings).',
            'entity_detail' => 'Open the matching knowledge-{section}.json for a specific URL (products|posts|pages|categories|media).',
            'strategy' => 'Use site_brain.json for clusters, navigation, entity_index.',
            'linking' => 'Use internal_link_graph.json for orphans, hubs, link_opportunities.',
            'audit' => 'Use seo_audit.json when you need findings beyond the ai_context cap.',
            'keywords' => 'Use keyword_map.json for inventory, gaps, cannibalization.',
            'technical' => 'Use technical.json keys: site_profile, redirect_map, hreflang, sitemap_coverage, media_seo, content_duplicates, audit_diff.',
        ];

        $counts = [];
        foreach (self::KNOWLEDGE_SECTIONS as $section) {
            $counts[$section] = count($knowledge[$section] ?? []);
        }

        $manifest = [
            'version' => '1.1',
            'generated_at' => $generatedAt,
            'site_name' => function_exists('get_bloginfo') ? get_bloginfo('name') : '',
            'site_url' => function_exists('home_url') ? home_url('/') : '',
            'exporter_version' => $version,
            'purpose' => 'AI upload pack: minified JSON; knowledge split by entity type for Claude/Gem token and file budgets.',
            'file_count' => $fileCount,
            'core_file_count' => self::CORE_FILE_COUNT,
            'knowledge_parts' => count(self::KNOWLEDGE_SECTIONS),
            'max_upload_budget' => 10,
            'json_format' => 'compact',
            'how_to_use' => $howToUse,
            'counts' => $counts,
            'files' => $paths,
            'knowledge_files' => $knowledgePaths,
            'technical_keys' => [
                'site_profile',
                'redirect_map',
                'hreflang',
                'sitemap_coverage',
                'media_seo',
                'content_duplicates',
                'audit_diff',
            ],
            'recommended_ai_starter' => 'ai-pack/ai_context.json',
            'recommended_upload_order' => array_merge(
                [
                    'ai-pack/manifest.json',
                    'ai-pack/ai_context.json',
                    'ai-pack/site_brain.json',
                    'ai-pack/seo_audit.json',
                    'ai-pack/internal_link_graph.json',
                    'ai-pack/keyword_map.json',
                    'ai-pack/technical.json',
                ],
                array_values($knowledgePaths)
            ),
        ];

        // Prefer pack-relative paths inside the copied ai_context for upload workflows.
        $packAiContext = $aiContext;
        if ($packAiContext !== []) {
            $packAiContext['files'] = $paths;
            $packAiContext['files']['knowledge'] = 'ai-pack/knowledge-{products|categories|posts|pages|media}.json';
            $packAiContext['files']['site_profile'] = 'ai-pack/technical.json#site_profile';
            $packAiContext['files']['redirect_map'] = 'ai-pack/technical.json#redirect_map';
            $packAiContext['files']['hreflang'] = 'ai-pack/technical.json#hreflang';
            $packAiContext['files']['sitemap_coverage'] = 'ai-pack/technical.json#sitemap_coverage';
            $packAiContext['files']['media_seo'] = 'ai-pack/technical.json#media_seo';
            $packAiContext['files']['content_duplicates'] = 'ai-pack/technical.json#content_duplicates';
            $packAiContext['files']['audit_diff'] = 'ai-pack/technical.json#audit_diff';
            if (isset($packAiContext['how_to_use']['one_url'])) {
                $packAiContext['how_to_use']['one_url'] = 'Look up url_index, then open that entity in knowledge-{section}.json';
            }
        }

        $files = [
            'manifest.json' => $manifest,
            'ai_context.json' => $packAiContext !== [] ? $packAiContext : [
                'purpose' => 'AI starter pack was disabled or empty; use seo_audit.json + knowledge-*.json.',
                'files' => $paths,
            ],
            'site_brain.json' => $brain,
            'internal_link_graph.json' => $linkGraph,
            'seo_audit.json' => $audit,
            'keyword_map.json' => $keywordMap,
            'technical.json' => $technical,
        ];

        foreach (self::KNOWLEDGE_SECTIONS as $section) {
            $rows = $knowledge[$section] ?? [];
            if (!is_array($rows)) {
                $rows = [];
            }
            $files['knowledge-' . $section . '.json'] = [
                'version' => '1.0',
                'generated_at' => $generatedAt,
                'section' => $section,
                'count' => count($rows),
                $section => $rows,
            ];
        }

        return [
            'files' => $files,
            'paths' => $paths,
            'manifest' => $manifest,
        ];
    }

    public static function fileCount(): int
    {
        return self::CORE_FILE_COUNT + count(self::KNOWLEDGE_SECTIONS);
    }
}
