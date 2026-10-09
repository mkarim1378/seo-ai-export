<?php

declare(strict_types=1);

class HreflangExporter
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
        $map = (new HreflangBuilder())->build($knowledge);
        $this->json->write('hreflang.json', $map);

        return $map;
    }
}
