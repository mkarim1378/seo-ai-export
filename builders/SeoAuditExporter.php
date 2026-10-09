<?php

declare(strict_types=1);

class SeoAuditExporter
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

    public function export(
        array $knowledge,
        array $brain = [],
        array $keywordMap = [],
        array $redirectMap = [],
        array $hreflang = []
    ): array {
        $audit = (new SeoAuditBuilder())->build(
            $knowledge,
            $brain,
            $keywordMap,
            $redirectMap,
            $hreflang
        );

        $this->json->write('seo_audit.json', $audit);

        return $audit;
    }
}
