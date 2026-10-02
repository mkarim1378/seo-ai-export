<?php

declare(strict_types=1);

class SiteBrainExporter
{
    private array $config;
    private JsonWriter $json;
    private InternalLinkGraphExporter $linkGraphExporter;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->json = new JsonWriter(
            rtrim($config['output'], '/') . '/json'
        );

        $this->linkGraphExporter = new InternalLinkGraphExporter($config);
    }

    public function export(array $knowledge): array
    {
        $brain = (new SiteBrainBuilder())->build($knowledge);

        $this->json->write('site_brain.json', $brain);

        $linkGraph = $brain['knowledge_graph']['internal_link_graph'] ?? [];
        $this->linkGraphExporter->export($linkGraph);

        return $brain;
    }
}
