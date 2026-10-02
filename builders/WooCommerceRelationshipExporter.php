<?php

declare(strict_types=1);

class WooCommerceRelationshipExporter
{
    public function export(array $relationships): string
    {
        $file = AI_OUTPUT_DIR . '/woocommerce_relationships.json';

        file_put_contents(
            $file,
            json_encode(
                $relationships,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );

        return $file;
    }
}