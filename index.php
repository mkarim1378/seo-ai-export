<?php

declare(strict_types=1);

define('AI_EXPORTER_ROOT', __DIR__);

require_once AI_EXPORTER_ROOT . '/helpers/ExportProgress.php';

/*
|--------------------------------------------------------------------------
| Browser routing
|--------------------------------------------------------------------------
| index.php                → صفحه لودینگ فوری (بدون لود وردپرس)
| index.php?action=run     → (POST) Export در پس‌زمینه + نوشتن progress
| index.php?action=status  → JSON وضعیت برای poll
| index.php?action=report  → گزارش HTML آخرین اجرا
| index.php?sync=1         → رفتار قدیمی: اجرا و نمایش گزارش در همان درخواست
| CLI همیشه مستقیم اجرا می‌شود.
*/

$progress = null;

if (PHP_SAPI !== 'cli' && empty($_GET['sync'])) {
    $tracker = ExportProgress::forRoot(AI_EXPORTER_ROOT);
    $action = (string)($_GET['action'] ?? '');

    $sendJson = static function (array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    if ($action === 'status') {
        $sendJson($tracker->read());
        exit;
    }

    if ($action === 'report') {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        if (!is_file($tracker->reportPath())) {
            header('Location: index.php', true, 302);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        readfile($tracker->reportPath());
        exit;
    }

    if ($action === 'run') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $sendJson(['error' => 'POST required'], 405);
            exit;
        }

        $runId = preg_replace('/[^a-z0-9]/i', '', (string)($_POST['run_id'] ?? ''));
        $runId = $runId !== '' ? substr($runId, 0, 40) : bin2hex(random_bytes(8));

        if (!$tracker->acquire()) {
            $current = $tracker->read();
            $sendJson(['already_running' => true, 'run_id' => $current['run_id'] ?? null], 409);
            exit;
        }

        ignore_user_abort(true);
        set_time_limit(0);

        $tracker->start($runId, [
            'wordpress' => 'Loading WordPress',
            'products' => 'Products',
            'categories' => 'Product categories',
            'posts' => 'Posts',
            'pages' => 'Pages',
            'media' => 'Media library',
            'site_brain' => 'Site brain & internal link graph',
            'keyword_map' => 'Keyword intelligence',
            'knowledge' => 'Writing knowledge.json',
            'redirect_map' => 'Redirect map',
            'hreflang' => 'Hreflang',
            'sitemap_coverage' => 'Sitemap coverage',
            'media_seo' => 'Media SEO',
            'content_duplicates' => 'Duplicate content',
            'seo_audit' => 'SEO audit',
            'audit_diff' => 'Audit diff vs previous run',
            'ai_context' => 'AI context',
            'ai_pack' => 'AI upload pack',
            'manifest' => 'Manifest & report',
        ]);
        $progress = $tracker;

        register_shutdown_function(static function () use ($tracker): void {
            if (!$tracker->isRunning()) {
                return;
            }
            $error = error_get_last();
            $message = $error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)
                ? $error['message'] . ' (' . basename((string)$error['file']) . ':' . $error['line'] . ')'
                : 'The export process stopped unexpectedly.';
            $tracker->fail($message);
        });

        // پاسخ را فوراً ببند تا مرورگر/پراکسی منتظر نماند (و 504 ندهد)؛ کار در پس‌زمینه ادامه دارد.
        $body = json_encode(['started' => true, 'run_id' => $runId]);
        http_response_code(202);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen((string)$body));
        header('Connection: close');
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
        // هر خروجی بعدی (هشدارهای PHP و ...) نباید به پاسخ بسته‌شده اضافه شود.
        ob_start(static fn(): string => '');
    } else {
        require_once AI_EXPORTER_ROOT . '/views/LoadingPage.php';
        (new LoadingPage())->render();
        exit;
    }
}

$progress?->begin('wordpress');

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
require_once AI_EXPORTER_ROOT.'/builders/SitemapCoverageBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SitemapCoverageExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/MediaSeoBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/MediaSeoExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/DuplicateContentBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/DuplicateContentExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/SeoAuditBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SeoAuditExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/AiContextBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/AiContextExporter.php';
require_once AI_EXPORTER_ROOT.'/builders/AiPackBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/AiPackExporter.php';

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

