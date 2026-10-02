<?php

declare(strict_types=1);

class RelationshipBuilder
{
    public function build(array $knowledge): array
    {
        return [

            'product_category' => $this->productCategory($knowledge),

            'post_category' => $this->postCategory($knowledge),

            'page_links' => $this->pageLinks($knowledge),

            'post_links' => $this->postLinks($knowledge),

            'product_links' => $this->productLinks($knowledge),

            'media_usage' => $this->mediaUsage($knowledge)

        ];
    }

    private function productCategory(array $knowledge): array
    {
        $relations = [];

        foreach ($knowledge['products'] ?? [] as $product) {

            foreach (($product['taxonomy']['categories'] ?? []) as $category) {

                $relations[] = [

                    'product_id' => $product['basic']['id'],

                    'product_title' => $product['basic']['title'],

                    'category' => $category

                ];

            }

        }

        return $relations;
    }

    private function postCategory(array $knowledge): array
    {
        $relations = [];

        foreach ($knowledge['posts'] ?? [] as $post) {

            foreach (($post['taxonomy']['categories'] ?? []) as $category) {

                $relations[] = [

                    'post_id' => $post['basic']['id'],

                    'post_title' => $post['basic']['title'],

                    'category' => $category

                ];

            }

        }

        return $relations;
    }

    private function pageLinks(array $knowledge): array
    {
        $relations = [];

        foreach ($knowledge['pages'] ?? [] as $page) {

            preg_match_all(
                '/https?:\/\/[^"\']+/i',
                $page['content']['content'] ?? '',
                $matches
            );

            $relations[] = [

                'page_id' => $page['basic']['id'],

                'title' => $page['basic']['title'],

                'links' => array_values(array_unique($matches[0]))

            ];

        }

        return $relations;
    }

    private function postLinks(array $knowledge): array
    {
        $relations = [];

        foreach ($knowledge['posts'] ?? [] as $post) {

            preg_match_all(
                '/https?:\/\/[^"\']+/i',
                $post['content']['content'] ?? '',
                $matches
            );

            $relations[] = [

                'post_id' => $post['basic']['id'],

                'title' => $post['basic']['title'],

                'links' => array_values(array_unique($matches[0]))

            ];

        }

        return $relations;
    }

    private function productLinks(array $knowledge): array
    {
        $relations = [];

        foreach ($knowledge['products'] ?? [] as $product) {

            preg_match_all(
                '/https?:\/\/[^"\']+/i',
                $product['content']['description'] ?? '',
                $matches
            );

            $relations[] = [

                'product_id' => $product['basic']['id'],

                'title' => $product['basic']['title'],

                'links' => array_values(array_unique($matches[0]))

            ];

        }

        return $relations;
    }

    private function mediaUsage(array $knowledge): array
    {
        $relations = [];

        foreach ($knowledge['media'] ?? [] as $media) {

            $relations[] = [

                'media_id' => $media['basic']['id'],

                'file' => $media['basic']['url'],

                'parent_id' => $media['parent']['parent_id'] ?? 0,

                'parent_type' => $media['parent']['parent_type'] ?? ''

            ];

        }

        return $relations;
    }
}