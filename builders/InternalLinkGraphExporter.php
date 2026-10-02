<?php

declare(strict_types=1);

class InternalLinkGraphExporter
{
    private JsonWriter $json;

    public function __construct(array $config)
    {
        $this->json = new JsonWriter(
            rtrim($config['output'], '/') . '/json'
        );
    }

    public function export(array $graph): array
    {
        $this->json->write('internal_link_graph.json', $graph);

        return $graph;
    }
}
