<?php

declare(strict_types=1);

class AiPackExporter
{
    private array $config;
    private JsonWriter $json;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->json = new JsonWriter(
            rtrim($config['output'], '/') . '/ai-pack'
        );
    }

    /**
     * @return array{paths: array<string, string>, manifest: array, file_count: int}
     */
    public function export(
        array $knowledge,
        array $brain,
        array $audit,
        array $aiContext,
        array $keywordMap,
        array $siteProfile,
        array $redirectMap,
        array $hreflang,
        array $sitemapCoverage,
        array $mediaSeo,
        array $contentDuplicates,
        array $auditDiff
    ): array {
        $pack = (new AiPackBuilder())->build(
            $knowledge,
            $brain,
            $audit,
            $aiContext,
            $keywordMap,
            $siteProfile,
            $redirectMap,
            $hreflang,
            $sitemapCoverage,
            $mediaSeo,
            $contentDuplicates,
            $auditDiff,
            isset($this->config['version']) ? (string)$this->config['version'] : null
        );

        foreach ($pack['files'] as $filename => $data) {
            $this->json->write($filename, is_array($data) ? $data : []);
        }

        return [
            'paths' => $pack['paths'],
            'manifest' => $pack['manifest'],
            'file_count' => AiPackBuilder::FILE_COUNT,
        ];
    }
}
