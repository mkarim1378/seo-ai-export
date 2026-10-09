<?php

declare(strict_types=1);

define('AI_EXPORTER_ROOT', __DIR__);

require_once dirname(__DIR__) . '/wp-load.php';

$config = require AI_EXPORTER_ROOT . '/config.php';

require_once AI_EXPORTER_ROOT . '/helpers.php';
require_once AI_EXPORTER_ROOT . '/helpers/TextMetrics.php';
require_once AI_EXPORTER_ROOT . '/helpers/ContentStructureExtractor.php';
require_once AI_EXPORTER_ROOT . '/helpers/ContentRenderer.php';
require_once AI_EXPORTER_ROOT . '/helpers/SchemaExtractor.php';
require_once AI_EXPORTER_ROOT . '/helpers/ContentPipeline.php';
require_once AI_EXPORTER_ROOT . '/helpers/CustomFieldsFilter.php';

if (!defined('AI_EXPORTER_CONTENT_RENDER')) {
    define(
        'AI_EXPORTER_CONTENT_RENDER',
        (string)($config['content_render'] ?? 'blocks')
    );
}

/*
|--------------------------------------------------------------------------
| Writers
|--------------------------------------------------------------------------
*/

require_once AI_EXPORTER_ROOT.'/writers/CsvWriter.php';
require_once AI_EXPORTER_ROOT.'/writers/JsonWriter.php';
require_once AI_EXPORTER_ROOT.'/writers/MarkdownWriter.php';

/*
|--------------------------------------------------------------------------
| Repositories
|--------------------------------------------------------------------------
*/

require_once AI_EXPORTER_ROOT.'/repositories/SeoMetaExtractor.php';
require_once AI_EXPORTER_ROOT.'/repositories/ProductMapper.php';
require_once AI_EXPORTER_ROOT.'/repositories/ProductMetaExtractor.php';
require_once AI_EXPORTER_ROOT.'/repositories/ProductRepository.php';

require_once AI_EXPORTER_ROOT.'/repositories/CategoryMapper.php';
require_once AI_EXPORTER_ROOT.'/repositories/CategoryRepository.php';

require_once AI_EXPORTER_ROOT.'/repositories/PostMapper.php';
require_once AI_EXPORTER_ROOT.'/repositories/PostRepository.php';

require_once AI_EXPORTER_ROOT.'/repositories/PageMapper.php';
require_once AI_EXPORTER_ROOT.'/repositories/PageRepository.php';

require_once AI_EXPORTER_ROOT.'/repositories/MediaMapper.php';
require_once AI_EXPORTER_ROOT.'/repositories/MediaRepository.php';

/*
|--------------------------------------------------------------------------
| Builders
|--------------------------------------------------------------------------
*/

require_once AI_EXPORTER_ROOT.'/builders/SemanticEnricher.php';
require_once AI_EXPORTER_ROOT.'/builders/RelationshipBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/NavigationBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/InternalLinkGraphBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/InternalLinkGraphExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/WooCommerceRelationshipBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/KnowledgeGraphBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SiteProfileBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SiteBrainBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SiteBrainExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/KeywordIntelligenceBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/KeywordIntelligenceExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/RedirectMapBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/RedirectMapExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/HreflangBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/HreflangExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/AuditDiffBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/AuditDiffExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/SeoAuditBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SeoAuditExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/AiContextBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/AiContextExporter.php';

/*
|--------------------------------------------------------------------------
| Exporters / Views
|--------------------------------------------------------------------------
*/

require_once AI_EXPORTER_ROOT.'/exporters/ProductsExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/CategoriesExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/PostsExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/PagesExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/MediaExporter.php';
require_once AI_EXPORTER_ROOT.'/views/ExportReport.php';

ignore_user_abort(true);
set_time_limit(0);
ini_set('memory_limit', '2048M');

$start = microtime(true);
$knowledge = [];
$report = [
    'site_name' => get_bloginfo('name'),
    'site_url' => home_url('/'),
    'generated_at' => date('Y-m-d H:i:s'),
    'counts' => [],
    'audit' => [],
    'link_analysis' => [],
    'files' => [],
];

