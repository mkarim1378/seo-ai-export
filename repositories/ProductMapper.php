<?php

declare(strict_types=1);

class ProductMapper
{
    public function map(WC_Product $product): array
    {
        return [

            'basic' => $this->basic($product),

            'content' => $this->content($product),

            'pricing' => $this->pricing($product),

            'inventory' => $this->inventory($product),

            'shipping' => $this->shipping($product),

            'taxonomy' => $this->taxonomy($product),

            'attributes' => $this->attributes($product),

            'media' => $this->media($product),

            'seo' => $this->seo($product),

            'relations' => $this->relations($product),

            'ratings' => $this->ratings($product),

        ];
    }

    private function basic(WC_Product $product): array
    {
        return [

            'id' => $product->get_id(),

            'type' => $product->get_type(),

            'status' => get_post_status($product->get_id()),

            'title' => $product->get_name(),

            'slug' => $product->get_slug(),

            'sku' => $product->get_sku(),

            'url' => get_permalink($product->get_id()),

            'created_at' => $product->get_date_created()
                ? $product->get_date_created()->date('Y-m-d H:i:s')
                : '',

            'updated_at' => $product->get_date_modified()
                ? $product->get_date_modified()->date('Y-m-d H:i:s')
                : ''

        ];
    }

    private function content(WC_Product $product): array
    {
        return [

            'short_description' => ai_clean_text(
                $product->get_short_description()
            ),

            'description' => ai_clean_text(
                $product->get_description()
            ),

        ];
    }

    private function pricing(WC_Product $product): array
    {
        return [

            'price' => $product->get_price(),

            'regular_price' => $product->get_regular_price(),

            'sale_price' => $product->get_sale_price(),

            'on_sale' => $product->is_on_sale(),

        ];
    }

    private function inventory(WC_Product $product): array
    {
        return [

            'stock_status' => $product->get_stock_status(),

            'stock_quantity' => $product->get_stock_quantity(),

            'manage_stock' => $product->get_manage_stock(),

            'backorders' => $product->get_backorders(),

            'sold_individually' => $product->is_sold_individually(),

        ];
    }

    private function shipping(WC_Product $product): array
    {
        return [

            'weight' => $product->get_weight(),

            'length' => $product->get_length(),

            'width' => $product->get_width(),

            'height' => $product->get_height(),

            'shipping_class' => $product->get_shipping_class(),

        ];
    }

    private function taxonomy(WC_Product $product): array
    {
        return [

            'categories' => wp_get_post_terms(
                $product->get_id(),
                'product_cat',
                ['fields'=>'names']
            ),

            'tags' => wp_get_post_terms(
                $product->get_id(),
                'product_tag',
                ['fields'=>'names']
            )

        ];
    }

    private function attributes(WC_Product $product): array
    {
        $result=[];

        foreach($product->get_attributes() as $attribute){

            if($attribute->is_taxonomy()){

                $values=wc_get_product_terms(

                    $product->get_id(),

                    $attribute->get_name(),

                    ['fields'=>'names']

                );

            }else{

                $values=$attribute->get_options();

            }

            $result[]=[

                'name'=>wc_attribute_label(
                    $attribute->get_name()
                ),

                'values'=>$values

            ];

        }

        return $result;
    }

private function media(WC_Product $product): array
{
    $gallery = [];

    foreach ($product->get_gallery_image_ids() as $id) {

        $gallery[] = ai_attachment((int)$id);

    }

    return [

        'featured' => ai_attachment(
            (int)$product->get_image_id()
        ),

        'gallery' => $gallery

    ];
}

    private function seo(WC_Product $product): array
    {
        $id=$product->get_id();

        return [

            'title'=>get_post_meta(
                $id,
                '_yoast_wpseo_title',
                true
            ),

            'description'=>get_post_meta(
                $id,
                '_yoast_wpseo_metadesc',
                true
            ),

            'canonical'=>get_post_meta(
                $id,
                '_yoast_wpseo_canonical',
                true
            ),

            'focus_keyword'=>get_post_meta(
                $id,
                '_yoast_wpseo_focuskw',
                true
            )

        ];
    }

    private function relations(WC_Product $product): array
    {
        return [

            'upsells'=>$product->get_upsell_ids(),

            'cross_sells'=>$product->get_cross_sell_ids(),

        ];
    }

    private function ratings(WC_Product $product): array
    {
        return [

            'average'=>$product->get_average_rating(),

            'review_count'=>$product->get_review_count(),

            'rating_count'=>$product->get_rating_counts()

        ];
    }

}