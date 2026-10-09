<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    */

    'version' => '3.0.0',

    /*
    |--------------------------------------------------------------------------
    | Site
    |--------------------------------------------------------------------------
    */

    'site_name' => get_bloginfo('name'),

    'site_url' => home_url('/'),

    'language' => get_locale(),

    'charset' => get_bloginfo('charset'),

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    'output' => AI_EXPORTER_ROOT . '/output',

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    'export' => [

        'products' => true,

        'categories' => true,

        'posts' => true,

        'pages' => true,

        'media' => true,

        'menus' => true,

        'internal_link_graph' => true,

        'site_brain' => true,

        'seo_audit' => true,

        'keyword_map' => true,

        'ai_context' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Optional Search Console CSV (Queries export)
    |--------------------------------------------------------------------------
    | Place file at input/gsc-queries.csv or set an absolute path here.
    | Expected columns: Query/Page/Clicks/Impressions/Position (GSC export names OK).
    */

    'gsc_csv' => '',

    /*
    |--------------------------------------------------------------------------
    | Writers
    |--------------------------------------------------------------------------
    */

    'writers' => [

        'csv' => true,

        'json' => true,

        'markdown' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Directories
    |--------------------------------------------------------------------------
    */

    'directories' => [

        'csv' => 'csv',

        'json' => 'json',

        'markdown' => 'markdown',

        'manifest' => 'manifest',

        'logs' => 'logs'

    ],

];