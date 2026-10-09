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
                : null,
        ];
    }

    private function statistics(array $knowledge): array
    {
        return [
            'products' => count($knowledge['products'] ?? []),
            'categories' => count($knowledge['categories'] ?? []),
            'posts' => count($knowledge['posts'] ?? []),
            'pages' => count($knowledge['pages'] ?? []),
            'media' => count($knowledge['media'] ?? []),
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
                'parent' => (int)($category['basic']['parent'] ?? 0),
            ];
        }

        return $tree;
    }

    private function contentClusters(array $knowledge): array
    {
        $clusters = [];
        $byId = [];
        $byName = [];

        foreach ($knowledge['categories'] ?? [] as $category) {
            $slug = (string)($category['basic']['slug'] ?? '');
            $id = (int)($category['basic']['id'] ?? 0);
            $name = (string)($category['basic']['name'] ?? '');

            if ($slug === '') {
                continue;
            }

            $clusters[$slug] = [
                'category_id' => $id,
                'category' => $name,
                'slug' => $slug,
                'products' => [],
                'posts' => [],
                'pages' => [],
            ];

            if ($id > 0) {
                $byId[$id] = $slug;
            }

            if ($name !== '') {
                $byName[mb_strtolower($name)] = $slug;
            }
        }

        foreach ($knowledge['products'] ?? [] as $product) {
            foreach ($this->termSlugs($product['taxonomy']['categories'] ?? [], $byId, $byName, $clusters) as $slug) {
                $clusters[$slug]['products'][] = [
                    'id' => $product['basic']['id'] ?? null,
                    'title' => $product['basic']['title'] ?? '',
                ];
            }
        }

        foreach ($knowledge['posts'] ?? [] as $post) {
            foreach ($this->termSlugs($post['taxonomy']['categories'] ?? [], $byId, $byName, $clusters) as $slug) {
                $clusters[$slug]['posts'][] = [
                    'id' => $post['basic']['id'] ?? null,
                    'title' => $post['basic']['title'] ?? '',
                ];
            }
        }

        return array_values($clusters);
    }

    /**
     * Resolve category refs (legacy string name/slug or {id,name,slug}) to known cluster slugs.
     *
     * @param list<mixed> $terms
     * @param array<int,string> $byId
     * @param array<string,string> $byName
     * @param array<string,array<string,mixed>> $clusters
     * @return list<string>
     */
    private function termSlugs(array $terms, array $byId, array $byName, array $clusters): array
    {
        $slugs = [];

        foreach ($terms as $term) {
            if (is_array($term)) {
                $slug = (string)($term['slug'] ?? '');
                $id = (int)($term['id'] ?? 0);
                $name = (string)($term['name'] ?? '');

                if ($slug !== '' && isset($clusters[$slug])) {
                    $slugs[] = $slug;
                    continue;
                }

                if ($id > 0 && isset($byId[$id])) {
                    $slugs[] = $byId[$id];
                    continue;
                }

                $nameKey = mb_strtolower($name);
                if ($nameKey !== '' && isset($byName[$nameKey])) {
                    $slugs[] = $byName[$nameKey];
                }

                continue;
            }

            $raw = trim((string)$term);
            if ($raw === '') {
                continue;
            }

            if (isset($clusters[$raw])) {
                $slugs[] = $raw;
                continue;
            }

            $nameKey = mb_strtolower($raw);
            if (isset($byName[$nameKey])) {
                $slugs[] = $byName[$nameKey];
            }
        }

        return array_values(array_unique($slugs));
    }
}
