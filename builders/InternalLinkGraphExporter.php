<?php

declare(strict_types=1);

class InternalLinkGraphExporter
{
    public function export(array $graph): string
    {
        $file = AI_OUTPUT_DIR . '/internal_link_graph.json';

        file_put_contents(
            $file,
            json_encode(
                $graph,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );

        return $file;
    }
}