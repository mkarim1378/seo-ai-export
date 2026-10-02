<?php

declare(strict_types=1);

class WooCommerceRelationshipBuilder
{
    public function build(): array
    {
        $relationships = [];

        if (!class_exists('WooCommerce')) {
            return $relationships;
        }

        $products = wc_get_products([

            'limit' => -1,

            'status' => 'publish',

            'return' => 'objects'

        ]);

        foreach ($products as $product) {

            $relationships[] = [

                'id' => $product->get_id(),

                'sku' => $product->get_sku(),

                'name' => $product->get_name(),

                'type' => $product->get_type(),

                'cross_sell_ids' => $product->get_cross_sell_ids(),

                'upsell_ids' => $product->get_upsell_ids(),

                'category_ids' => $product->get_category_ids(),

                'tag_ids' => $product->get_tag_ids(),

                'attributes' => $this->extractAttributes($product),

                'variations' => $this->extractVariations($product)

            ];
        }

        return $relationships;
    }

    private function extractAttributes($product): array
    {
        $result = [];

        foreach ($product->get_attributes() as $attribute) {

            $result[] = [

                'name' => $attribute->get_name(),

                'visible' => $attribute->get_visible(),

                'variation' => $attribute->get_variation(),

                'options' => $attribute->get_options()

            ];
        }

        return $result;
    }

    private function extractVariations($product): array
    {
        if (!$product->is_type('variable')) {
            return [];
        }

        $items = [];

        foreach ($product->get_children() as $variationId) {

            $variation = wc_get_product($variationId);

            if (!$variation) {
                continue;
            }

            $items[] = [

                'id' => $variation->get_id(),

                'sku' => $variation->get_sku(),

                'price' => $variation->get_price(),

                'regular_price' => $variation->get_regular_price(),

                'sale_price' => $variation->get_sale_price(),

                'attributes' => $variation->get_attributes(),

                'stock_status' => $variation->get_stock_status()

            ];
        }

        return $items;
    }
}