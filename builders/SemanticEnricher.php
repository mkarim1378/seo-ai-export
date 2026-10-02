<?php

declare(strict_types=1);

class SemanticEnricher
{
    public function enrich(array $knowledge): array
    {
        $knowledge['site'] = $this->site();

        $knowledge['taxonomy'] = $this->taxonomy($knowledge);

        $knowledge['statistics'] = $this->statistics($knowledge);

        $knowledge['content_clusters'] = $this->contentClusters($knowledge);

        return $knowledge;
    }

    private function site(): array
    {
        return [

            'name' => get_bloginfo('name'),

            'description' => get_bloginfo('description'),

            'url' => home_url(),

            'language' => get_locale(),

            'theme' => wp_get_theme()->get('Name'),

            'wordpress' => get_bloginfo('version'),

            'woocommerce' => class_exists('WooCommerce')
                ? WC()->version
                : null

        ];
    }

    private function statistics(array $knowledge): array
    {
        return [

            'products' => count($knowledge['products'] ?? []),

            'categories' => count($knowledge['categories'] ?? []),

            'posts' => count($knowledge['posts'] ?? []),

            'pages' => count($knowledge['pages'] ?? []),

            'media' => count($knowledge['media'] ?? [])

        ];
    }

    private function taxonomy(array $knowledge): array
    {
        $tree = [];

        foreach ($knowledge['categories'] ?? [] as $category) {

            $tree[] = [

                'id' => $category['basic']['id'] ?? null,

                'name' => $category['basic']['name'] ?? '',

                'slug' => $category['basic']['slug'] ?? '',

                'parent' => $category['basic']['parent'] ?? 0

            ];

        }

        return $tree;
    }

    private function contentClusters(array $knowledge): array
    {
        $clusters = [];

        foreach ($knowledge['categories'] ?? [] as $category) {

            $clusters[$category['basic']['slug']] = [

                'category' => $category['basic']['name'],

                'products' => [],

                'posts' => [],

                'pages' => []

            ];

        }

        foreach ($knowledge['products'] ?? [] as $product) {

            foreach (($product['taxonomy']['categories'] ?? []) as $cat) {

                if (!isset($clusters[$cat])) {
                    continue;
                }

                $clusters[$cat]['products'][] = [

                    'id' => $product['basic']['id'],

                    'title' => $product['basic']['title']

                ];

            }

        }

        return array_values($clusters);
    }
}