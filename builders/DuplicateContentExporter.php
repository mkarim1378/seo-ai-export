<?php

declare(strict_types=1);

class DuplicateContentExporter
{
    private array $config;
    private JsonWriter $json;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->json = new JsonWriter(rtrim($config['output'], '/') . '/json');
    }

    public function export(array $knowledge): array
    {
        $data = (new DuplicateContentBuilder())->build($knowledge);
        $this->json->write('content_duplicates.json', $data);

        return $data;
    }
}
