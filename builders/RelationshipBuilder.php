<?php

declare(strict_types=1);

class RelationshipBuilder
{
    public function build(array $knowledge): array
    {
        return [
            'product_category' => $this->productCategory($knowledge),
            'post_category' => $this->postCategory($knowledge),
            'page_links' => $this->entityLinks($knowledge['pages'] ?? [], 'page'),
            'post_links' => $this->entityLinks($knowledge['posts'] ?? [], 'post'),
            'product_links' => $this->entityLinks($knowledge['products'] ?? [], 'product'),
            'media_usage' => $this->mediaUsage($knowledge),
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
                    'category' => $category,
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
                    'category' => $category,
                ];
            }
        }

        return $relations;
    }

    private function entityLinks(array $entities, string $type): array
    {
        $relations = [];

        foreach ($entities as $entity) {
            $internal = $entity['structure']['internal_links'] ?? [];
            $external = $entity['structure']['external_links'] ?? [];

            $relations[] = [
                "{$type}_id" => $entity['basic']['id'] ?? null,
                'title' => $entity['basic']['title'] ?? ($entity['basic']['name'] ?? ''),
                'internal_links' => $internal,
                'external_links' => $external,
                'internal_count' => count($internal),
                'external_count' => count($external),
                'links' => array_values(array_unique(array_map(
                    static fn(array $link): string => (string)($link['url'] ?? ''),
                    array_merge($internal, $external)
                ))),
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
                'parent_type' => $media['parent']['parent_type'] ?? '',
            ];
        }

        return $relations;
    }
}
