<?php

declare(strict_types=1);

class SiteBrainExporter
{
    private array $config;

    private JsonWriter $json;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->json = new JsonWriter(
            rtrim($config['output'], '/') . '/json'
        );
    }

    public function export(array $knowledge): array
    {
        $brain = (new SiteBrainBuilder())->build($knowledge);

        $this->json->write(
            'site_brain.json',
            $brain
        );

        return $brain;
    }
}