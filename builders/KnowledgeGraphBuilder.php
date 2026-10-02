<?php

declare(strict_types=1);

require_once __DIR__ . '/InternalLinkGraphBuilder.php';
require_once __DIR__ . '/WooCommerceRelationshipBuilder.php';

class KnowledgeGraphBuilder
{
    public function build(array $knowledge): array
    {
        return [

            'site' => [

                'name' => get_bloginfo('name'),

                'url' => home_url('/'),

                'description' => get_bloginfo('description')

            ],

            'navigation' => $this->buildNavigation(),

            'internal_link_graph' => (new InternalLinkGraphBuilder())->build(),

            'woocommerce' => (new WooCommerceRelationshipBuilder())->build(),

            'entities' => [

                'products' => count($knowledge['products'] ?? []),

                'categories' => count($knowledge['categories'] ?? []),

                'posts' => count($knowledge['posts'] ?? []),

                'pages' => count($knowledge['pages'] ?? []),

                'media' => count($knowledge['media'] ?? [])

            ]

        ];
    }

    private function buildNavigation(): array
    {
        $result = [];

        $locations = get_nav_menu_locations();

        foreach ($locations as $location => $menuId) {

            $menu = wp_get_nav_menu_object($menuId);

            if (!$menu) {
                continue;
            }

            $items = wp_get_nav_menu_items($menuId);

            $result[$location] = [

                'name' => $menu->name,

                'items' => []

            ];

            foreach ($items as $item) {

                $result[$location]['items'][] = [

                    'id' => (int)$item->ID,

                    'parent' => (int)$item->menu_item_parent,

                    'title' => html_entity_decode($item->title),

                    'url' => $item->url,

                    'object' => $item->object,

                    'object_id' => (int)$item->object_id,

                    'type' => $item->type,

                    'order' => (int)$item->menu_order

                ];
            }
        }

        return $result;
    }
}