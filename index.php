<?php

declare(strict_types=1);

define('AI_EXPORTER_ROOT', __DIR__);

require_once dirname(__DIR__) . '/wp-load.php';

$config = require AI_EXPORTER_ROOT . '/config.php';

require_once AI_EXPORTER_ROOT . '/helpers.php';
require_once AI_EXPORTER_ROOT . '/helpers/TextMetrics.php';
require_once AI_EXPORTER_ROOT . '/helpers/ContentStructureExtractor.php';

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

require_once AI_EXPORTER_ROOT.'/repositories/KnowledgeGraphBuilder.php';

/*
|--------------------------------------------------------------------------
| Builders
|--------------------------------------------------------------------------
*/

require_once AI_EXPORTER_ROOT.'/builders/SemanticEnricher.php';
require_once AI_EXPORTER_ROOT.'/builders/RelationshipBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SiteBrainBuilder.php';
require_once AI_EXPORTER_ROOT.'/builders/SiteBrainExporter.php';

/*
|--------------------------------------------------------------------------
| Exporters
|--------------------------------------------------------------------------
*/

require_once AI_EXPORTER_ROOT.'/exporters/ProductsExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/CategoriesExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/PostsExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/PagesExporter.php';
require_once AI_EXPORTER_ROOT.'/exporters/MediaExporter.php';

ignore_user_abort(true);
set_time_limit(0);
ini_set('memory_limit','2048M');

header('Content-Type:text/html;charset=utf-8');

echo "<pre>";

echo "========================================\n";
echo " AI SITE INTELLIGENCE ENGINE\n";
echo "========================================\n\n";

$start = microtime(true);

$knowledge = [];

try {

    $products = (new ProductsExporter($config))->export();
    echo "✔ Products : {$products['count']}\n";
    $knowledge['products'] = $products['data'];

    $categories = (new CategoriesExporter($config))->export();
    echo "✔ Categories : {$categories['count']}\n";
    $knowledge['categories'] = $categories['data'];

    $posts = (new PostsExporter($config))->export();
    echo "✔ Posts : {$posts['count']}\n";
    $knowledge['posts'] = $posts['data'];

    $pages = (new PagesExporter($config))->export();
    echo "✔ Pages : {$pages['count']}\n";
    $knowledge['pages'] = $pages['data'];

    $media = (new MediaExporter($config))->export();
    echo "✔ Media : {$media['count']}\n";
    $knowledge['media'] = $media['data'];

    $json = new JsonWriter(
        rtrim($config['output'],'/').'/json'
    );

    $json->write('knowledge.json', $knowledge);

    (new SiteBrainExporter($config))->export($knowledge);

    echo "✔ Site Brain : generated\n";

    $json->write('manifest.json', [

        'generated_at' => date('Y-m-d H:i:s'),

        'site_name' => get_bloginfo('name'),

        'site_url' => home_url(),

        'products' => count($knowledge['products']),

        'categories' => count($knowledge['categories']),

        'posts' => count($knowledge['posts']),

        'pages' => count($knowledge['pages']),

        'media' => count($knowledge['media'])

    ]);

}
catch(Throwable $e){

    echo "\nERROR\n\n";

    echo $e->getMessage()."\n\n";

    echo $e->getFile()."\n";

    echo "Line ".$e->getLine();

    exit;

}

$time = round(microtime(true)-$start,2);

echo "\n========================================\n";

echo "Completed Successfully\n";

echo "Execution Time : {$time} sec\n";

echo "========================================\n";

echo "</pre>";