<?php

declare(strict_types=1);

class SitemapCoverageExporter
{
    private array $config;
    private JsonWriter $json;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->json = new JsonWriter(rtrim($config['output'], '/') . '/json');
    }

    public function export(array $knowledge, array $siteProfile = []): array
    {
        $data = (new SitemapCoverageBuilder())->build($knowledge, $siteProfile);
        $this->json->write('sitemap_coverage.json', $data);

        return $data;
    }
}
