<?php

class NavigationBuilder
{
    public function build()
    {
        $menus = [];

        $locations = get_nav_menu_locations();

        foreach ($locations as $location => $menu_id) {

            $menu = wp_get_nav_menu_object($menu_id);

            if (!$menu) {
                continue;
            }

            $items = wp_get_nav_menu_items($menu_id);

            $nodes = [];

            foreach ($items as $item) {

                $nodes[] = [

                    'id' => (int) $item->ID,

                    'parent' => (int) $item->menu_item_parent,

                    'title' => html_entity_decode($item->title),

                    'url' => $item->url,

                    'type' => $item->type,

                    'object' => $item->object,

                    'object_id' => (int) $item->object_id,

                    'target' => $item->target,

                    'classes' => array_values(array_filter($item->classes)),

                    'order' => (int) $item->menu_order

                ];
            }

            $menus[$location] = [

                'menu_name' => $menu->name,

                'items' => $nodes

            ];
        }

        return $menus;
    }
}