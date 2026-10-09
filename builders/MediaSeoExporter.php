<?php

declare(strict_types=1);

class MediaSeoExporter
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
        $data = (new MediaSeoBuilder())->build($knowledge);
        $this->json->write('media_seo.json', $data);

        return $data;
    }
}
