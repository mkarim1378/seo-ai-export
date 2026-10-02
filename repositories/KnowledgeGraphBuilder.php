<?php

declare(strict_types=1);

class KnowledgeGraphBuilder
{
    public function build(array $knowledge): array
    {
        $graph = [

            'products' => [],

            'categories' => [],

            'pages' => [],

            'posts' => [],

            'relations' => []

        ];

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        */

        foreach ($knowledge['products'] ?? [] as $product) {

            $id = $product['basic']['id'];

            $graph['products'][$id] = [

                'id' => $id,

                'title' => $product['basic']['title'] ?? '',

                'slug' => $product['basic']['slug'] ?? '',

                'url' => $product['basic']['url'] ?? ''

            ];

            foreach (($product['taxonomy']['categories'] ?? []) as $category) {

                $graph['relations'][] = [

                    'from' => $id,

                    'type' => 'belongs_to',

                    'to' => $category

                ];

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        foreach ($knowledge['categories'] ?? [] as $category) {

            $graph['categories'][] = [

                'id' => $category['basic']['id'],

                'name' => $category['basic']['name'],

                'slug' => $category['basic']['slug']

            ];

        }

        /*
        |--------------------------------------------------------------------------
        | Posts
        |--------------------------------------------------------------------------
        */

        foreach ($knowledge['posts'] ?? [] as $post) {

            $graph['posts'][] = [

                'id' => $post['basic']['id'],

                'title' => $post['basic']['title'],

                'url' => $post['basic']['url']

            ];

        }

        /*
        |--------------------------------------------------------------------------
        | Pages
        |--------------------------------------------------------------------------
        */

        foreach ($knowledge['pages'] ?? [] as $page) {

            $graph['pages'][] = [

                'id' => $page['basic']['id'],

                'title' => $page['basic']['title'],

                'url' => $page['basic']['url']

            ];

        }

        return $graph;
    }
}