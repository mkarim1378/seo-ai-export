<?php

declare(strict_types=1);

class NavigationBuilder
{
    public function build(): array
    {
        $menus = [];
        $locations = get_nav_menu_locations();

        foreach ($locations as $location => $menuId) {
            $menu = wp_get_nav_menu_object($menuId);

            if (!$menu) {
                continue;
            }

            $items = wp_get_nav_menu_items($menuId) ?: [];
            $nodes = [];

            foreach ($items as $item) {
                $nodes[] = [
                    'id' => (int)$item->ID,
                    'parent' => (int)$item->menu_item_parent,
                    'title' => html_entity_decode((string)$item->title),
                    'url' => (string)$item->url,
                    'type' => (string)$item->type,
                    'object' => (string)$item->object,
                    'object_id' => (int)$item->object_id,
                    'target' => (string)$item->target,
                    'classes' => array_values(array_filter((array)$item->classes)),
                    'order' => (int)$item->menu_order,
                ];
            }

            $menus[$location] = [
                'name' => $menu->name,
                'menu_name' => $menu->name,
                'items' => $nodes,
            ];
        }

        return $menus;
    }

    /**
     * Post/product/page IDs referenced by any menu item.
     *
     * @return array<int,int> object_id => 1
     */
    public function objectIdMap(): array
    {
        $map = [];

        foreach ($this->build() as $menu) {
            foreach ($menu['items'] as $item) {
                $objectId = (int)($item['object_id'] ?? 0);
                if ($objectId > 0) {
                    $map[$objectId] = 1;
                }
            }
        }

        return $map;
    }
}
