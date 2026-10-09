<?php

declare(strict_types=1);

class RedirectMapExporter
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

    public function export(array $knowledge = []): array
    {
        $map = (new RedirectMapBuilder())->build($knowledge);
        $this->json->write('redirect_map.json', $map);

        return $map;
    }
}