try {
    $products = (new ProductsExporter($config))->export();
    $knowledge['products'] = $products['data'];

    $categories = (new CategoriesExporter($config))->export();
    $knowledge['categories'] = $categories['data'];

    $posts = (new PostsExporter($config))->export();
    $knowledge['posts'] = $posts['data'];

    $pages = (new PagesExporter($config))->export();
    $knowledge['pages'] = $pages['data'];

    $media = (new MediaExporter($config))->export();
    $knowledge['media'] = $media['data'];

    $json = new JsonWriter(
        rtrim($config['output'], '/') . '/json'
    );

    $brain = (new SiteBrainExporter($config))->export($knowledge);
    $linkSummary = $brain['link_analysis']['summary'] ?? [];
    $siteProfile = $brain['site_profile'] ?? [];

    $keywordMap = [];
    if (!empty($config['export']['keyword_map'])) {
        $keywordExport = (new KeywordIntelligenceExporter($config))->export($knowledge, $brain);
        $keywordMap = $keywordExport['map'];
        $knowledge = $keywordExport['knowledge'];
    }

    $json->write('knowledge.json', $knowledge);

    $redirectMap = [];
    if (!empty($config['export']['redirect_map'])) {
        $redirectMap = (new RedirectMapExporter($config))->export($knowledge);
    }

    $hreflang = [];
    if (!empty($config['export']['hreflang'])) {
        $hreflang = (new HreflangExporter($config))->export($knowledge);
    }

    $auditDiffExporter = new AuditDiffExporter($config);
    $previousAudit = !empty($config['export']['audit_diff'])
        ? $auditDiffExporter->loadPreviousAudit()
        : null;

    $audit = (new SeoAuditExporter($config))->export(
        $knowledge,
        $brain,
        $keywordMap,
        $redirectMap,
        $hreflang
    );
    $auditSummary = $audit['summary'] ?? [];

    $auditDiff = [];
    if (!empty($config['export']['audit_diff'])) {
        $auditDiff = $auditDiffExporter->export($previousAudit, $audit);
    }

    $files = [
        'ai_context' => 'json/ai_context.json',
        'knowledge' => 'json/knowledge.json',
        'site_profile' => 'json/site_profile.json',
        'site_brain' => 'json/site_brain.json',
        'internal_link_graph' => 'json/internal_link_graph.json',
        'keyword_map' => 'json/keyword_map.json',
        'redirect_map' => 'json/redirect_map.json',
        'hreflang' => 'json/hreflang.json',
        'seo_audit' => 'json/seo_audit.json',
        'audit_diff' => 'json/audit_diff.json',
        'manifest' => 'json/manifest.json',
    ];

    $aiContext = [];
    if (!empty($config['export']['ai_context'])) {
        $aiContext = (new AiContextExporter($config))->export(
            $knowledge,
            $brain,
            $audit,
            $files,
            $keywordMap,
            $auditDiff,
            $redirectMap,
            $hreflang
        );
    }

    $sitemapSummary = $siteProfile['crawl']['sitemaps'] ?? [];
    if (is_array($sitemapSummary) && isset($sitemapSummary[0]) && is_array($sitemapSummary[0])) {
        $sitemapSummary = array_map(
            static fn(array $row): string => (string)($row['url'] ?? ''),
            $sitemapSummary
        );
    }

    $json->write('manifest.json', [
        'generated_at' => date('Y-m-d H:i:s'),
        'site_name' => get_bloginfo('name'),
        'site_url' => home_url(),
        'version' => $config['version'] ?? null,
        'products' => count($knowledge['products']),
        'categories' => count($knowledge['categories']),
        'posts' => count($knowledge['posts']),
        'pages' => count($knowledge['pages']),
        'media' => count($knowledge['media']),
        'site_profile' => [
            'search_engine_visibility' => $siteProfile['crawl']['search_engine_visibility'] ?? null,
            'permalink_structure' => $siteProfile['crawl']['permalink_structure'] ?? null,
            'seo_plugins' => $siteProfile['seo_plugins'] ?? [],
            'seo_plugin_globals' => $siteProfile['seo_plugin_globals'] ?? [],
            'sitemaps' => $sitemapSummary,
        ],
        'link_analysis' => $linkSummary,
        'keyword_map' => $keywordMap['summary'] ?? [],
        'redirect_map' => $redirectMap['summary'] ?? [],
        'hreflang' => $hreflang['summary'] ?? [],
        'audit_diff' => $auditDiff['summary'] ?? [],
        'seo_audit' => $auditSummary,
        'ai_context' => [
            'top_findings' => count($aiContext['seo_audit']['top_findings'] ?? []),
            'next_actions' => count($aiContext['next_actions'] ?? []),
            'url_index' => count($aiContext['url_index'] ?? []),
        ],
        'files' => $files,
        'recommended_ai_starter' => 'json/ai_context.json',
    ]);

    $report['counts'] = [
        'products' => count($knowledge['products']),
        'categories' => count($knowledge['categories']),
        'posts' => count($knowledge['posts']),
        'pages' => count($knowledge['pages']),
        'media' => count($knowledge['media']),
    ];
    $report['audit'] = $auditSummary;
    $report['link_analysis'] = $linkSummary;
    $report['files'] = [
        [
            'name' => 'ai_context.json',
            'href' => 'output/json/ai_context.json',
            'description' => 'Start here for Gemini/ChatGPT — compact AI pack',
        ],
        [
            'name' => 'keyword_map.json',
            'href' => 'output/json/keyword_map.json',
            'description' => 'Keyword inventory, gaps, suggestions, cannibalization',
        ],
        [
            'name' => 'audit_diff.json',
            'href' => 'output/json/audit_diff.json',
            'description' => 'Before/after vs previous seo_audit (added/resolved)',
        ],
        [
            'name' => 'redirect_map.json',
            'href' => 'output/json/redirect_map.json',
            'description' => 'Redirect rules + chain/loop analysis',
        ],
        [
            'name' => 'hreflang.json',
            'href' => 'output/json/hreflang.json',
            'description' => 'Polylang/WPML language + translation pairs',
        ],
        [
            'name' => 'seo_audit.json',
            'href' => 'output/json/seo_audit.json',
            'description' => 'Full actionable SEO findings',
        ],
        [
            'name' => 'site_profile.json',
            'href' => 'output/json/site_profile.json',
            'description' => 'Crawl/visibility/permalink/sitemap/SEO plugin context',
        ],
        [
            'name' => 'site_brain.json',
            'href' => 'output/json/site_brain.json',
            'description' => 'Full site intelligence package',
        ],
        [
            'name' => 'internal_link_graph.json',
            'href' => 'output/json/internal_link_graph.json',
            'description' => 'Orphans, hubs, link opportunities, and link structure',
        ],
        [
            'name' => 'knowledge.json',
            'href' => 'output/json/knowledge.json',
            'description' => 'Raw exported entities (+ keyword_coverage)',
        ],
        [
            'name' => 'manifest.json',
            'href' => 'output/json/manifest.json',
            'description' => 'Run summary and file index',
        ],
    ];
} catch (Throwable $e) {
    $report['error'] = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
}

$report['duration'] = round(microtime(true) - $start, 2);

(new ExportReport())->render($report);