$progress?->done('wordpress');

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
    $progress?->begin('products');
    $products = (new ProductsExporter($config))->export();
    $knowledge['products'] = $products['data'];
    $progress?->done('products', number_format(count($products['data'])) . ' products');

    $progress?->begin('categories');
    $categories = (new CategoriesExporter($config))->export();
    $knowledge['categories'] = $categories['data'];
    $progress?->done('categories', number_format(count($categories['data'])) . ' categories');

    $progress?->begin('posts');
    $posts = (new PostsExporter($config))->export();
    $knowledge['posts'] = $posts['data'];
    $progress?->done('posts', number_format(count($posts['data'])) . ' posts');

    $progress?->begin('pages');
    $pages = (new PagesExporter($config))->export();
    $knowledge['pages'] = $pages['data'];
    $progress?->done('pages', number_format(count($pages['data'])) . ' pages');

    $progress?->begin('media');
    $media = (new MediaExporter($config))->export();
    $knowledge['media'] = $media['data'];
    $progress?->done('media', number_format(count($media['data'])) . ' files');

    $json = new JsonWriter(
        rtrim($config['output'], '/') . '/json'
    );

    $progress?->begin('site_brain');
    $brain = (new SiteBrainExporter($config))->export($knowledge);
    $progress?->done('site_brain');
    $linkSummary = $brain['link_analysis']['summary'] ?? [];
    $siteProfile = $brain['site_profile'] ?? [];

    $keywordMap = [];
    if (!empty($config['export']['keyword_map'])) {
        $progress?->begin('keyword_map');
        $keywordExport = (new KeywordIntelligenceExporter($config))->export($knowledge, $brain);
        $keywordMap = $keywordExport['map'];
        $knowledge = $keywordExport['knowledge'];
        $progress?->done('keyword_map');
    } else {
        $progress?->skip('keyword_map');
    }

    $progress?->begin('knowledge');
    $json->write('knowledge.json', $knowledge);
    $progress?->done('knowledge');

    $redirectMap = [];
    if (!empty($config['export']['redirect_map'])) {
        $progress?->begin('redirect_map');
        $redirectMap = (new RedirectMapExporter($config))->export($knowledge);
        $progress?->done('redirect_map');
    } else {
        $progress?->skip('redirect_map');
    }

    $hreflang = [];
    if (!empty($config['export']['hreflang'])) {
        $progress?->begin('hreflang');
        $hreflang = (new HreflangExporter($config))->export($knowledge);
        $progress?->done('hreflang');
    } else {
        $progress?->skip('hreflang');
    }

    $sitemapCoverage = [];
    if (!empty($config['export']['sitemap_coverage'])) {
        $progress?->begin('sitemap_coverage');
        $sitemapCoverage = (new SitemapCoverageExporter($config))->export(
            $knowledge,
            $siteProfile
        );
        $progress?->done('sitemap_coverage');
    } else {
        $progress?->skip('sitemap_coverage');
    }

    $mediaSeo = [];
    if (!empty($config['export']['media_seo'])) {
        $progress?->begin('media_seo');
        $mediaSeo = (new MediaSeoExporter($config))->export($knowledge);
        $progress?->done('media_seo');
    } else {
        $progress?->skip('media_seo');
    }

    $contentDuplicates = [];
    if (!empty($config['export']['content_duplicates'])) {
        $progress?->begin('content_duplicates');
        $contentDuplicates = (new DuplicateContentExporter($config))->export($knowledge);
        $progress?->done('content_duplicates');
    } else {
        $progress?->skip('content_duplicates');
    }

    $auditDiffExporter = new AuditDiffExporter($config);
    $previousAudit = !empty($config['export']['audit_diff'])
        ? $auditDiffExporter->loadPreviousAudit()
        : null;

    $progress?->begin('seo_audit');
    $audit = (new SeoAuditExporter($config))->export(
        $knowledge,
        $brain,
        $keywordMap,
        $redirectMap,
        $hreflang,
        $sitemapCoverage,
        $mediaSeo,
        $contentDuplicates
    );
    $auditSummary = $audit['summary'] ?? [];
    $progress?->done('seo_audit', number_format((int)($auditSummary['total'] ?? 0)) . ' findings');

    $auditDiff = [];
    if (!empty($config['export']['audit_diff'])) {
        $progress?->begin('audit_diff');
        $auditDiff = $auditDiffExporter->export($previousAudit, $audit);
        $progress?->done('audit_diff');
    } else {
        $progress?->skip('audit_diff');
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
        'sitemap_coverage' => 'json/sitemap_coverage.json',
        'media_seo' => 'json/media_seo.json',
        'content_duplicates' => 'json/content_duplicates.json',
        'seo_audit' => 'json/seo_audit.json',
        'audit_diff' => 'json/audit_diff.json',
        'manifest' => 'json/manifest.json',
    ];

    $aiContext = [];
    if (!empty($config['export']['ai_context'])) {
        $progress?->begin('ai_context');
        $aiContext = (new AiContextExporter($config))->export(
            $knowledge,
            $brain,
            $audit,
            $files,
            $keywordMap,
            $auditDiff,
            $redirectMap,
            $hreflang,
            $sitemapCoverage,
            $mediaSeo,
            $contentDuplicates
        );
        $progress?->done('ai_context');
    } else {
        $progress?->skip('ai_context');
    }

    $sitemapSummary = $siteProfile['crawl']['sitemaps'] ?? [];
    if (is_array($sitemapSummary) && isset($sitemapSummary[0]) && is_array($sitemapSummary[0])) {
        $sitemapSummary = array_map(
            static fn(array $row): string => (string)($row['url'] ?? ''),
            $sitemapSummary
        );
    }

    $aiPack = [];
    if (!empty($config['export']['ai_pack'])) {
        $progress?->begin('ai_pack');
        $aiPack = (new AiPackExporter($config))->export(
            $knowledge,
            $brain,
            $audit,
            $aiContext,
            $keywordMap,
            $siteProfile,
            $redirectMap,
            $hreflang,
            $sitemapCoverage,
            $mediaSeo,
            $contentDuplicates,
            $auditDiff
        );
        $files['ai_pack'] = 'ai-pack/';
        foreach ($aiPack['paths'] as $key => $path) {
            $files['ai_pack_' . $key] = $path;
        }
        $progress?->done('ai_pack', ($aiPack['file_count'] ?? 0) . ' files');
    } else {
        $progress?->skip('ai_pack');
    }

    $progress?->begin('manifest');
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
        'sitemap_coverage' => $sitemapCoverage['summary'] ?? [],
        'media_seo' => $mediaSeo['summary'] ?? [],
        'content_duplicates' => $contentDuplicates['summary'] ?? [],
        'audit_diff' => $auditDiff['summary'] ?? [],
        'seo_audit' => $auditSummary,
        'ai_context' => [
            'top_findings' => count($aiContext['seo_audit']['top_findings'] ?? []),
            'next_actions' => count($aiContext['next_actions'] ?? []),
            'url_index' => count($aiContext['url_index'] ?? []),
        ],
        'ai_pack' => [
            'enabled' => $aiPack !== [],
            'file_count' => $aiPack['file_count'] ?? 0,
            'directory' => 'ai-pack/',
            'files' => $aiPack['paths'] ?? [],
        ],
        'files' => $files,
        'recommended_ai_starter' => $aiPack !== []
            ? 'ai-pack/ai_context.json'
            : 'json/ai_context.json',
        'recommended_ai_upload' => $aiPack !== []
            ? 'ai-pack/ (all 8 JSON files)'
            : 'json/ai_context.json',
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

    $packReportFiles = [];
    if ($aiPack !== []) {
        $packReportFiles = [
            [
                'name' => 'ai-pack/manifest.json',
                'href' => 'output/ai-pack/manifest.json',
                'description' => 'AI upload pack index (start here for file map)',
            ],
            [
                'name' => 'ai-pack/ai_context.json',
                'href' => 'output/ai-pack/ai_context.json',
                'description' => 'Priorities / next_actions — upload with the rest of ai-pack/',
            ],
            [
                'name' => 'ai-pack/knowledge.json',
                'href' => 'output/ai-pack/knowledge.json',
                'description' => 'Full entities for AI upload pack',
            ],
            [
                'name' => 'ai-pack/site_brain.json',
                'href' => 'output/ai-pack/site_brain.json',
                'description' => 'Clusters, navigation, entity_index',
            ],
            [
                'name' => 'ai-pack/internal_link_graph.json',
                'href' => 'output/ai-pack/internal_link_graph.json',
                'description' => 'Orphans, hubs, link opportunities',
            ],
            [
                'name' => 'ai-pack/seo_audit.json',
                'href' => 'output/ai-pack/seo_audit.json',
                'description' => 'Full SEO findings',
            ],
            [
                'name' => 'ai-pack/keyword_map.json',
                'href' => 'output/ai-pack/keyword_map.json',
                'description' => 'Keyword inventory, gaps, cannibalization',
            ],
            [
                'name' => 'ai-pack/technical.json',
                'href' => 'output/ai-pack/technical.json',
                'description' => 'Merged full: site_profile, redirects, hreflang, sitemap, media_seo, duplicates, audit_diff',
            ],
        ];
    }

    $report['files'] = array_merge($packReportFiles, [
        [
            'name' => 'ai_context.json',
            'href' => 'output/json/ai_context.json',
            'description' => 'Compact AI starter (also copied into ai-pack/)',
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
            'name' => 'sitemap_coverage.json',
            'href' => 'output/json/sitemap_coverage.json',
            'description' => 'Sitemap XML vs exported URL coverage gaps',
        ],
        [
            'name' => 'media_seo.json',
            'href' => 'output/json/media_seo.json',
            'description' => 'Media library alt / oversized / dimension issues',
        ],
        [
            'name' => 'content_duplicates.json',
            'href' => 'output/json/content_duplicates.json',
            'description' => 'Near-duplicate content and title groups',
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
    ]);
} catch (Throwable $e) {
    $report['error'] = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
}

$report['duration'] = round(microtime(true) - $start, 2);

if ($progress !== null) {
    $html = (new ExportReport())->html($report);
    if (!empty($report['error'])) {
        $progress->fail((string)$report['error']['message'], $html);
    } else {
        $progress->done('manifest');
        $progress->complete($html);
    }
    exit;
}

(new ExportReport())->render($report);
