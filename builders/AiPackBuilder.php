<?php

declare(strict_types=1);

/**
 * Curated ≤10-file pack for Gemini Gem / ChatGPT upload.
 * Full payloads only — no summarize/truncate of source datasets.
 */
class AiPackBuilder
{
    public const FILE_COUNT = 8;

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

        $paths = [
            'manifest' => 'ai-pack/manifest.json',
            'ai_context' => 'ai-pack/ai_context.json',
            'knowledge' => 'ai-pack/knowledge.json',
            'site_brain' => 'ai-pack/site_brain.json',
            'internal_link_graph' => 'ai-pack/internal_link_graph.json',
            'seo_audit' => 'ai-pack/seo_audit.json',
            'keyword_map' => 'ai-pack/keyword_map.json',
            'technical' => 'ai-pack/technical.json',
        ];

        $howToUse = [
            'upload' => 'Attach all files in output/ai-pack/ to Gemini Gem / ChatGPT (max 8 files, under the 10-file upload budget).',
            'start' => 'Read ai_context.json first for priorities (next_actions + top_findings).',
            'entity_detail' => 'Open knowledge.json for a specific URL.',
            'strategy' => 'Use site_brain.json for clusters, navigation, entity_index.',
            'linking' => 'Use internal_link_graph.json for orphans, hubs, link_opportunities.',
            'audit' => 'Use seo_audit.json when you need findings beyond the ai_context cap.',
            'keywords' => 'Use keyword_map.json for inventory, gaps, cannibalization.',
            'technical' => 'Use technical.json keys: site_profile, redirect_map, hreflang, sitemap_coverage, media_seo, content_duplicates, audit_diff.',
        ];

        $manifest = [
            'version' => '1.0',
            'generated_at' => $generatedAt,
            'site_name' => function_exists('get_bloginfo') ? get_bloginfo('name') : '',
            'site_url' => function_exists('home_url') ? home_url('/') : '',
            'exporter_version' => $version,
            'purpose' => 'AI upload pack: at most 8 JSON files covering the useful site datasets.',
            'file_count' => self::FILE_COUNT,
            'max_upload_budget' => 10,
            'how_to_use' => $howToUse,
            'counts' => [
                'products' => count($knowledge['products'] ?? []),
                'categories' => count($knowledge['categories'] ?? []),
                'posts' => count($knowledge['posts'] ?? []),
                'pages' => count($knowledge['pages'] ?? []),
                'media' => count($knowledge['media'] ?? []),
            ],
            'files' => $paths,
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
        ];

        // Prefer pack-relative paths inside the copied ai_context for upload workflows.
        $packAiContext = $aiContext;
        if ($packAiContext !== []) {
            $packAiContext['files'] = $paths;
            $packAiContext['files']['site_profile'] = 'ai-pack/technical.json#site_profile';
            $packAiContext['files']['redirect_map'] = 'ai-pack/technical.json#redirect_map';
            $packAiContext['files']['hreflang'] = 'ai-pack/technical.json#hreflang';
            $packAiContext['files']['sitemap_coverage'] = 'ai-pack/technical.json#sitemap_coverage';
            $packAiContext['files']['media_seo'] = 'ai-pack/technical.json#media_seo';
            $packAiContext['files']['content_duplicates'] = 'ai-pack/technical.json#content_duplicates';
            $packAiContext['files']['audit_diff'] = 'ai-pack/technical.json#audit_diff';
        }

        $files = [
            'manifest.json' => $manifest,
            'ai_context.json' => $packAiContext !== [] ? $packAiContext : [
                'purpose' => 'AI starter pack was disabled or empty; use seo_audit.json + knowledge.json.',
                'files' => $paths,
            ],
            'knowledge.json' => $knowledge,
            'site_brain.json' => $brain,
            'internal_link_graph.json' => $linkGraph,
            'seo_audit.json' => $audit,
            'keyword_map.json' => $keywordMap,
            'technical.json' => $technical,
        ];

        return [
            'files' => $files,
            'paths' => $paths,
            'manifest' => $manifest,
        ];
    }
}
