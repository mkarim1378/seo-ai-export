<?php

declare(strict_types=1);

class AiContextExporter
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
        array $brain,
        array $audit,
        array $files = [],
        array $keywordMap = [],
        array $auditDiff = [],
        array $redirectMap = [],
        array $hreflang = []
    ): array {
        $context = (new AiContextBuilder())->build(
            $knowledge,
            $brain,
            $audit,
            $files,
            $keywordMap,
            $auditDiff,
            $redirectMap,
            $hreflang
        );

        $this->json->write('ai_context.json', $context);

        return $context;
    }
}
